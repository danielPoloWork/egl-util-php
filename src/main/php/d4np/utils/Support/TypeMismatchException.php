<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

/**
 * A payload value cannot satisfy the declared type of the property it maps to.
 *
 * The message names the path, the declared type, and what actually arrived — the three facts
 * needed to fix the caller. PHP has no runtime generics, so this is also the only place a
 * `Collection<T>` element type can be enforced at run time when the optional `instanceof`
 * guard is enabled (RFC-0001, spec §2 item 3).
 */
final class TypeMismatchException extends HydrationException
{
    /**
     * @param string $path  dot-separated path of the offending value
     * @param string $expected  the declared type, e.g. `int` or `App\Dto\AddressDto`
     * @param string $actual  the type that arrived, as reported by `get_debug_type()`
     */
    public static function at(string $path, string $expected, string $actual): self
    {
        return new self(
            sprintf('Expected %s at "%s", got %s.', $expected, $path, $actual),
            $path,
        );
    }
}
