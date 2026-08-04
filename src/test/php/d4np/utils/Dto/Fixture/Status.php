<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

/** A string-backed enum: the ordinary case, hydrated from its backing value. */
enum Status: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
