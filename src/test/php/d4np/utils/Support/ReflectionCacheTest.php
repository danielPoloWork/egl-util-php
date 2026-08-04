<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\ClassMetadata;
use D4np\Utils\Support\ReflectionCache;
use D4np\Utils\Support\UtilsException;
use D4np\Utils\Support\UtilsThrowable;
use D4np\Utils\Tests\Support\Fixture\AbstractService;
use D4np\Utils\Tests\Support\Fixture\IntersectionTypeService;
use D4np\Utils\Tests\Support\Fixture\MixedTypeService;
use D4np\Utils\Tests\Support\Fixture\NestedDto;
use D4np\Utils\Tests\Support\Fixture\NoConstructorService;
use D4np\Utils\Tests\Support\Fixture\OptionalsDto;
use D4np\Utils\Tests\Support\Fixture\PrivateConstructorService;
use D4np\Utils\Tests\Support\Fixture\ScalarDto;
use D4np\Utils\Tests\Support\Fixture\ServiceContract;
use D4np\Utils\Tests\Support\Fixture\UnionTypeService;
use D4np\Utils\Tests\Support\Fixture\UntypedService;
use D4np\Utils\Tests\Support\Fixture\VariadicService;
use PHPUnit\Framework\TestCase;

/**
 * `ReflectionCache` — spec FR-01/FR-04, ADR-0006.
 *
 * Two things are under test and they are not the same: that the metadata **describes the class
 * truthfully**, and that the cache **actually memoises**. The second is what NFR-01 and NFR-02
 * rest on, and it is the one a test can accidentally not check at all.
 */
final class ReflectionCacheTest extends TestCase
{
    private ReflectionCache $cache;

    protected function setUp(): void
    {
        $this->cache = new ReflectionCache();
    }

    // ------------------------------------------------------------ memoisation

    /**
     * The memoisation itself, observed by object identity rather than by timing.
     *
     * A non-memoising implementation returns an equal-but-distinct object every call, so `===`
     * fails while `==` would still pass — which is exactly why this asserts identity. Timing
     * would be the flaky way to ask the same question.
     */
    public function testTheSameClassReturnsTheVerySameMetadataInstance(): void
    {
        $first = $this->cache->for(ScalarDto::class);
        $second = $this->cache->for(ScalarDto::class);

        self::assertSame($first, $second, 'a second lookup must return the cached object, not a fresh reflection');
    }

    public function testTheCacheReportsWhatItHasReflected(): void
    {
        self::assertFalse($this->cache->isCached(ScalarDto::class));
        self::assertSame(0, $this->cache->count());

        $this->cache->for(ScalarDto::class);

        self::assertTrue($this->cache->isCached(ScalarDto::class));
        self::assertSame(1, $this->cache->count());

        // A repeat lookup must not grow the cache — the observable form of "reflected once".
        $this->cache->for(ScalarDto::class);
        self::assertSame(1, $this->cache->count());

        $this->cache->for(NestedDto::class);
        self::assertSame(2, $this->cache->count());
    }

    public function testTwoCachesAreIndependent(): void
    {
        // Instance-scoped, not global (ADR-0006): one cache's state is invisible to another.
        $other = new ReflectionCache();
        $this->cache->for(ScalarDto::class);

        self::assertFalse($other->isCached(ScalarDto::class));
        self::assertNotSame($this->cache->for(ScalarDto::class), $other->for(ScalarDto::class));
    }

    // --------------------------------------------------------------- accuracy

    public function testScalarConstructorParametersAreDescribedInDeclarationOrder(): void
    {
        $meta = $this->cache->for(ScalarDto::class);

        self::assertSame(ScalarDto::class, $meta->className);
        self::assertTrue($meta->isInstantiable);
        self::assertSame(['email', 'age'], $meta->parameterNames());

        $email = $meta->parameter('email');
        self::assertNotNull($email);
        self::assertSame('string', $email->type);
        self::assertSame('string', $email->declaredType);
        self::assertTrue($email->isBuiltin);
        self::assertFalse($email->allowsNull);
        self::assertFalse($email->hasDefault);
        self::assertFalse($email->isVariadic);
    }

    public function testAnUnknownParameterNameIsNull(): void
    {
        self::assertNull($this->cache->for(ScalarDto::class)->parameter('nope'));
    }

    /** RFC-0001 R-4: "neither nullable nor defaulted" is what makes a key required. */
    public function testNullableAndDefaultedParametersAreDistinguished(): void
    {
        $meta = $this->cache->for(OptionalsDto::class);

        $required = $meta->parameter('required');
        self::assertNotNull($required);
        self::assertFalse($required->allowsNull);
        self::assertFalse($required->hasDefault);
        self::assertFalse($required->isOptional(), 'a plain typed parameter is required');

        $nullable = $meta->parameter('nullable');
        self::assertNotNull($nullable);
        self::assertTrue($nullable->allowsNull);
        self::assertFalse($nullable->hasDefault);
        self::assertTrue($nullable->isOptional());
        self::assertSame('string', $nullable->type, 'the named type survives the ? prefix');
        self::assertSame('?string', $nullable->declaredType);

        $defaulted = $meta->parameter('defaulted');
        self::assertNotNull($defaulted);
        self::assertFalse($defaulted->allowsNull);
        self::assertTrue($defaulted->hasDefault);
        self::assertSame(42, $defaulted->default);
        self::assertTrue($defaulted->isOptional());
    }

    /**
     * A default of `null` is why `hasDefault` cannot be inferred from `default` alone — the two
     * fields carry different facts and a single one would conflate them.
     */
    public function testADefaultOfNullIsStillADeclaredDefault(): void
    {
        $parameter = $this->cache->for(OptionalsDto::class)->parameter('nullableAndDefaulted');

        self::assertNotNull($parameter);
        self::assertTrue($parameter->hasDefault);
        self::assertNull($parameter->default);
    }

    public function testAClassTypedParameterIsAutowirable(): void
    {
        $inner = $this->cache->for(NestedDto::class)->parameter('inner');

        self::assertNotNull($inner);
        self::assertSame(ScalarDto::class, $inner->type);
        self::assertFalse($inner->isBuiltin);
        self::assertTrue($inner->isAutowirable());
    }

    public function testABuiltinParameterIsNotAutowirable(): void
    {
        $email = $this->cache->for(ScalarDto::class)->parameter('email');

        self::assertNotNull($email);
        self::assertTrue($email->isBuiltin);
        self::assertFalse($email->isAutowirable(), 'a scalar has no service to resolve');
    }

    /**
     * Imported ADR-001 has the Container refuse a union-typed parameter rather than pick an arm.
     * The metadata makes that refusable *and* explainable: `type` is null, and `declaredType`
     * still names what was seen.
     */
    public function testAUnionTypeHasNoSingleTypeButKeepsItsDeclaration(): void
    {
        $parameter = $this->cache->for(UnionTypeService::class)->parameter('ambiguous');

        self::assertNotNull($parameter);
        self::assertNull($parameter->type);
        self::assertFalse($parameter->isAutowirable());

        // PHP canonicalises a union's arms rather than preserving declaration order — the
        // fixture declares `int|string` and reflection reports `string|int`, verified directly.
        // Asserting membership instead of an exact string keeps this a test of the metadata
        // rather than of PHP's ordering rule.
        self::assertNotNull($parameter->declaredType);
        self::assertStringContainsString('int', $parameter->declaredType);
        self::assertStringContainsString('string', $parameter->declaredType);
        self::assertStringContainsString('|', $parameter->declaredType);
    }

    public function testAnIntersectionTypeHasNoSingleTypeButKeepsItsDeclaration(): void
    {
        $parameter = $this->cache->for(IntersectionTypeService::class)->parameter('both');

        self::assertNotNull($parameter);
        self::assertNull($parameter->type);
        self::assertNotNull($parameter->declaredType);
        self::assertStringContainsString('Countable', $parameter->declaredType);
        self::assertStringContainsString('ArrayAccess', $parameter->declaredType);
        self::assertFalse($parameter->isAutowirable());
    }

    /**
     * A genuinely untyped parameter and a union-typed one both have `type === null`, and they
     * are not the same situation — `declaredType` is what tells them apart, which is the reason
     * that field exists.
     */
    public function testAnUntypedParameterIsDistinguishableFromAUnionTypedOne(): void
    {
        $untyped = $this->cache->for(UntypedService::class)->parameter('anything');
        $union = $this->cache->for(UnionTypeService::class)->parameter('ambiguous');

        self::assertNotNull($untyped);
        self::assertNotNull($union);
        self::assertNull($untyped->type);
        self::assertNull($union->type);

        self::assertNull($untyped->declaredType, 'no declaration at all');
        self::assertNotNull($union->declaredType, 'a union declares something, just not one thing');
    }

    /**
     * `mixed` is a **named builtin type**, not the absence of one — verified directly against
     * reflection. Flattening it into "untyped" would lose a real distinction: an untyped
     * parameter was never annotated, while `mixed` is a deliberate declaration.
     */
    public function testMixedIsANamedBuiltinTypeNotTheAbsenceOfOne(): void
    {
        $mixed = $this->cache->for(MixedTypeService::class)->parameter('anything');
        $untyped = $this->cache->for(UntypedService::class)->parameter('anything');

        self::assertNotNull($mixed);
        self::assertNotNull($untyped);

        self::assertSame('mixed', $mixed->type);
        self::assertSame('mixed', $mixed->declaredType);
        self::assertTrue($mixed->isBuiltin);
        self::assertTrue($mixed->allowsNull, 'mixed accepts null by definition');
        self::assertFalse($mixed->isAutowirable(), 'a builtin has no service to resolve');

        self::assertNull($untyped->type, 'the untyped case must not be described the same way');
    }

    public function testAnUntypedParameterAllowsNull(): void
    {
        // No declaration constrains nothing, so null is acceptable — which makes the parameter
        // optional under RFC-0001 R-4's rule.
        $untyped = $this->cache->for(UntypedService::class)->parameter('anything');

        self::assertNotNull($untyped);
        self::assertTrue($untyped->allowsNull);
        self::assertTrue($untyped->isOptional());
        self::assertFalse($untyped->isAutowirable());
    }

    public function testAVariadicParameterIsFlaggedAndNotAutowirable(): void
    {
        $parameter = $this->cache->for(VariadicService::class)->parameter('rest');

        self::assertNotNull($parameter);
        self::assertTrue($parameter->isVariadic);
        self::assertTrue($parameter->isOptional(), 'a variadic parameter may legitimately receive nothing');
        self::assertFalse($parameter->isAutowirable());
    }

    // ---------------------------------------------------------- instantiable

    public function testAClassWithNoConstructorHasNoParametersAndIsInstantiable(): void
    {
        $meta = $this->cache->for(NoConstructorService::class);

        self::assertSame([], $meta->parameters);
        self::assertTrue($meta->isInstantiable, 'no constructor is not an error — it is a no-argument one');
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function nonInstantiableClasses(): iterable
    {
        yield 'abstract class' => [AbstractService::class];
        yield 'interface' => [ServiceContract::class];
        yield 'private constructor' => [PrivateConstructorService::class];
    }

    /**
     * @param class-string $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonInstantiableClasses')]
    public function testNonInstantiableClassesAreReflectedAndFlagged(string $class): void
    {
        // Reflectable but not constructible — the Container needs both facts to refuse clearly
        // instead of failing inside `new`.
        $meta = $this->cache->for($class);

        self::assertSame($class, $meta->className);
        self::assertFalse($meta->isInstantiable);
    }

    public function testAnInterfaceIsReflectable(): void
    {
        // Interfaces are the reason the existence guard checks interface_exists() as well:
        // class_exists() is false for one. That PHP fact is not re-asserted here — PHPStan
        // decides it statically — but narrowing the guard to class_exists() alone does make
        // this test error, which is what keeps it meaningful.
        self::assertInstanceOf(ClassMetadata::class, $this->cache->for(ServiceContract::class));
    }

    // ------------------------------------------------------------- failure

    /**
     * A class name that resolves to nothing, built from a parameter so it is not a literal
     * PHPStan can narrow — the type is what a caller reading a class name out of configuration
     * would have, which is exactly the case the runtime guard exists for.
     *
     * @return class-string
     */
    private static function missingClassName(string $suffix): string
    {
        /** @var class-string */
        return 'D4np\\Utils\\Tests\\Support\\Fixture\\Missing' . $suffix;
    }

    public function testReflectingAnUnknownClassThrowsThroughTheLibraryMarker(): void
    {
        try {
            $this->cache->for(self::missingClassName('NoSuchClassAnywhere'));
            self::fail('expected a UtilsException');
        } catch (UtilsThrowable $e) {
            // ADR-0004's contract: a consumer catching everything this library raises catches
            // reflection failures too, rather than a bare \ReflectionException escaping.
            self::assertInstanceOf(UtilsException::class, $e);
            self::assertStringContainsString('NoSuchClassAnywhere', $e->getMessage());
        }
    }

    public function testAFailedReflectionIsNotCached(): void
    {
        try {
            $this->cache->for(self::missingClassName('AlsoMissing'));
        } catch (UtilsException) {
            // expected
        }

        self::assertSame(0, $this->cache->count(), 'a failure must not leave a poisoned entry behind');
    }
}
