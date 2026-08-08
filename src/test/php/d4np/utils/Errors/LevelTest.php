<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Errors;

use D4np\Utils\Errors\Level;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

/**
 * The level vocabulary and its ordering (spec FR-41, suite T-12).
 */
#[CoversClass(Level::class)]
#[Group('T-12')]
final class LevelTest extends TestCase
{
    public function testEveryPsrLevelHasExactlyOneCase(): void
    {
        $psr = (new \ReflectionClass(LogLevel::class))->getConstants();
        $cases = \array_map(static fn (Level $l): string => $l->value, Level::cases());

        // Both directions: PSR-3 gains a level and we would not notice, or we invent one it has
        // never heard of and a consumer's `LogLevel::` constant stops resolving to a case.
        self::assertSame(\array_values($psr), $cases, 'the enum and PSR-3 must name the same levels');
        self::assertCount(8, $cases);
    }

    /**
     * The ranks are the only reason this enum exists, so a ninth case without one must not be
     * possible to add quietly. `rank()` reads a private map; if a case were missing from it, this
     * fails with an undefined-key error rather than a wrong comparison somewhere downstream.
     */
    public function testEveryCaseHasARank(): void
    {
        $ranks = [];

        foreach (Level::cases() as $case) {
            $ranks[$case->value] = $case->rank();
        }

        self::assertSame(
            [
                LogLevel::EMERGENCY => 0,
                LogLevel::ALERT => 1,
                LogLevel::CRITICAL => 2,
                LogLevel::ERROR => 3,
                LogLevel::WARNING => 4,
                LogLevel::NOTICE => 5,
                LogLevel::INFO => 6,
                LogLevel::DEBUG => 7,
            ],
            $ranks,
        );
    }

    public function testMostSevereRanksLowestSoPassingAFloorIsALessThanOrEqual(): void
    {
        self::assertLessThan(Level::Debug->rank(), Level::Emergency->rank());
        self::assertLessThan(Level::Warning->rank(), Level::Error->rank());
    }

    /**
     * @return iterable<string, array{Level, Level, bool}>
     */
    public static function inclusionCases(): iterable
    {
        yield 'a floor includes itself' => [Level::Warning, Level::Warning, true];
        yield 'warning includes error' => [Level::Warning, Level::Error, true];
        yield 'warning includes emergency' => [Level::Warning, Level::Emergency, true];
        yield 'warning excludes notice' => [Level::Warning, Level::Notice, false];
        yield 'warning excludes debug' => [Level::Warning, Level::Debug, false];
        yield 'debug includes everything' => [Level::Debug, Level::Emergency, true];
        yield 'debug includes debug' => [Level::Debug, Level::Debug, true];
        yield 'emergency excludes alert' => [Level::Emergency, Level::Alert, false];
    }

    #[DataProvider('inclusionCases')]
    public function testIncludesAnswersWhetherARecordPassesAFloor(Level $floor, Level $record, bool $expected): void
    {
        self::assertSame($expected, $floor->includes($record));
    }

    public function testEveryFloorIncludesEmergencyAndOnlyDebugIncludesDebug(): void
    {
        $includingDebug = [];

        foreach (Level::cases() as $floor) {
            self::assertTrue($floor->includes(Level::Emergency), "{$floor->value} must pass emergency");

            if ($floor->includes(Level::Debug)) {
                $includingDebug[] = $floor->value;
            }
        }

        self::assertSame([LogLevel::DEBUG], $includingDebug);
    }

    public function testRankOfAcceptsThePsrLevelString(): void
    {
        self::assertSame(0, Level::rankOf(LogLevel::EMERGENCY));
        self::assertSame(7, Level::rankOf('debug'));
    }

    public function testRankOfAcceptsALevelInstance(): void
    {
        self::assertSame(4, Level::rankOf(Level::Warning));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function notALevel(): iterable
    {
        yield 'unknown name' => ['verbose'];
        yield 'wrong case' => ['WARNING'];
        yield 'empty string' => [''];
        yield 'integer' => [3];
        yield 'null' => [null];
        yield 'array' => [['warning']];
        yield 'object' => [new \stdClass()];
        yield 'the rank itself' => [4];
    }

    #[DataProvider('notALevel')]
    public function testRankOfReturnsNullForAnythingThatIsNotALevel(mixed $level): void
    {
        self::assertNull(Level::rankOf($level));
    }

    /**
     * Uppercase deserves its own note: `LogLevel::WARNING` is the *constant* name, `'warning'` its
     * value, and PSR-3 specifies the value. Accepting `'WARNING'` would be this library inventing a
     * second spelling that other PSR-3 loggers reject.
     */
    public function testLevelNamesAreCaseSensitive(): void
    {
        self::assertNull(Level::rankOf('Warning'));
        self::assertSame(4, Level::rankOf('warning'));
    }

    public function testInvalidBuildsThePsrExceptionNamingEveryAcceptedLevel(): void
    {
        $exception = Level::invalid('verbose');

        self::assertInstanceOf(InvalidArgumentException::class, $exception);
        self::assertStringContainsString("'verbose'", $exception->getMessage());

        foreach (Level::names() as $name) {
            self::assertStringContainsString($name, $exception->getMessage());
        }
    }

    public function testInvalidDescribesANonScalarByTypeRatherThanExportingIt(): void
    {
        // var_export()ing an object into an exception message is how a log level ends up printing
        // a whole object graph at the top of a stack trace.
        self::assertStringContainsString('stdClass', Level::invalid(new \stdClass())->getMessage());
        self::assertStringContainsString('array', Level::invalid([])->getMessage());
    }

    public function testNamesAreOrderedMostSevereFirst(): void
    {
        self::assertSame(
            ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'],
            Level::names(),
        );
    }
}
