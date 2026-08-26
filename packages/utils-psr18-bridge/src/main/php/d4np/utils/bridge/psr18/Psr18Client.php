<?php

declare(strict_types=1);

namespace D4np\Utils\Bridge\Psr18;

use D4np\Utils\Http\HttpClient;
use D4np\Utils\Http\HttpResponse;
use D4np\Utils\Support\HttpClientException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * `egl/utils`' {@see HttpClient} as a PSR-18 `ClientInterface` (issue #93, ADR-0075).
 *
 * The one sanctioned crossing point between this library's outbound HTTP vocabulary and the
 * ecosystem's. The core keeps no `Psr\Http` dependency and never learns this class exists —
 * deptrac's `Bridge` layer makes a core import a build failure (ADR-0033).
 *
 * **This is not the PSR-7 bridge, and it does not use it.** `egl/utils-psr7-bridge` converts the
 * *server* vocabulary — `Request` and `Response`, what an application receives and emits.
 * `HttpClient` speaks the *client* one, and returns {@see HttpResponse}, a different, read-only
 * type. The two packages share a problem domain and no code; depending on one from the other would
 * have coupled a consumer who wants an HTTP client to a server-side conversion they never asked
 * for. They are installable independently.
 *
 * **Factories are injected, never discovered** — the PSR-7 bridge's rule, unchanged. "Any PSR-17
 * factory" means the consumer's: this class ships no default and falls back to nothing.
 *
 * ## What PSR-18 requires, and where the core already agreed
 *
 * - *"MUST return a response for every status the origin produced."* `HttpClient` already does
 *   exactly that, reserving its exception for requests that produced nothing (ADR-0049). A `404`
 *   comes back as a response through both contracts, with no translation.
 * - *"MUST NOT follow redirects unless configured to."* `HttpClient` defaults `followRedirects` to
 *   `false` (ADR-0049), so the default composition is already PSR-18-shaped. A consumer who
 *   constructs the client with redirects on has opted in, and this class does not second-guess it.
 * - *"MUST throw `ClientExceptionInterface`."* {@see RequestRefused} and {@see TransportFailed}
 *   split that per PSR-18's own distinction — see {@see self::sendRequest()}.
 */
final class Psr18Client implements ClientInterface
{
    /**
     * The schemes this bridge will hand to the core.
     *
     * **Deliberately duplicated** from `HttpClient::ALLOWED_SCHEMES`, which is `private` — this is
     * the same arrangement `QueryBuilder::LIKE_ESCAPE` has with `Sanitizer::LIKE_ESCAPE`, and it is
     * affordable for the same reason: the drift is caught by a test rather than argued about.
     * `Psr18ClientTest` asserts, against the real core, that these two are accepted and that a
     * third is refused *before the transport is touched* — so if the core ever widens or narrows
     * its list, this constant fails rather than silently disagreeing.
     *
     * Why duplicate at all: PSR-18 wants a scheme refusal reported as `RequestExceptionInterface`,
     * and the core reports it as the same `HttpClientException` it uses for a dead socket. The list
     * is how this class tells a malformed request from a failed one *before* making the call,
     * rather than by reading an exception message.
     */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    public function __construct(
        private readonly HttpClient $client,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * Send a PSR-7 request and return the PSR-7 response the origin produced.
     *
     * **The classification is structural, not a message match.** PSR-18 distinguishes a malformed
     * request from a network failure because only the second is worth retrying, and the core
     * raises one exception type for both. So the request-shaped checks all run *before* the call:
     *
     * 1. the URI must carry a host and a scheme this client speaks — PSR-18 names a missing host as
     *    the archetypal malformed request;
     * 2. `HttpClient::contextOptionsFor()` — the core's **own** header validator, which is public
     *    and pure — is invoked on the headers first. It refuses a name or value that could smuggle
     *    a second header (ADR-0025), and doing it here means that refusal is reported as
     *    `RequestExceptionInterface` rather than as a network fault.
     *
     * Only after all of that does the request go out, and from that point anything the core throws
     * is a {@see TransportFailed}: the message was well-formed, so a failure now is the network's.
     *
     * @throws RequestRefused  if the request cannot be sent as written
     * @throws TransportFailed if it was sent and produced no response
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();

        if ($uri->getHost() === '') {
            throw new RequestRefused($request, \sprintf(
                'Cannot send a request whose URI has no host ("%s"). PSR-18 names a missing host as '
                . 'a malformed request, and this client has nowhere to connect to.',
                (string) $uri,
            ));
        }

        $scheme = \strtolower($uri->getScheme());

        if (!\in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new RequestRefused($request, \sprintf(
                'Refusing to send to scheme "%s"; this client speaks %s only.',
                $scheme === '' ? '(none)' : $scheme,
                \implode(' and ', self::ALLOWED_SCHEMES),
            ));
        }

        $headers = self::headersOf($request);
        $body = self::bodyOf($request->getBody());

        // The core's own validator, run before the send so its refusal is classified correctly.
        // Pure by construction (ADR-0026's shape), so calling it costs nothing and sends nothing.
        try {
            $this->client->contextOptionsFor($request->getMethod(), $body, $headers);
        } catch (HttpClientException $e) {
            throw new RequestRefused($request, $e->getMessage(), $e);
        }

        try {
            $response = $this->client->send($request->getMethod(), (string) $uri, $body, $headers);
        } catch (HttpClientException $e) {
            throw new TransportFailed($request, $e->getMessage(), $e);
        }

        return $this->toPsr7($response);
    }

    /**
     * An {@see HttpResponse} as a PSR-7 response.
     *
     * **Multi-valued headers survive**, which is worth naming because the PSR-7 bridge's
     * `responseFromPsr7()` has to *refuse* a message carrying two `Set-Cookie` headers. That
     * refusal is a property of the core's server-side `Response`, whose header map holds one value
     * per name. `HttpResponse` — the inbound, client-side type — keeps `array<string, list<string>>`,
     * so the list passes straight into `withHeader()` and nothing is joined, dropped or corrupted.
     * The same contract, opposite outcomes, because they are different types.
     */
    private function toPsr7(HttpResponse $response): ResponseInterface
    {
        $message = $this->responseFactory
            ->createResponse($response->status)
            ->withBody($this->streamFactory->createStream($response->body));

        foreach ($response->headers() as $name => $values) {
            $message = $message->withHeader($name, $values);
        }

        return $message;
    }

    /**
     * PSR-7's multi-valued headers as the single-valued map the core takes.
     *
     * Comma-joining is correct here and would not be on a response: RFC 7230 §3.2.2 permits it for
     * every header a *request* carries, and `Set-Cookie` — the one header where a join corrupts the
     * value, because cookie strings contain commas of their own — is a response header. The PSR-7
     * bridge refuses that exact case in the other direction; this one does not have to face it.
     *
     * @return array<string, string>
     */
    private static function headersOf(RequestInterface $request): array
    {
        $headers = [];

        foreach (\array_keys($request->getHeaders()) as $name) {
            $name = (string) $name;

            // Host is derived from the URI by the core when it builds the request, and PSR-7
            // guarantees the message carries one matching the URI. Sending it as an explicit
            // header too would put two Host lines in play.
            if (\strtolower($name) === 'host') {
                continue;
            }

            $headers[$name] = $request->getHeaderLine($name);
        }

        return $headers;
    }

    /**
     * A PSR-7 body as the core's `?string`.
     *
     * Rewound first when seekable, for the reason the PSR-7 bridge rewinds too: a body already read
     * by middleware would otherwise be sent empty, which is the failure that looks like "the
     * request lost its payload".
     *
     * An empty body becomes `null` rather than `''`. The core's signature distinguishes them — a
     * `null` body means the request carries none — and PSR-7 gives every request a stream whether
     * or not anything was written to it, so `''` is what a `GET` looks like. Mapping it to `null`
     * is what keeps a `GET` through this bridge identical to a `GET` through `HttpClient::get()`.
     */
    private static function bodyOf(StreamInterface $stream): ?string
    {
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = $stream->getContents();

        return $body === '' ? null : $body;
    }
}
