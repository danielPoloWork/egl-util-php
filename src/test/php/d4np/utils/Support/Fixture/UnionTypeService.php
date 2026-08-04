<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support\Fixture;

/** A union type — imported ADR-001 has the Container refuse these rather than pick an arm. */
final class UnionTypeService
{
    public function __construct(
        public readonly int|string $ambiguous,
    ) {
    }
}
