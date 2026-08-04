<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support\Fixture;

/**
 * An intersection type: reflectable, equally un-autowirable.
 *
 * `ArrayAccess` is generic, so PHPStan at max level requires its type arguments — supplied in
 * the docblock, since PHP's native intersection syntax cannot carry them.
 */
final class IntersectionTypeService
{
    /** @param \Countable&\ArrayAccess<int, string> $both */
    public function __construct(
        public readonly \Countable&\ArrayAccess $both,
    ) {
    }
}
