<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Container\Fixture;

/** A three-node cycle: A -> B -> C -> A. */
final class CycleA
{
    public function __construct(public readonly CycleB $b)
    {
    }
}
