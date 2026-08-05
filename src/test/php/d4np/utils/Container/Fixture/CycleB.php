<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Container\Fixture;

final class CycleB
{
    public function __construct(public readonly CycleC $c)
    {
    }
}
