<?php

declare(strict_types=1);

namespace D4np\Utils\Http;

use D4np\Utils\Support\HttpClientException;
use D4np\Utils\Support\Json;
use D4np\Utils\Support\JsonException;

/**
 * What an outbound request came back with (spec r3 FR-37, RFC-0002; ADR-0049).
 *
 * A **result**, not an outcome to be judged here: a `404` is as much a response as a `200`, and
 * whether it is a failure depends on what the caller asked for. {@see HttpClient} therefore
 * returns this object for every status the origin produced and reserves
 * {@see HttpClientException} for requests that produced no response at all.
 *
 * Distinct from {@see Response}, which this library sends *out* as a server. The two share a
 * problem domain and nothing else: this one is read-only and inbound, that one is built and
 * emitted. Merging them would give both audiences the other's methods.
 */
final class HttpResponse
{
    /** @var array<string, list<string>> lowercased name => values, in arrival order */
    private readonly array $headers;

    /**
     * @param list<string> $rawHeaders the origin's header lines, status line already removed
     */
    public function __construct(
        public readonly int $status,
        array $rawHeaders,
        public readonly string $body,
    ) {
        $parsed = [];

        foreach ($rawHeaders as $line) {
            $colon = \strpos($line, ':');
            if ($colon === false) {
                // A continuation or a malformed line. Dropped rather than guessed at: inventing
                // a name for it would put attacker-influenced text under a key a caller reads.
                continue;
            }

            $name = \strtolower(\trim(\substr($line, 0, $colon)));
            $parsed[$name][] = \trim(\substr($line, $colon + 1));
        }

        $this->headers = $parsed;
    }

    /**
     * `true` for 2xx only.
     *
     * 3xx is deliberately **not** successful here: {@see HttpClient} does not follow redirects
     * by default (ADR-0049), so a 3xx reaching a caller means the request has not finished, and
     * calling that a success is how a redirect to a login page gets parsed as data.
     */
    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * The first value of a header, matched case-insensitively, or `null`.
     */
    public function header(string $name): ?string
    {
        return $this->headers[\strtolower($name)][0] ?? null;
    }

    /**
     * Every value of a header, in arrival order — the shape `Set-Cookie` needs.
     *
     * @return list<string>
     */
    public function headerLine(string $name): array
    {
        return $this->headers[\strtolower($name)] ?? [];
    }

    /**
     * @return array<string, list<string>> every header, lowercased names
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * The body decoded as JSON.
     *
     *
     * @throws JsonException if the body is not valid JSON — {@see Json::decode()}'s contract,
     *                       unchanged: the failure is the payload's, not the transport's, so it
     *                       keeps its own exception type rather than being reported as a
     *                       client error
     */
    public function json(): mixed
    {
        return Json::decode($this->body);
    }
}
