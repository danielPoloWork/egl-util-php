<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

/**
 * A request could not be read, or a response could not be produced, as asked.
 *
 * Raised by the HTTP group — a superglobal that does not hold the shape a typed reader was
 * asked for, a header set after output has begun, a session operation on a session that was
 * never started. CSRF rejection lives here too: a token that fails constant-time comparison,
 * or that belongs to another session, is an HTTP-layer refusal (RFC-0001, spec §2 item 12).
 *
 * **An extension point, not a leaf** (ADR-0049, amending ADR-0004's default). The group grew a
 * failure that callers need to distinguish — {@see HttpClientException}, raised when an
 * *outbound* request never produces a response — and the two are not interchangeable: an
 * inbound-shape refusal is a bug in the caller's code, while a transport failure is the
 * network. Both stay catchable as `HttpException`, which is what the second audience wants,
 * and the same shape {@see HydrationException} already had.
 */
class HttpException extends UtilsException
{
}
