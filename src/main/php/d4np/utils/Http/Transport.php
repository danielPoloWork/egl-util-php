<?php

declare(strict_types=1);

namespace D4np\Utils\Http;

use D4np\Utils\Support\HttpClientException;

/**
 * The one place {@see HttpClient} touches the network (spec r3 FR-37, RFC-0002; ADR-0049).
 *
 * A seam for the same reason {@see SessionApi} is one (ADR-0026): the guarantees this client
 * makes — TLS verification set explicitly, a timeout on every request, a bounded total
 * duration — are properties of the **context it builds**, and a unit test cannot observe them
 * by making real requests. With the transport behind an interface, the policy is asserted
 * against the options actually handed over, and suite **T-07** (roadmap item 11.4) exercises
 * the real implementation against a live `php -S` origin.
 *
 * The interface deliberately takes the built context options rather than a client-shaped
 * request object: what needs proving is that *those exact options* reach PHP.
 */
interface Transport
{
    /**
     * @param array{http: array<string, mixed>, ssl: array<string, mixed>} $contextOptions as
     *                                                                    {@see HttpClient} built them
     * @param float                                                       $totalTimeoutSeconds wall-clock ceiling for the whole exchange
     *
     * @throws HttpClientException if no response is produced — refused connection, failed TLS
     *                             verification, expired timeout, unreadable stream
     */
    public function send(string $url, array $contextOptions, float $totalTimeoutSeconds): HttpResponse;
}
