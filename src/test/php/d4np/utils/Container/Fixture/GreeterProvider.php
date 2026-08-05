<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Container\Fixture;

use D4np\Utils\Container\Container;
use D4np\Utils\Container\ServiceProvider;

/** A provider that registers a binding, a singleton and a plain value. */
final class GreeterProvider extends ServiceProvider
{
    public int $registerCalls = 0;

    public function register(Container $container): void
    {
        $this->registerCalls++;

        $container
            ->bind(Greeter::class, EnglishGreeter::class)
            ->instance('greeting.suffix', '!')
            ->singleton('greeting.full', static function (Container $c): string {
                // `get(Greeter::class)` infers `Greeter`; a string-keyed entry stays `mixed` and is
                // narrowed. That asymmetry is `Container::get()`'s conditional return type showing
                // through, and it is the behaviour a consumer will see.
                $suffix = $c->get('greeting.suffix');

                return $c->get(Greeter::class)->greet() . (is_string($suffix) ? $suffix : '');
            });
    }
}
