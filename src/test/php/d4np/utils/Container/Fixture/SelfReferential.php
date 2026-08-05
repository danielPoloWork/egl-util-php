<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Container\Fixture;

/** The degenerate cycle, which a naive stack check can miss. */
final class SelfReferential
{
    public function __construct(public readonly SelfReferential $itself)
    {
    }
}
