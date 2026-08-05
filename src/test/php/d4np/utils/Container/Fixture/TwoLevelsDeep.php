<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Container\Fixture;

/** Recursion: the container must build the whole chain, not just the first level. */
final class TwoLevelsDeep
{
    public function __construct(public readonly OneDependency $middle)
    {
    }
}
