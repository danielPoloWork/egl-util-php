<?php

declare(strict_types=1);

namespace D4np\Utils\Bridge\Psr18;

use D4np\Utils\Support\HttpException;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * The request was sent and produced no response — PSR-18's `NetworkExceptionInterface`.
 *
 * The half of PSR-18's split that callers act on: a refused connection, a failed TLS handshake, an
 * expired timeout. This is what a retry middleware keys on, which is exactly why it must not be
 * conflated with {@see RequestRefused} — retrying a malformed request produces the same refusal
 * every time, at whatever the backoff costs.
 *
 * **A 4xx or 5xx is not this.** The core's `HttpClient` returns an `HttpResponse` for every status
 * an origin produced and reserves its exception for requests that produced nothing at all
 * (ADR-0049), which is precisely PSR-18's own rule: `sendRequest()` returns the response and lets
 * the caller judge it. The two contracts agree here without translation.
 *
 * Extends the core's `HttpException` *and* implements PSR-18's interface for the reason
 * {@see RequestRefused} sets out: two audiences, two catch-hierarchies, both satisfied.
 */
final class TransportFailed extends HttpException implements NetworkExceptionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
