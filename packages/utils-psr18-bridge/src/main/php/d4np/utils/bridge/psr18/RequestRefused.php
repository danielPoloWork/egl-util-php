<?php

declare(strict_types=1);

namespace D4np\Utils\Bridge\Psr18;

use D4np\Utils\Support\HttpException;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * The request was refused before anything was sent — PSR-18's `RequestExceptionInterface`.
 *
 * PSR-18 draws one line that matters to callers: a request that is *malformed* is not the same
 * failure as a network that did not answer, because only the second is worth retrying. This is the
 * first half. {@see TransportFailed} is the second.
 *
 * **It extends the core's `HttpException` and implements PSR-18's interface, deliberately both.**
 * ADR-0004 roots every exception this library throws on `UtilsThrowable` so a consumer has one
 * thing to catch; PSR-18 requires `ClientExceptionInterface` so ecosystem middleware has one thing
 * to catch. Those are different audiences, and satisfying only one would make this class wrong for
 * the other — a consumer writing `catch (UtilsThrowable)` around their whole application would
 * otherwise miss it, and a PSR-18 retry middleware would not recognise it at all. Nothing about
 * either hierarchy is bent to do it: `HttpException` is not `final`, and PSR-18's contracts are
 * interfaces.
 */
final class RequestRefused extends HttpException implements RequestExceptionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * PSR-18 requires the offending request back, so a caller can report or repair it.
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
