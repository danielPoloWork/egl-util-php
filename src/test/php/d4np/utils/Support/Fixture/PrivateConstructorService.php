<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support\Fixture;

/** A private constructor: this library's static-utility idiom, which the Container must refuse. */
final class PrivateConstructorService
{
    private function __construct()
    {
    }
}
