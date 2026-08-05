<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Container\Fixture;

/** A union type: imported ADR-001 requires refusal rather than picking an arm. */
final class UnionTyped
{
    public function __construct(public readonly NoDependencies|OneDependency $either)
    {
    }
}
