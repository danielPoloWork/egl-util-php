<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

use Throwable;

/**
 * Raised when a payload cannot be turned into a typed DTO.
 *
 * Coarse catch for the three concrete hydration failures — an undeclared key
 * ({@see UnknownKeyException}), an absent required key ({@see MissingKeyException}), and a
 * value of the wrong type ({@see TypeMismatchException}).
 *
 * Every hydration failure names the **path** at which it occurred, because a DTO graph nests:
 * "expected int, got string" is not actionable, while `address.postcode` is. The path is
 * dot-separated from the root of the payload being hydrated.
 *
 * The constructor takes `$path` where `RuntimeException` takes `$code`. PHP exempts
 * constructors from signature compatibility, and the trade is deliberate: an integer code
 * carries nothing here — no consumer branches on it — while the path is the one piece of
 * context that makes the failure diagnosable. `getCode()` still exists and returns 0.
 */
class HydrationException extends UtilsException
{
    public function __construct(
        string $message,
        private readonly string $path = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The dot-separated path at which hydration failed, e.g. `address.postcode`.
     *
     * Empty when the failure is not attributable to a single property.
     */
    public function path(): string
    {
        return $this->path;
    }
}
