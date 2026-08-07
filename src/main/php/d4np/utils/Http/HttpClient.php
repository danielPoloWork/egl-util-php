<?php

declare(strict_types=1);

namespace D4np\Utils\Http;

use D4np\Utils\Support\HttpClientException;
use D4np\Utils\Support\Json;
use D4np\Utils\Support\Url;

/**
 * An outbound HTTP client over PHP's stream wrapper (spec r3 FR-37, RFC-0002; ADR-0049).
 *
 * The surveyed estate reached remote services through a helper that rebuilt every address as
 * `"http://{$host}{$path}"` and read it with no timeout at all — a forced plaintext downgrade
 * and an unbounded hang, in the same three lines. Both are refused here by construction:
 * addresses arrive as {@see Url} (ADR-0036, which will not silently drop a scheme), only
 * `http` and `https` are accepted, and every request carries a per-phase timeout *and* a total
 * duration ceiling.
 *
 * **TLS options are set explicitly, and that is the security decision.** Probed: a freshly
 * created stream context carries **no `ssl` options at all**, so verification is whatever the
 * process default happens to be — and `stream_context_set_default(['ssl' => ['verify_peer' =>
 * false]])`, which any bootstrap file in a host application may have run, silently becomes
 * this client's policy. An explicit setting in our own context was measured to win over that
 * default, so the policy is written out on every request rather than inherited.
 *
 * **Not PSR-18**, deliberately: that interface is defined in terms of PSR-7 messages, and this
 * library's HTTP stance is native wrappers plus an optional bridge (RFC-0001 Alternative #3).
 * A PSR-18 adapter over this class remains something a consumer can write; requiring PSR-7 of
 * everyone who wants a timeout does not.
 *
 * **A response is a result, not an error.** Any status the origin produced comes back as an
 * {@see HttpResponse}; {@see HttpClientException} is reserved for a request that produced no
 * response. Only the caller knows whether a `404` is a failure.
 */
final class HttpClient
{
    public const DEFAULT_TIMEOUT_SECONDS = 10.0;

    public const DEFAULT_TOTAL_TIMEOUT_SECONDS = 30.0;

    /** Schemes this client will send to. Anything else is refused before a socket is opened. */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    private readonly Transport $transport;

    /**
     * @param float                 $timeoutSeconds      per-phase socket timeout — measured, PHP applies
     *                                                   this to the connect *and* to each read, re-arming
     *                                                   every time bytes arrive
     * @param float                 $totalTimeoutSeconds wall-clock ceiling for the whole exchange; this is
     *                                                   what an origin dripping bytes cannot outlast
     * @param array<string, string> $defaultHeaders      sent with every request, overridable per call
     * @param bool                  $followRedirects     off by default — see {@see self::contextOptionsFor()}
     */
    public function __construct(
        private readonly float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        private readonly float $totalTimeoutSeconds = self::DEFAULT_TOTAL_TIMEOUT_SECONDS,
        private readonly array $defaultHeaders = [],
        private readonly bool $followRedirects = false,
        private readonly int $maxRedirects = 5,
        ?Transport $transport = null,
    ) {
        if ($timeoutSeconds <= 0.0 || $totalTimeoutSeconds <= 0.0) {
            throw new HttpClientException('Timeouts must be positive; a client with no time limit is the defect this class exists to remove.');
        }

        if ($totalTimeoutSeconds < $timeoutSeconds) {
            throw new HttpClientException(\sprintf(
                'The total time budget (%.3Fs) is below the per-phase timeout (%.3Fs), so the per-phase value could never be reached.',
                $totalTimeoutSeconds,
                $timeoutSeconds,
            ));
        }

        $this->transport = $transport ?? new StreamTransport();
    }

    /**
     * @param array<string, string> $headers
     *
     * @throws HttpClientException
     */
    public function get(Url|string $url, array $headers = []): HttpResponse
    {
        return $this->send('GET', $url, null, $headers);
    }

    /**
     * @param array<string, string> $headers
     *
     * @throws HttpClientException
     */
    public function post(Url|string $url, string $body, array $headers = []): HttpResponse
    {
        return $this->send('POST', $url, $body, $headers);
    }

    /**
     * POST a JSON body, with the header that says so.
     *
     * @param array<string, string> $headers
     *
     * @throws HttpClientException
     * @throws \D4np\Utils\Support\JsonException if the payload cannot be encoded
     */
    public function postJson(Url|string $url, mixed $payload, array $headers = []): HttpResponse
    {
        return $this->send(
            'POST',
            $url,
            Json::encode($payload),
            ['Content-Type' => 'application/json'] + $headers,
        );
    }

    /**
     * @param array<string, string> $headers
     *
     * @throws HttpClientException
     */
    public function send(string $method, Url|string $url, ?string $body = null, array $headers = []): HttpResponse
    {
        $url = $url instanceof Url ? $url : Url::parse($url);
        $this->guardScheme($url);

        return $this->transport->send(
            $url->toString(),
            $this->contextOptionsFor($method, $body, $headers),
            $this->totalTimeoutSeconds,
        );
    }

    /**
     * The exact options a request would be sent with — a **pure value**, so the security policy
     * can be asserted without a network (ADR-0026's shape, and the reason {@see Transport} is a
     * seam).
     *
     * Every entry is written out rather than left to a default:
     *
     * - `ssl.verify_peer` / `verify_peer_name` — because a fresh context has *no* ssl options
     *   and would otherwise inherit whatever the process default holds;
     * - `ssl.allow_self_signed` — `false` explicitly; it is meaningless while `verify_peer` is
     *   on, and it is the flag someone reaches for first when a certificate is inconvenient;
     * - `http.ignore_errors` — `true`, so a 4xx/5xx *body* reaches the caller instead of the
     *   wrapper returning `false` and losing the response;
     * - `http.follow_location` — `0` by default. A redirect the client follows silently is how
     *   a request to an allow-listed host ends up somewhere else, and how a POST body is
     *   replayed against an origin the caller never named;
     * - `http.protocol_version` — `1.1`, since the wrapper still defaults to `1.0`.
     *
     * @param array<string, string> $headers
     *
     * @return array{http: array<string, mixed>, ssl: array<string, mixed>}
     *
     * @throws HttpClientException if a header name or value could smuggle a second header
     */
    public function contextOptionsFor(string $method, ?string $body = null, array $headers = []): array
    {
        $merged = $this->defaultHeaders;

        foreach ($headers as $name => $value) {
            $merged[$name] = $value;
        }

        $lines = [];

        foreach ($merged as $name => $value) {
            $this->guardHeader($name, $value);
            $lines[] = $name . ': ' . $value;
        }

        $http = [
            'method' => \strtoupper($method),
            'header' => $lines,
            'timeout' => $this->timeoutSeconds,
            'ignore_errors' => true,
            'follow_location' => $this->followRedirects ? 1 : 0,
            'max_redirects' => $this->followRedirects ? $this->maxRedirects : 0,
            'protocol_version' => 1.1,
        ];

        if ($body !== null) {
            $http['content'] = $body;
        }

        return [
            'http' => $http,
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
                'disable_compression' => true,
            ],
        ];
    }

    /**
     * @throws HttpClientException
     */
    private function guardScheme(Url $url): void
    {
        if (!\in_array($url->scheme(), self::ALLOWED_SCHEMES, true)) {
            throw new HttpClientException(\sprintf(
                'Refusing to send to scheme "%s"; this client speaks %s only.',
                $url->scheme(),
                \implode(' and ', self::ALLOWED_SCHEMES),
            ));
        }
    }

    /**
     * Header injection, refused at build time rather than at send time.
     *
     * The same stance {@see Response} takes on the way out (ADR-0025): a `\r` or `\n` in a
     * value ends the header and starts another one, so a caller interpolating user input into
     * a header would otherwise be able to add any header — or a body — of its choosing.
     *
     * @throws HttpClientException
     */
    private function guardHeader(string $name, string $value): void
    {
        if (\preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1) {
            throw new HttpClientException(\sprintf('"%s" is not a valid header name.', $name));
        }

        if (\preg_match('/[\r\n\x00]/', $value) === 1) {
            throw new HttpClientException(\sprintf(
                'Header "%s" carries a carriage return, newline or NUL, which would let it smuggle a second header.',
                $name,
            ));
        }
    }
}
