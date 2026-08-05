<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Container\Fixture;

/** Not instantiable, despite being a concrete class. */
final class PrivateConstructor
{
    private function __construct()
    {
    }
}
