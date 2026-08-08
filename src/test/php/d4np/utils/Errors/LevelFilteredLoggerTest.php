<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Errors;

use D4np\Utils\Errors\Level;
use D4np\Utils\Errors\LevelFilteredLogger;
use D4np\Utils\Tests\Errors\Fixture\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * The routing half of suite T-12: every floor against every level, and what filtering must not hide.
 */
#[CoversClass(LevelFilteredLogger::class)]
#[Group('T-12')]
final class LevelFilteredLoggerTest extends TestCase
{
    public function testIsAPsrLogger(): void
    {
        self::assertInstanceOf(LoggerInterface::class, new LevelFilteredLogger(new RecordingLogger()));
    }

    /**
     * **The matrix.** 64 combinations — every floor × every record level — asserted against
     * `Level::includes()`'s answer rather than against a hand-written expectation table, because a
     * second table would only prove the two tables agree. The property under test is that the
     * decorator routes exactly what the ordering says it should, and the ordering itself is pinned
     * by `LevelTest`.
     *
     * @return iterable<string, array{Level, Level}>
     */
    public static function floorAndRecord(): iterable
    {
        foreach (Level::cases() as $floor) {
            foreach (Level::cases() as $record) {
                yield "floor {$floor->value} <- {$record->value}" => [$floor, $record];
            }
        }
    }

    #[DataProvider('floorAndRecord')]
    public function testTheMatrixRoutesExactlyWhatTheOrderingAllows(Level $floor, Level $record): void
    {
        $inner = new RecordingLogger();
        $logger = new LevelFilteredLogger($inner, $floor);

        $logger->log($record->value, 'a record');

        self::assertCount(
            $floor->includes($record) ? 1 : 0,
            $inner->records,
            "floor {$floor->value} with a {$record->value} record",
        );
    }

    public function testTheDefaultFloorPassesEverything(): void
    {
        $inner = new RecordingLogger();
        $logger = new LevelFilteredLogger($inner);

        foreach (Level::names() as $name) {
            $logger->log($name, 'a record');
        }

        self::assertCount(8, $inner->records);
    }

    public function testAPassedRecordArrivesUnchanged(): void
    {
        $inner = new RecordingLogger();

        (new LevelFilteredLogger($inner, Level::Info))
            ->warning('disk at {percent}%', ['percent' => 91]);

        self::assertSame([[
            'level' => LogLevel::WARNING,
            'message' => 'disk at {percent}%',
            'context' => ['percent' => 91],
        ]], $inner->records);
    }

    /**
     * The level shortcuts are `AbstractLogger`'s, so this asserts the inheritance is real rather
     * than that eight methods were written out — and that each one arrives at the floor comparison
     * with the level PSR-3 says it should.
     */
    public function testTheLevelShortcutMethodsRouteThroughTheSameFilter(): void
    {
        $inner = new RecordingLogger();
        $logger = new LevelFilteredLogger($inner, Level::Error);

        $logger->emergency('a');
        $logger->alert('b');
        $logger->critical('c');
        $logger->error('d');
        $logger->warning('dropped');
        $logger->notice('dropped');
        $logger->info('dropped');
        $logger->debug('dropped');

        self::assertSame(
            [LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR],
            \array_column($inner->records, 'level'),
        );
    }

    public function testALevelInstanceIsAcceptedAsTheRecordLevel(): void
    {
        $inner = new RecordingLogger();

        (new LevelFilteredLogger($inner, Level::Warning))->log(Level::Error, 'typed');

        self::assertCount(1, $inner->records);
    }

    public function testALevelInstanceBelowTheFloorIsStillDropped(): void
    {
        $inner = new RecordingLogger();

        (new LevelFilteredLogger($inner, Level::Warning))->log(Level::Debug, 'typed');

        self::assertSame([], $inner->records);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function notALevel(): iterable
    {
        yield 'unknown name' => ['verbose'];
        yield 'uppercase' => ['ERROR'];
        yield 'integer' => [3];
        yield 'null' => [null];
    }

    /**
     * The decision this class exists to get right: an unknown level throws **whether or not it
     * would have been filtered out**. Were it filtered first, a typo would be invisible under a
     * high floor and fatal under a low one — so it would appear the moment someone turned verbosity
     * up to investigate something else.
     */
    #[DataProvider('notALevel')]
    public function testAnUnknownLevelThrowsEvenWhenTheFloorWouldHaveDroppedIt(mixed $level): void
    {
        $inner = new RecordingLogger();
        // The strictest floor there is: everything except emergency is dropped, so a filter-first
        // implementation would discard this level in silence.
        $logger = new LevelFilteredLogger($inner, Level::Emergency);

        try {
            $logger->log($level, 'a record');
            self::fail('an unknown level must throw');
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame([], $inner->records, 'nothing may reach the inner logger');
    }

    public function testTheInnerLoggerNeverSeesAnUnknownLevel(): void
    {
        $inner = new RecordingLogger();
        // A permissive floor: without validation the record would sail straight through.
        $logger = new LevelFilteredLogger($inner, Level::Debug);

        $this->expectException(InvalidArgumentException::class);

        try {
            $logger->log('verbose', 'a record');
        } finally {
            self::assertSame([], $inner->records);
        }
    }

    public function testTheFloorIsReadableWithoutEmittingARecord(): void
    {
        // A wiring assertion: the reason floor() exists (ADR-0026's stance on the session flags).
        self::assertSame(Level::Notice, (new LevelFilteredLogger(new RecordingLogger(), Level::Notice))->floor());
        self::assertSame(Level::Debug, (new LevelFilteredLogger(new RecordingLogger()))->floor());
    }

    public function testDecoratorsCompose(): void
    {
        $inner = new RecordingLogger();

        // The tighter floor must win regardless of nesting order: two applications of a `<=` test.
        $outerTight = new LevelFilteredLogger(new LevelFilteredLogger($inner, Level::Debug), Level::Error);
        $outerLoose = new LevelFilteredLogger(new LevelFilteredLogger($inner, Level::Error), Level::Debug);

        $outerTight->warning('dropped by the outer');
        $outerLoose->warning('dropped by the inner');

        self::assertSame([], $inner->records);

        $outerTight->error('passes both');
        $outerLoose->error('passes both');

        self::assertCount(2, $inner->records);
    }
}
