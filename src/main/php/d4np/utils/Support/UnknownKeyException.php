<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

/**
 * The payload carries a key the target DTO does not declare.
 *
 * Raised by strict hydration, which is the default: silently dropping an unrecognised key is
 * how a typo in a payload becomes a field that was never assigned, and how a mass-assignment
 * attempt becomes invisible. Consumers that genuinely receive wider payloads than they map opt
 * out per call with `lenient()`, which skips unknown keys instead (RFC-0001, spec §2 item 1).
 */
final class UnknownKeyException extends HydrationException
{
    /**
     * @param string $path  dot-separated path of the offending key
     * @param class-string $target  the DTO class being hydrated
     */
    public static function forKey(string $path, string $target): self
    {
        return new self(
            sprintf(
                'Unknown key "%s" for %s. Strict hydration rejects keys the target does not '
                . 'declare; use lenient() to ignore them.',
                $path,
                $target,
            ),
            $path,
        );
    }
}
