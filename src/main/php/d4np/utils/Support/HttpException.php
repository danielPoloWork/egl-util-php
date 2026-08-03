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
 */
final class HttpException extends UtilsException
{
}
