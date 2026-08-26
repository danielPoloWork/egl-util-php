<?php

declare(strict_types=1);

namespace D4np\Utils\Bridge\Psr18\Tests;

use D4np\Utils\Http\HttpResponse;
use D4np\Utils\Http\Transport;
use D4np\Utils\Support\HttpClientException;

/**
 * The core's `Transport` seam, standing in for the network.
 *
 * The seam exists because `HttpClient`'s guarantees are properties of the context it builds, and a
 * unit test cannot observe them by making real requests (ADR-0049). This suite uses it for the same
 * reason plus one more: the whole point of {@see \D4np\Utils\Bridge\Psr18\Psr18Client} is *which*
 * exception it raises and *what* it hands the core, and both are visible here without a socket.
 *
 * `$url`, `$options` and `$totalTimeoutSeconds` are recorded because "the request went out
 * unmodified" is PSR-18's own requirement and cannot be asserted from the response alone.
 */
final class RecordingTransport implements Transport
{
    public ?string $url = null;

    /** @var array{http: array<string, mixed>, ssl: array<string, mixed>}|null */
    public ?array $options = null;

    public ?float $totalTimeoutSeconds = null;

    public int $calls = 0;

    public function __construct(
        private readonly ?HttpResponse $response = null,
        private readonly ?string $failWith = null,
    ) {
    }

    /**
     * @param array{http: array<string, mixed>, ssl: array<string, mixed>} $contextOptions
     */
    public function send(string $url, array $contextOptions, float $totalTimeoutSeconds): HttpResponse
    {
        ++$this->calls;
        $this->url = $url;
        $this->options = $contextOptions;
        $this->totalTimeoutSeconds = $totalTimeoutSeconds;

        if ($this->failWith !== null) {
            throw new HttpClientException($this->failWith);
        }

        return $this->response ?? new HttpResponse(200, [], '');
    }

    /**
     * The request headers the core actually built, as a `name => value` map.
     *
     * `contextOptionsFor()` renders them as a **list of `Name: value` lines** — PHP's stream
     * wrapper accepts either that or one joined string, and the core chose the list. Written
     * against what it actually produces rather than what the wrapper's documentation leads with.
     *
     * @return array<string, string>
     */
    public function sentHeaders(): array
    {
        $lines = $this->options['http']['header'] ?? [];

        if (!\is_array($lines)) {
            return [];
        }

        $headers = [];

        foreach ($lines as $line) {
            if (!\is_string($line)) {
                continue;
            }

            $colon = \strpos($line, ':');

            if ($colon !== false) {
                $headers[\strtolower(\trim(\substr($line, 0, $colon)))] = \trim(\substr($line, $colon + 1));
            }
        }

        return $headers;
    }
}
