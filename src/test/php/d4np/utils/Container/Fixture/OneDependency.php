<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Container\Fixture;

/** One level of autowiring. */
final class OneDependency
{
    public function __construct(public readonly NoDependencies $leaf)
    {
    }
}
