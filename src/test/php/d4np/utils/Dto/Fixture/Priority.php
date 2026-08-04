<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

/** An int-backed enum: proves the branch is not string-only. */
enum Priority: int
{
    case Low = 1;
    case High = 2;
}
