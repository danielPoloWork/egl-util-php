<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Container;

use D4np\Utils\Container\Container;
use D4np\Utils\Container\ServiceProvider;
use D4np\Utils\Tests\Container\Fixture\EnglishGreeter;
use D4np\Utils\Tests\Container\Fixture\FrenchGreeter;
use D4np\Utils\Tests\Container\Fixture\FrenchGreeterProvider;
use D4np\Utils\Tests\Container\Fixture\Greeter;
use D4np\Utils\Tests\Container\Fixture\GreeterProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec FR-05's `ServiceProvider`.
 *
 * The contract is one method, so most of what is worth asserting is about what a provider is
 * *not*: no boot phase, no ordering machinery, no lifecycle beyond "apply these definitions".
 */
final class ServiceProviderTest extends TestCase
{
    public function testAProviderRegistersItsDefinitions(): void
    {
        $container = new Container();

        $container->register(new GreeterProvider());

        self::assertInstanceOf(EnglishGreeter::class, $container->get(Greeter::class));
        self::assertSame('!', $container->get('greeting.suffix'));
        self::assertSame('hello!', $container->get('greeting.full'));
    }

    public function testSeveralProvidersRegisterInOneCall(): void
    {
        $container = new Container();

        $container->register(new GreeterProvider(), new FrenchGreeterProvider());

        self::assertInstanceOf(
            FrenchGreeter::class,
            $container->get(Greeter::class),
            'the later provider must win, so registration order is something the caller controls',
        );
    }

    public function testRegisterIsCalledOncePerProvider(): void
    {
        $provider = new GreeterProvider();

        (new Container())->register($provider);

        self::assertSame(1, $provider->registerCalls);
    }

    public function testRegisterReturnsTheContainerForChaining(): void
    {
        $container = new Container();

        self::assertSame($container, $container->register(new GreeterProvider()));
    }

    /**
     * The absence of a `boot()` is a decision, not an omission (imported ADR-001's line on scope).
     * A two-phase lifecycle exists to order side effects across providers, and that is application
     * composition rather than a utility library's job — so the base class stays at exactly one
     * method, and this asserts it has not quietly grown a second.
     */
    public function testTheContractIsExactlyOneMethod(): void
    {
        $declared = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(ServiceProvider::class))->getMethods(),
        );

        self::assertSame(['register'], $declared);
    }
}
