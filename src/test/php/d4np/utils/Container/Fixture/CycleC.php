<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Container\Fixture;

final class CycleC
{
    public function __construct(public readonly CycleA $a)
    {
    }
}
