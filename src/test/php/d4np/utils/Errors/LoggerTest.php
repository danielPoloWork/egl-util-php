<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Errors;

use D4np\Utils\Errors\Logger;
use D4np\Utils\Support\UtilsException;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Spec FR-17's PSR-3 logger.
 */
final class LoggerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/d4np-logger-' . bin2hex(random_bytes(6)) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        $contents = is_file($this->path) ? (string) file_get_contents($this->path) : '';

        return $contents === '' ? [] : explode("\n", rtrim($contents, "\n"));
    }

    // ---- PSR-3 conformance ------------------------------------------------------------------------

    public function testItIsAPsr3Logger(): void
    {
        self::assertInstanceOf(LoggerInterface::class, new Logger($this->path));
    }

    /**
     * `AbstractLogger` gives the eight level methods for free — asserted because "for free" is a
     * claim about a base class, and each one must arrive at `log()` with the right level.
     */
    public function testAllEightLevelMethodsRecordAtTheirOwnLevel(): void
    {
        $logger = new Logger($this->path);

        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $method) {
            $logger->{$method}('m');
        }

        $lines = $this->lines();
        self::assertCount(8, $lines);

        foreach (['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING', 'NOTICE', 'INFO', 'DEBUG'] as $i => $level) {
            self::assertStringContainsString($level . ': m', $lines[$i]);
        }
    }

    /**
     * The one throw PSR-3 permits. Logging an unknown level at some guessed severity would make it
     * subject to a filtering rule nobody wrote.
     */
    public function testAnUnknownLevelThrowsThePsr3Exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is not a PSR-3 log level/');

        (new Logger($this->path))->log('verbose', 'm');
    }

    public function testAnUnknownMinimumLevelIsRefusedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Logger($this->path, 'chatty');
    }

    // ---- writing ----------------------------------------------------------------------------------

    public function testRecordsAreAppendedRatherThanReplacing(): void
    {
        $logger = new Logger($this->path);

        $logger->info('first');
        $logger->info('second');

        self::assertCount(2, $this->lines());
        self::assertStringContainsString('first', $this->lines()[0]);
        self::assertStringContainsString('second', $this->lines()[1]);
    }

    public function testARecordCarriesAUtcTimestampAndTheLevel(): void
    {
        (new Logger($this->path))->warning('careful');

        self::assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\] WARNING: careful$/',
            $this->lines()[0],
        );
    }

    /**
     * **The LOCK_EX finding, asserted functionally.** `file_put_contents()` with `LOCK_EX` on a
     * `php://` stream returns `false` and writes **nothing** — verified. So a logger that locked
     * unconditionally would silently discard every console record, and this test is what notices:
     * with the lock applied the captured buffer comes back empty.
     */
    public function testWritingToAStreamDestinationActuallyWrites(): void
    {
        ob_start();

        try {
            (new Logger('php://output'))->info('to the console');
            $captured = (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        self::assertStringContainsString('INFO: to the console', $captured);
    }

    // ---- level filtering --------------------------------------------------------------------------

    public function testRecordsBelowTheMinimumLevelAreDropped(): void
    {
        $logger = new Logger($this->path, LogLevel::WARNING);

        $logger->debug('dropped');
        $logger->info('dropped');
        $logger->notice('dropped');
        $logger->warning('kept');
        $logger->error('kept');
        $logger->emergency('kept');

        self::assertCount(3, $this->lines());
    }

    public function testTheDefaultMinimumKeepsEverything(): void
    {
        $logger = new Logger($this->path);

        $logger->debug('kept');
        $logger->emergency('kept');

        self::assertCount(2, $this->lines());
    }

    // ---- interpolation ----------------------------------------------------------------------------

    public function testPlaceholdersAreReplacedFromTheContext(): void
    {
        (new Logger($this->path))->info('user {id} did {what}', ['id' => 42, 'what' => 'login']);

        self::assertStringContainsString('user 42 did login', $this->lines()[0]);
    }

    public function testBooleansAndNullInterpolateReadably(): void
    {
        (new Logger($this->path))->info('a={a} b={b} c={c}', ['a' => true, 'b' => false, 'c' => null]);

        self::assertStringContainsString('a=true b=false c=null', $this->lines()[0]);
    }

    /**
     * A placeholder whose value cannot be rendered is left intact rather than becoming `Array`, so
     * the record shows a value was expected there and did not arrive.
     */
    public function testAnUnrenderablePlaceholderIsLeftAsItself(): void
    {
        (new Logger($this->path))->info('got {thing}', ['thing' => ['an', 'array']]);

        self::assertStringContainsString('got {thing}', $this->lines()[0]);
    }

    // ---- the context, and the Throwable finding ----------------------------------------------------

    public function testTheContextIsAppendedAsJson(): void
    {
        (new Logger($this->path))->info('m', ['k' => 'v', 'n' => 1]);

        self::assertStringContainsString('{"k":"v","n":1}', $this->lines()[0]);
    }

    /**
     * **The `{}` finding.** `json_encode()` serialises an exception's *public* properties, of which
     * there are none, so a throwable passed straight through encodes to `{}` — no error, no warning,
     * every detail gone from the one record anyone would read. Verified before this was written.
     */
    public function testAThrowableInTheContextIsExpandedRatherThanEncodedAsAnEmptyObject(): void
    {
        (new Logger($this->path))->error('failed', ['exception' => new \RuntimeException('the cause', 7)]);

        $line = $this->lines()[0];

        self::assertStringNotContainsString('"exception":{}', $line, 'the throwable was silently emptied');
        self::assertStringContainsString('RuntimeException', $line);
        self::assertStringContainsString('the cause', $line);
        self::assertStringContainsString('"code":7', $line);
        self::assertStringContainsString('"trace"', $line);
    }

    public function testAChainedThrowableKeepsItsCause(): void
    {
        $root = new \RuntimeException('the root cause');
        $wrapper = new UtilsException('the wrapper', 0, $root);

        (new Logger($this->path))->error('failed', ['exception' => $wrapper]);

        $line = $this->lines()[0];

        self::assertStringContainsString('the wrapper', $line);
        self::assertStringContainsString('the root cause', $line);
        self::assertStringContainsString('"previous"', $line);
    }

    /**
     * One line per record, even when the record contains a stack trace — which is the whole reason
     * the context is JSON. A log that sometimes spans lines cannot be read with `grep` or `tail`, and
     * that stops being true exactly when someone is in a hurry.
     */
    public function testARecordStaysOnOneLineEvenWithATrace(): void
    {
        (new Logger($this->path))->error('failed', ['exception' => new \RuntimeException('multi')]);

        self::assertCount(1, $this->lines(), 'a trace broke the record across lines');
    }

    // ---- failure behaviour ------------------------------------------------------------------------

    /**
     * Refused at wiring time, where the trace points at the misconfiguration — rather than during the
     * incident the logger was supposed to record.
     */
    public function testAnUnwritableDestinationIsRefusedAtConstruction(): void
    {
        $this->expectException(UtilsException::class);
        $this->expectExceptionMessageMatches('/cannot be written to/');

        new Logger(sys_get_temp_dir() . '/d4np-no-such-directory-xyz/app.log');
    }

    /**
     * PSR-3 permits a throw only for a bad level, and a logger that threw while an exception handler
     * was using it would turn a handled failure into a fatal one. So once constructed, writing is
     * best-effort: here the file is replaced by a directory behind the logger's back.
     */
    public function testAWriteFailureAfterConstructionDoesNotThrow(): void
    {
        $logger = new Logger($this->path);
        $logger->info('fine');

        unlink($this->path);
        mkdir($this->path);

        try {
            $logger->error('this cannot be written');
            $this->addToAssertionCount(1);
        } finally {
            rmdir($this->path);
        }
    }

    public function testAnExistingFileIsAcceptedAndNotTruncated(): void
    {
        file_put_contents($this->path, "pre-existing\n");

        (new Logger($this->path))->info('added');

        self::assertSame('pre-existing', $this->lines()[0]);
        self::assertCount(2, $this->lines());
    }
}
