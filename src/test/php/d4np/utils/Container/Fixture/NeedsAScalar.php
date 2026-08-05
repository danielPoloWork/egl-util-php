<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Container\Fixture;

/** A built-in type with no default: the container has nothing to resolve it by. */
final class NeedsAScalar
{
    public function __construct(public readonly string $dsn)
    {
    }
}
