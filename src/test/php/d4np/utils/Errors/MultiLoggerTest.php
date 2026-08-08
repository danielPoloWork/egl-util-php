<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Errors;

use D4np\Utils\Errors\Level;
use D4np\Utils\Errors\Logger;
use D4np\Utils\Errors\MultiLogger;
use D4np\Utils\Tests\Errors\Fixture\RecordingLogger;
use D4np\Utils\Tests\Errors\Fixture\ThrowingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;

/**
 * The fan-out half of suite T-12: every delegate gets the record, and what a failing one may cost.
 */
#[CoversClass(MultiLogger::class)]
#[Group('T-12')]
final class MultiLoggerTest extends TestCase
{
    private string $path = '';

    protected function tearDown(): void
    {
        if ($this->path !== '' && \is_file($this->path)) {
            \unlink($this->path);
        }
    }

    public function testIsAPsrLogger(): void
    {
        self::assertInstanceOf(LoggerInterface::class, new MultiLogger());
    }

    public function testEveryDelegateGetsTheSameRecord(): void
    {
        $first = new RecordingLogger();
        $second = new RecordingLogger();
        $third = new RecordingLogger();

        (new MultiLogger($first, $second, $third))->warning('to all three', ['k' => 'v']);

        foreach ([$first, $second, $third] as $delegate) {
            self::assertSame([[
                'level' => LogLevel::WARNING,
                'message' => 'to all three',
                'context' => ['k' => 'v'],
            ]], $delegate->records);
        }
    }

    public function testTheRecordIsNotFilteredHere(): void
    {
        $inner = new RecordingLogger();
        $logger = new MultiLogger($inner);

        foreach (Level::names() as $name) {
            $logger->log($name, 'every level passes a composite');
        }

        // Filtering is LevelFilteredLogger's job. A composite that also filtered would give a
        // channel two floors, only one of which is visible where it is configured.
        self::assertCount(8, $inner->records);
    }

    public function testCountReportsHowManyDestinationsARecordReaches(): void
    {
        self::assertSame(0, (new MultiLogger())->count());
        self::assertSame(2, (new MultiLogger(new RecordingLogger(), new RecordingLogger()))->count());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function notALevel(): iterable
    {
        yield 'unknown name' => ['verbose'];
        yield 'integer' => [3];
        yield 'null' => [null];
    }

    /**
     * A partial fan-out is the state this ordering exists to prevent: the same record present in
     * the first destination, absent from the third, with nothing saying why.
     */
    #[DataProvider('notALevel')]
    public function testAnUnknownLevelThrowsBeforeAnyDelegateIsWrittenTo(mixed $level): void
    {
        $first = new RecordingLogger();
        $second = new RecordingLogger();

        try {
            (new MultiLogger($first, $second))->log($level, 'a record');
            self::fail('an unknown level must throw');
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame([], $first->records);
        self::assertSame([], $second->records);
    }

    public function testAnEmptyFanOutStillValidatesTheLevel(): void
    {
        // The property that makes the empty composite the right disabled channel: PSR-3's own
        // NullLogger accepts 'verbose' without complaint (verified), so a channel disabled with one
        // would stop detecting a bad level until the day it was enabled again.
        $this->expectException(InvalidArgumentException::class);

        (new MultiLogger())->log('verbose', 'a record');
    }

    public function testAnEmptyFanOutAcceptsAValidRecordAndDiscardsIt(): void
    {
        $logger = new MultiLogger();

        $logger->warning('nowhere to go');

        // Nothing to assert about a destination; the assertion is that this did not throw.
        self::assertSame(0, $logger->count());
    }

    public function testAFailingDelegateDoesNotDepriveTheOthers(): void
    {
        $before = new RecordingLogger();
        $failing = new ThrowingLogger();
        $after = new RecordingLogger();

        try {
            (new MultiLogger($before, $failing, $after))->error('the record still matters');
        } catch (RuntimeException) {
            // expected — see the next test
        }

        self::assertCount(1, $before->records);
        self::assertCount(1, $after->records, 'a delegate after the failure must still be written to');
        self::assertSame(1, $failing->attempts);
    }

    public function testTheFirstFailureIsRethrownAfterEveryDelegateWasAttempted(): void
    {
        $first = new ThrowingLogger('the first failure');
        $second = new ThrowingLogger('the second failure');
        $recording = new RecordingLogger();

        try {
            (new MultiLogger($first, $second, $recording))->error('a record');
            self::fail('a failing delegate must not be swallowed by the composite');
        } catch (RuntimeException $e) {
            self::assertSame('the first failure', $e->getMessage());
        }

        self::assertSame(1, $second->attempts, 'the second delegate is attempted despite the first failing');
        self::assertCount(1, $recording->records);
    }

    /**
     * Stated rather than hidden: only the first failure survives. PHP has no suppressed-exception
     * mechanism, so the choice is between losing a later failure and losing the first one — the same
     * trade ADR-0016 recorded for a rollback that fails while an exception is in flight.
     */
    public function testLaterFailuresAreLostAndThatIsTheDocumentedTrade(): void
    {
        $first = new ThrowingLogger('the first failure');
        $second = new ThrowingLogger('the second failure');

        try {
            (new MultiLogger($first, $second))->error('a record');
            self::fail('expected the first failure');
        } catch (RuntimeException $e) {
            self::assertSame('the first failure', $e->getMessage());
            self::assertNull($e->getPrevious(), 'the second failure is not chained — it is lost');
        }
    }

    /**
     * The composition claim from `MultiLogger`'s docblock, performed rather than asserted in prose:
     * over this library's own leaves, the composite never throws, because {@see Logger} refuses to
     * escalate a write failure (ADR-0029). The rethrow above only ever concerns third-party loggers.
     */
    public function testOverThisLibrarysOwnLoggersTheCompositeNeverThrows(): void
    {
        $this->path = \sys_get_temp_dir() . '/d4np-multi-' . \bin2hex(\random_bytes(4)) . '.log';
        \file_put_contents($this->path, '');

        $logger = new MultiLogger(new Logger($this->path), new Logger('php://output'));

        // The destination passed the constructor's check and then went bad — the only way a leaf
        // reaches a failing write at all.
        @\chmod($this->path, 0o444);

        \ob_start();
        $logger->error('an unwritable file and a stream');
        $console = (string) \ob_get_clean();

        @\chmod($this->path, 0o666);

        self::assertStringContainsString('an unwritable file and a stream', $console);
    }
}
