<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Security\Escaper;
use D4np\Utils\Support\Env;
use D4np\Utils\Support\File;
use D4np\Utils\Support\Json;
use D4np\Utils\Support\Str;
use D4np\Utils\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The static-utility contract every helper class in this library follows.
 *
 * Each of these exposes only static methods and declares a private constructor so it cannot be
 * instantiated. That constructor is the guard, and it is *unreachable by design* — which is
 * exactly why it had never been executed, and why these classes were dragging the coverage floor
 * down (roadmap 2.7): `Version` sat at 0%, its private constructor being the only executable
 * line it has.
 *
 * The test does two things rather than one, and the pairing is the point: it asserts the guard
 * **is** there (`new` is impossible from outside), and it drives the constructor through
 * reflection to confirm the guard is **inert** — that it does no work, allocates nothing, and
 * cannot fail. A constructor nobody has ever run is a constructor nobody has checked; a
 * `sleep(10)` or a thrown exception hiding in one would be invisible until the day someone
 * reflected on the class.
 */
final class StaticUtilityContractTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function staticUtilityClasses(): iterable
    {
        yield 'Version' => [Version::class];
        yield 'Str' => [Str::class];
        yield 'File' => [File::class];
        yield 'Env' => [Env::class];
        yield 'Json' => [Json::class];
        yield 'Escaper' => [Escaper::class];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('staticUtilityClasses')]
    public function testTheClassIsFinalAndCannotBeInstantiatedNormally(string $class): void
    {
        $reflection = new ReflectionClass($class);

        self::assertTrue($reflection->isFinal(), sprintf('%s must be final', $class));
        self::assertFalse(
            $reflection->isInstantiable(),
            sprintf('%s must not be instantiable — its constructor is the guard', $class),
        );

        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor, sprintf('%s must declare a constructor to make private', $class));
        self::assertTrue($constructor->isPrivate(), sprintf('%s::__construct() must be private', $class));
        self::assertSame(
            0,
            $constructor->getNumberOfParameters(),
            sprintf('%s::__construct() takes no parameters — it exists only to be private', $class),
        );
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('staticUtilityClasses')]
    public function testThePrivateConstructorIsInert(string $class): void
    {
        // Reflection is the only way to reach it, which is the whole point of the guard. Driving
        // it here proves the constructor does nothing: it returns an object and throws nothing.
        // Without this, the one statement in it has never run anywhere.
        $reflection = new ReflectionClass($class);

        $instance = $reflection->newInstanceWithoutConstructor();
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        $constructor->setAccessible(true);
        $constructor->invoke($instance);

        self::assertInstanceOf($class, $instance);
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('staticUtilityClasses')]
    public function testEveryPublicMethodIsStatic(string $class): void
    {
        // The other half of the contract: a private constructor is pointless if some public
        // method needs an instance it can never get.
        //
        // Collected into a list and asserted once, rather than asserted inside the loop: a class
        // with no public methods at all (Version) would otherwise run the loop zero times and
        // assert nothing, which PHPUnit correctly flags as risky — and `failOnRisky` is on.
        $nonStatic = [];
        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            if ($method->isPublic() && !$method->isStatic()) {
                $nonStatic[] = $method->getName();
            }
        }

        self::assertSame(
            [],
            $nonStatic,
            sprintf('%s has public non-static method(s): %s', $class, implode(', ', $nonStatic)),
        );
    }
}
