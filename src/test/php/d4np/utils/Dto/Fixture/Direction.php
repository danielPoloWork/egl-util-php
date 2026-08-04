<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

/**
 * A pure (non-backed) enum: no scalar to key from, so it stays instance-only. Distinguishing
 * this from Status is the point of checking BackedEnum specifically rather than UnitEnum.
 */
enum Direction
{
    case Up;
    case Down;
}
