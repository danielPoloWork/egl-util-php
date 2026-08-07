<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

/**
 * The payload omits a property the target DTO declares as required.
 *
 * "Required" means neither nullable nor carrying a default: a nullable or defaulted property
 * absent from the payload hydrates to `null` or its default and raises nothing.
 *
 * Raised in **both** strict and lenient modes. Lenient hydration relaxes what may arrive, not
 * what must (RFC-0001 R-4) — a DTO missing a required field is malformed under either policy,
 * and the imported specification was silent on this case until review added it.
 */
final class MissingKeyException extends HydrationException
{
    /**
     * @param string $path  dot-separated path of the absent property
     * @param class-string $target  the DTO class being hydrated
     */
    public static function forKey(string $path, string $target): self
    {
        return new self(
            \sprintf(
                'Missing required key "%s" for %s. The property is neither nullable nor '
                . 'defaulted, so it cannot be omitted — in strict or lenient mode.',
                $path,
                $target,
            ),
            $path,
        );
    }
}
