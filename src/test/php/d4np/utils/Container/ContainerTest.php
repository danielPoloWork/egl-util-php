<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Container;

use D4np\Utils\Container\CircularDependencyException;
use D4np\Utils\Container\Container;
use D4np\Utils\Container\ContainerException;
use D4np\Utils\Container\NotFoundException;
use D4np\Utils\Support\ReflectionCache;
use D4np\Utils\Support\UtilsThrowable;
use D4np\Utils\Tests\Container\Fixture\AbstractThing;
use D4np\Utils\Tests\Container\Fixture\CycleA;
use D4np\Utils\Tests\Container\Fixture\EnglishGreeter;
use D4np\Utils\Tests\Container\Fixture\FrenchGreeter;
use D4np\Utils\Tests\Container\Fixture\Greeter;
use D4np\Utils\Tests\Container\Fixture\NeedsAGreeter;
use D4np\Utils\Tests\Container\Fixture\NeedsAScalar;
use D4np\Utils\Tests\Container\Fixture\NoDependencies;
use D4np\Utils\Tests\Container\Fixture\OneDependency;
use D4np\Utils\Tests\Container\Fixture\OptionalMissingImplementation;
use D4np\Utils\Tests\Container\Fixture\PrivateConstructor;
use D4np\Utils\Tests\Container\Fixture\ScalarWithDefault;
use D4np\Utils\Tests\Container\Fixture\SelfReferential;
use D4np\Utils\Tests\Container\Fixture\TwoLevelsDeep;
use D4np\Utils\Tests\Container\Fixture\UnionTyped;
use D4np\Utils\Tests\Container\Fixture\UnionTypedWithDefault;
use D4np\Utils\Tests\Container\Fixture\UntypedParameter;
use D4np\Utils\Tests\Container\Fixture\Variadic;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Spec FR-04's minimal PSR-11 container.
 *
 * The interesting half of this file is the refusals. Imported ADR-001 bought a hand-written
 * container by promising it would **fail loudly** exactly where a mature one would add a feature —
 * so the tests that matter most are the ones asserting it declines to guess, and says why.
 */
final class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    // ---- PSR-11 conformance ----------------------------------------------------------------------

    public function testItIsAPsr11Container(): void
    {
        self::assertInstanceOf(ContainerInterface::class, $this->container);
    }

    /**
     * Both exception hierarchies at once. A consumer catching PSR-11's interface and one catching
     * this library's root (ADR-0004) must each get what they expect, without either being told
     * about the other.
     */
    public function testExceptionsSatisfyBothPsr11AndTheLibraryRoot(): void
    {
        try {
            $this->container->get('no\such\entry');
            self::fail('expected a not-found failure');
        } catch (NotFoundException $e) {
            self::assertInstanceOf(NotFoundExceptionInterface::class, $e);
            self::assertInstanceOf(ContainerExceptionInterface::class, $e);
            self::assertInstanceOf(UtilsThrowable::class, $e);
        }
    }

    public function testNotFoundIsDistinctFromAFailureToBuild(): void
    {
        self::assertInstanceOf(ContainerException::class, new NotFoundException('x'));

        // "absent" and "present but unbuildable" must not collapse into one answer.
        try {
            $this->container->get(AbstractThing::class);
            self::fail('expected a build failure');
        } catch (ContainerException $e) {
            self::assertNotInstanceOf(NotFoundExceptionInterface::class, $e);
        }
    }

    // ---- definitions -----------------------------------------------------------------------------

    public function testAnInstanceIsReturnedAsIs(): void
    {
        $value = new NoDependencies();
        $this->container->instance('leaf', $value);

        self::assertSame($value, $this->container->get('leaf'));
    }

    /**
     * A container is also where configuration lands, so entries are not restricted to objects.
     */
    public function testEntriesNeedNotBeObjects(): void
    {
        $this->container->instance('dsn', 'sqlite::memory:')->instance('retries', 3);

        self::assertSame('sqlite::memory:', $this->container->get('dsn'));
        self::assertSame(3, $this->container->get('retries'));
    }

    /**
     * `null` is a legitimate stored value, and the `isset()` fast path in `get()` is blind to it —
     * so this asserts the slow path catches what the fast one cannot see.
     */
    public function testANullEntryIsStoredRatherThanTreatedAsAbsent(): void
    {
        $this->container->instance('maybe', null);

        self::assertTrue($this->container->has('maybe'));
        self::assertNull($this->container->get('maybe'));
    }

    public function testASingletonFactoryRunsOnce(): void
    {
        $calls = 0;
        $this->container->singleton('thing', function () use (&$calls): NoDependencies {
            $calls++;

            return new NoDependencies();
        });

        $first = $this->container->get('thing');

        self::assertSame($first, $this->container->get('thing'));
        self::assertSame(1, $calls);
    }

    /**
     * `singleton()` with no factory marks a class to be autowired and shared — already the default,
     * and accepted so a `ServiceProvider` can say so explicitly rather than relying on the reader
     * knowing the default. Asserted because "documented API path with no test" is how a default
     * quietly changes.
     */
    public function testSingletonWithoutAFactoryMarksAClassSharedAndAutowired(): void
    {
        $this->container->singleton(OneDependency::class);

        $first = $this->container->get(OneDependency::class);

        self::assertInstanceOf(OneDependency::class, $first);
        self::assertSame($first, $this->container->get(OneDependency::class));
    }

    /**
     * Re-registering must not leave the previous decision half-applied: a factory promoted to a
     * singleton starts sharing, and a singleton demoted to a factory stops.
     */
    public function testRedefiningAnEntrySwitchesItsSharingBehaviour(): void
    {
        $make = static fn (): NoDependencies => new NoDependencies();

        $this->container->factory('thing', $make);
        self::assertNotSame($this->container->get('thing'), $this->container->get('thing'));

        $this->container->singleton('thing', $make);
        self::assertSame($this->container->get('thing'), $this->container->get('thing'));

        $this->container->factory('thing', $make);
        self::assertNotSame($this->container->get('thing'), $this->container->get('thing'));
    }

    public function testAFactoryRunsEveryTime(): void
    {
        $calls = 0;
        $this->container->factory('thing', function () use (&$calls): NoDependencies {
            $calls++;

            return new NoDependencies();
        });

        self::assertNotSame($this->container->get('thing'), $this->container->get('thing'));
        self::assertSame(2, $calls);
    }

    public function testAFactoryReceivesTheContainer(): void
    {
        $this->container->instance('name', 'world');

        // A string-keyed entry really is `mixed` — the key says nothing about the value — so a
        // consumer narrows. Only class-keyed lookups carry a type (see `Container::get()`).
        $this->container->factory('greeting', static function (Container $c): string {
            $name = $c->get('name');

            return 'hello ' . (is_string($name) ? $name : '');
        });

        self::assertSame('hello world', $this->container->get('greeting'));
    }

    // ---- autowiring ------------------------------------------------------------------------------

    public function testAClassWithNoDependenciesIsBuilt(): void
    {
        self::assertInstanceOf(NoDependencies::class, $this->container->get(NoDependencies::class));
    }

    public function testDependenciesAreResolvedRecursively(): void
    {
        $deep = $this->container->get(TwoLevelsDeep::class);

        self::assertInstanceOf(TwoLevelsDeep::class, $deep);
        self::assertInstanceOf(OneDependency::class, $deep->middle);
        self::assertInstanceOf(NoDependencies::class, $deep->middle->leaf);
    }

    /**
     * Autowired results are shared. NFR-02's "warm" resolve only means anything if a second `get()`
     * is a lookup rather than a rebuild — and a container that quietly rebuilt the graph each time
     * would turn one shared connection into hundreds.
     */
    public function testAutowiredInstancesAreSharedByDefault(): void
    {
        self::assertSame(
            $this->container->get(NoDependencies::class),
            $this->container->get(NoDependencies::class),
        );
    }

    public function testASharedDependencyIsTheSameObjectEverywhere(): void
    {
        $leaf = $this->container->get(NoDependencies::class);
        $parent = $this->container->get(OneDependency::class);

        self::assertSame($leaf, $parent->leaf);
    }

    public function testAVariadicTailIsLeftEmpty(): void
    {
        self::assertSame([], $this->container->get(Variadic::class)->items);
    }

    // ---- bind() ----------------------------------------------------------------------------------

    public function testAnInterfaceResolvesThroughItsBinding(): void
    {
        $this->container->bind(Greeter::class, EnglishGreeter::class);

        self::assertInstanceOf(EnglishGreeter::class, $this->container->get(Greeter::class));
        self::assertSame('hello', $this->container->get(NeedsAGreeter::class)->greeter->greet());
    }

    public function testRebindingReplacesAnAlreadyResolvedBinding(): void
    {
        $this->container->bind(Greeter::class, EnglishGreeter::class);
        self::assertSame('hello', $this->container->get(Greeter::class)->greet());

        $this->container->bind(Greeter::class, FrenchGreeter::class);
        self::assertSame('bonjour', $this->container->get(Greeter::class)->greet());
    }

    // ---- the refusals, which are the point -------------------------------------------------------

    public function testAnUnboundInterfaceIsRefusedRatherThanGuessed(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/cannot be instantiated.*bind\(\)/s');

        $this->container->get(Greeter::class);
    }

    public function testAnAbstractClassIsRefused(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/cannot be instantiated/');

        $this->container->get(AbstractThing::class);
    }

    public function testANonPublicConstructorIsRefused(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/cannot be instantiated/');

        $this->container->get(PrivateConstructor::class);
    }

    public function testABuiltinTypedParameterWithNoDefaultIsRefused(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/built-in type with no default/');

        $this->container->get(NeedsAScalar::class);
    }

    public function testAnUntypedParameterIsRefused(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/no type declaration/');

        $this->container->get(UntypedParameter::class);
    }

    /**
     * Imported ADR-001 names union-typed parameters as a place this container fails loudly. The
     * message must name the declaration, which is why ADR-0006 preserved it verbatim.
     */
    public function testAUnionTypedParameterIsRefusedAndTheDeclarationIsNamed(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/union or intersection/');

        $this->container->get(UnionTyped::class);
    }

    public function testARefusalMessageNamesTheParameterAndTheClass(): void
    {
        try {
            $this->container->get(NeedsAScalar::class);
            self::fail('expected a refusal');
        } catch (ContainerException $e) {
            self::assertStringContainsString('$dsn', $e->getMessage());
            self::assertStringContainsString(NeedsAScalar::class, $e->getMessage());
        }
    }

    // ---- defaults are the author's own answer ----------------------------------------------------

    public function testADefaultIsUsedWhenTheParameterCannotBeResolved(): void
    {
        self::assertSame('sqlite::memory:', $this->container->get(ScalarWithDefault::class)->dsn);
        self::assertSame('fallback', $this->container->get(UnionTypedWithDefault::class)->either);
        self::assertNull($this->container->get(OptionalMissingImplementation::class)->greeter);
    }

    /**
     * But a default must not paper over a *cycle*. That is a structural error, and falling back
     * would hand the caller a half-built graph while reporting success — which is why the container
     * distinguishes the two by exception type rather than by matching on message text.
     */
    public function testADefaultDoesNotSwallowACircularDependency(): void
    {
        $this->expectException(CircularDependencyException::class);

        $this->container->get(CycleA::class);
    }

    // ---- circular dependencies -------------------------------------------------------------------

    /**
     * ADR-001 requires the *path*, not just the fact. "Circular dependency detected" alone leaves
     * the reader to find the cycle by hand in a graph they cannot see.
     */
    public function testACycleIsReportedWithItsFullPath(): void
    {
        try {
            $this->container->get(CycleA::class);
            self::fail('expected a circular dependency failure');
        } catch (CircularDependencyException $e) {
            $message = $e->getMessage();

            self::assertStringContainsString('CycleA', $message);
            self::assertStringContainsString('CycleB', $message);
            self::assertStringContainsString('CycleC', $message);
            self::assertStringContainsString('->', $message, 'the path must show the order');
        }
    }

    public function testASelfReferentialClassIsACycleToo(): void
    {
        $this->expectException(CircularDependencyException::class);

        $this->container->get(SelfReferential::class);
    }

    /**
     * A failed resolution must leave nothing behind. Without the `finally` that unwinds the
     * in-progress stack, the second attempt would report a cycle that is not there.
     */
    public function testTheResolutionStackUnwindsAfterAFailure(): void
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $this->container->get(NeedsAScalar::class);
                self::fail('expected a refusal');
            } catch (ContainerException $e) {
                self::assertStringNotContainsString(
                    'Circular',
                    $e->getMessage(),
                    "attempt {$attempt} reported a cycle, so the previous failure leaked state",
                );
            }
        }
    }

    // ---- has() -----------------------------------------------------------------------------------

    public function testHasIsTrueForRegisteredEntries(): void
    {
        $this->container->instance('leaf', new NoDependencies());
        $this->container->factory('made', static fn (): NoDependencies => new NoDependencies());
        $this->container->bind(Greeter::class, EnglishGreeter::class);

        self::assertTrue($this->container->has('leaf'));
        self::assertTrue($this->container->has('made'));
        self::assertTrue($this->container->has(Greeter::class));
    }

    /**
     * `has()` answers for the container's *behaviour*, not its registration table: it is true for
     * anything `get()` would build, and false for what it would refuse.
     */
    public function testHasReflectsWhatGetWouldActuallyDo(): void
    {
        self::assertTrue($this->container->has(NoDependencies::class), 'get() would autowire this');
        self::assertFalse($this->container->has(AbstractThing::class), 'get() would refuse this');
        self::assertFalse($this->container->has(Greeter::class), 'unbound interface');
        self::assertFalse($this->container->has('no\such\entry'));
    }

    // ---- the one shared reflection cache ---------------------------------------------------------

    /**
     * Imported ADR-001 commits to **one** metadata cache across the container and the DTO hydrator.
     * Accepting one is how a consumer honours that; exposing it is how a consumer that let the
     * container build its own can still hand the same instance to the hydrator.
     */
    public function testItUsesTheSharedReflectionCache(): void
    {
        $cache = new ReflectionCache();
        $container = new Container($cache);

        self::assertSame($cache, $container->reflectionCache());

        $container->get(OneDependency::class);

        self::assertTrue($cache->isCached(OneDependency::class));
        self::assertTrue($cache->isCached(NoDependencies::class), 'dependencies go through it too');
    }

    /**
     * The cache must be consulted once per class, not once per resolution — this is the mechanism
     * NFR-02's warm budget actually rests on, and a count is the only way to see it.
     */
    public function testAClassIsReflectedOnlyOnce(): void
    {
        $cache = new ReflectionCache();
        $container = new Container($cache);

        $container->get(TwoLevelsDeep::class);
        $afterFirst = $cache->count();

        $container->get(TwoLevelsDeep::class);

        self::assertSame($afterFirst, $cache->count(), 'a warm resolve reflected something again');
    }
}
