<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Container\Fixture;

final class UnionTypedWithDefault
{
    public function __construct(public readonly NoDependencies|string $either = 'fallback')
    {
    }
}
