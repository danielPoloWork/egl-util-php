<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

/**
 * An outbound HTTP request could not be completed (spec r3 FR-37, RFC-0002; ADR-0049).
 *
 * **Transport failures only.** A response that arrives is a result, not an error, however
 * unwelcome its status: `404` and `500` are returned to the caller as
 * {@see \D4np\Utils\Http\HttpResponse} objects, because only the caller knows whether a `404`
 * is a bug or the expected answer. What raises this exception is the request never producing
 * a response at all — the name does not resolve, the connection is refused, TLS verification
 * fails, or a timeout expires.
 *
 * It extends {@see HttpException} rather than {@see UtilsException} directly so that a
 * consumer catching the HTTP group's failures catches this one too; that required unsealing
 * `HttpException` into an extension point, which ADR-0049 records against ADR-0004's
 * hierarchy contract.
 */
final class HttpClientException extends HttpException
{
}
