<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support\Fixture;

/**
 * A genuinely untyped parameter — `getType()` returns `null`.
 *
 * Distinct from {@see MixedTypeService}: `mixed` is a *named builtin type*, verified directly
 * against reflection, not the absence of one. Both are un-autowirable, for different reasons a
 * diagnostic message should not conflate.
 *
 * Cannot use constructor promotion: a promoted property requires a type.
 */
final class UntypedService
{
    public readonly mixed $anything;

    /** @param mixed $anything */
    public function __construct($anything)
    {
        $this->anything = $anything;
    }
}
