<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Container\Fixture;

/** The same, with the author's own answer for the absent case. */
final class ScalarWithDefault
{
    public function __construct(public readonly string $dsn = 'sqlite::memory:')
    {
    }
}
