<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\CryptoException;
use D4np\Utils\Support\CsvException;
use D4np\Utils\Support\DatabaseException;
use D4np\Utils\Support\FileException;
use D4np\Utils\Support\HttpClientException;
use D4np\Utils\Support\HttpException;
use D4np\Utils\Support\HydrationException;
use D4np\Utils\Support\InvalidUrlException;
use D4np\Utils\Support\JsonException;
use D4np\Utils\Support\MethodNotAllowedException;
use D4np\Utils\Support\MissingKeyException;
use D4np\Utils\Support\RouteNotFoundException;
use D4np\Utils\Support\SequenceExhaustedException;
use D4np\Utils\Support\TypeMismatchException;
use D4np\Utils\Support\UnknownKeyException;
use D4np\Utils\Support\UtilsException;
use D4np\Utils\Support\UtilsThrowable;
use JsonException as NativeJsonException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

/**
 * The exception hierarchy is a MAJOR-version surface (RFC-0001), so these tests assert its
 * *shape*, not merely that the classes exist.
 *
 * The discovery test is the load-bearing one: it enumerates the exception classes from disk
 * rather than from a hand-written list, so an exception added later that forgets to join the
 * family fails here instead of reaching a consumer's `catch` block and missing it.
 */
final class ExceptionHierarchyTest extends TestCase
{
    private const SUPPORT_DIR = __DIR__ . '/../../../../../main/php/d4np/utils/Support';
    private const NAMESPACE_PREFIX = 'D4np\\Utils\\Support\\';

    /**
     * Every exception class shipped in Support/, discovered from disk.
     *
     * @return list<class-string>
     */
    private static function discoverExceptionClasses(): array
    {
        $dir = \realpath(self::SUPPORT_DIR);
        self::assertIsString($dir, 'the Support source directory should be resolvable');

        $found = [];
        foreach (\scandir($dir) ?: [] as $entry) {
            if (!\str_ends_with($entry, 'Exception.php')) {
                continue;
            }
            /** @var class-string $class */
            $class = self::NAMESPACE_PREFIX . \basename($entry, '.php');
            $found[] = $class;
        }
        \sort($found);

        return $found;
    }

    public function testEveryExceptionInSupportJoinsTheFamily(): void
    {
        $classes = self::discoverExceptionClasses();

        self::assertNotSame([], $classes, 'no exception classes were discovered — the test would be vacuous');

        foreach ($classes as $class) {
            self::assertTrue(
                \is_a($class, UtilsThrowable::class, true),
                \sprintf(
                    '%s does not implement %s. Every exception this package throws must be '
                    . 'catchable through the one marker interface (ADR-0004).',
                    $class,
                    UtilsThrowable::class,
                ),
            );
            self::assertTrue(
                \is_a($class, UtilsException::class, true),
                \sprintf('%s does not extend %s.', $class, UtilsException::class),
            );
        }
    }

    public function testTheDiscoveredSetIsExactlyTheDocumentedHierarchy(): void
    {
        // Pinned deliberately: RFC-0001 makes the hierarchy's shape a MAJOR surface, so adding
        // or removing a member is a decision, not an edit that should slip through green.
        self::assertSame(
            [
                CryptoException::class,
                CsvException::class,
                DatabaseException::class,
                FileException::class,
                HttpClientException::class,
                HttpException::class,
                HydrationException::class,
                InvalidUrlException::class,
                JsonException::class,
                MethodNotAllowedException::class,
                MissingKeyException::class,
                RouteNotFoundException::class,
                SequenceExhaustedException::class,
                TypeMismatchException::class,
                UnknownKeyException::class,
                UtilsException::class,
            ],
            self::discoverExceptionClasses(),
        );
    }

    /**
     * @return iterable<string, array{class-string<HydrationException>}>
     */
    public static function hydrationLeaves(): iterable
    {
        yield 'unknown key' => [UnknownKeyException::class];
        yield 'missing key' => [MissingKeyException::class];
        yield 'type mismatch' => [TypeMismatchException::class];
    }

    /**
     * @param class-string<HydrationException> $leaf
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('hydrationLeaves')]
    public function testHydrationLeavesAreCatchableCoarsely(string $leaf): void
    {
        self::assertTrue(
            \is_a($leaf, HydrationException::class, true),
            \sprintf('%s must be catchable as %s', $leaf, HydrationException::class),
        );
    }

    public function testNonHydrationExceptionsAreNotCaughtByAHydrationCatch(): void
    {
        // The other half of the coarse-catch contract: a `catch (HydrationException)` must not
        // swallow a database or HTTP failure. Asserting only the positive direction would pass
        // even if every exception shared one parent.
        //
        // The set is derived from disk rather than written out, for two reasons: a member added
        // later is covered without anyone remembering to add it here, and a hard-coded list of
        // literals is something PHPStan can decide statically — which would make this assertion
        // a tautology dressed as a test.
        $hydration = [HydrationException::class, UnknownKeyException::class, MissingKeyException::class, TypeMismatchException::class];
        $others = \array_values(\array_filter(
            self::discoverExceptionClasses(),
            static fn (string $class): bool => !\in_array($class, $hydration, true),
        ));

        self::assertNotSame([], $others, 'no non-hydration exceptions found — the test would be vacuous');

        foreach ($others as $class) {
            self::assertFalse(
                \is_a($class, HydrationException::class, true),
                \sprintf('%s must NOT be catchable as %s', $class, HydrationException::class),
            );
        }
    }

    public function testConcreteLeavesAreFinalAndBasesAreExtensible(): void
    {
        // ADR-0004's extension-point contract, asserted so a later edit cannot flip it quietly.
        // HttpException joined the list at ADR-0049: the group grew a second failure kind
        // (a transport failure, which is not a caller's shape error), and both must stay
        // catchable as HttpException.
        foreach ([UtilsException::class, HydrationException::class, HttpException::class] as $base) {
            self::assertFalse(
                (new ReflectionClass($base))->isFinal(),
                \sprintf('%s is a documented extension point and must stay non-final', $base),
            );
        }
        $leaves = [
            CryptoException::class,
            CsvException::class,
            DatabaseException::class,
            FileException::class,
            HttpClientException::class,
            InvalidUrlException::class,
            JsonException::class,
            MethodNotAllowedException::class,
            MissingKeyException::class,
            RouteNotFoundException::class,
            SequenceExhaustedException::class,
            TypeMismatchException::class,
            UnknownKeyException::class,
        ];
        foreach ($leaves as $leaf) {
            self::assertTrue(
                (new ReflectionClass($leaf))->isFinal(),
                \sprintf('%s is a concrete leaf of a MAJOR-pinned hierarchy and must be final', $leaf),
            );
        }
    }

    public function testUnknownKeyFactoryCarriesThePathAndNamesIt(): void
    {
        // stdClass stands in for a consumer DTO: `forKey()` declares `class-string`, so passing
        // a name that resolves to nothing would be a type violation the production hydrator
        // could never make (it passes `$target::class`).
        $e = UnknownKeyException::forKey('address.postcode', stdClass::class);

        self::assertSame('address.postcode', $e->path());
        self::assertStringContainsString('address.postcode', $e->getMessage());
        self::assertStringContainsString('stdClass', $e->getMessage());
        self::assertStringContainsString('lenient()', $e->getMessage(), 'the message should name the opt-out');
    }

    public function testMissingKeyFactoryCarriesThePathAndSaysItAppliesToBothModes(): void
    {
        $e = MissingKeyException::forKey('email', stdClass::class);

        self::assertSame('email', $e->path());
        self::assertStringContainsString('email', $e->getMessage());
        self::assertStringContainsString('lenient', $e->getMessage());
    }

    public function testTypeMismatchFactoryNamesPathExpectedAndActual(): void
    {
        $e = TypeMismatchException::at('items.0.qty', 'int', 'string');

        self::assertSame('items.0.qty', $e->path());
        self::assertStringContainsString('items.0.qty', $e->getMessage());
        self::assertStringContainsString('int', $e->getMessage());
        self::assertStringContainsString('string', $e->getMessage());
    }

    public function testHydrationPathDefaultsToEmptyWhenNotAttributable(): void
    {
        self::assertSame('', (new HydrationException('malformed payload'))->path());
    }

    public function testJsonExceptionWrapsTheNativeOneWithoutLosingIt(): void
    {
        $native = new NativeJsonException('Syntax error', 4);

        $wrapped = JsonException::wrap($native);

        self::assertSame('Syntax error', $wrapped->getMessage());
        self::assertSame(4, $wrapped->getCode());
        self::assertSame($native, $wrapped->getPrevious(), 'the original must stay retrievable');
        self::assertInstanceOf(UtilsThrowable::class, $wrapped);
        // Not asserted here: that the wrapper is NOT a native \JsonException. PHPStan at max
        // level decides that statically for every call site in the codebase, permanently —
        // a strictly stronger guarantee than one runtime assertion, which it would flag as an
        // already-narrowed comparison anyway.
    }
}
