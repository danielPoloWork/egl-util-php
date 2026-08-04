<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support\Fixture;

/** Abstract: reflectable, not instantiable. */
abstract class AbstractService
{
    public function __construct(public readonly int $value)
    {
    }
}
