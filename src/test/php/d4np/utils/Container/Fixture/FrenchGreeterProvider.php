<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Container\Fixture;

use D4np\Utils\Container\Container;
use D4np\Utils\Container\ServiceProvider;

/** A second provider binding the same abstract, so registration order is observable. */
final class FrenchGreeterProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->bind(Greeter::class, FrenchGreeter::class);
    }
}
