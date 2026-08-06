<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\FileException;
use D4np\Utils\Support\FileSequence;
use D4np\Utils\Support\SequenceExhaustedException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `FileSequence` — spec r3 FR-32 (RFC-0002), ADR-0038.
 *
 * Single-process behaviour: rollover, the cap that refuses rather than wraps, and the
 * corrupt-state refusal. The cross-process guarantee is {@see FileSequenceConcurrencyTest}
 * (T-14), which is the one that can actually fail if the locking is wrong.
 */
final class FileSequenceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/egl-utils-seq-' . bin2hex(random_bytes(8));
        if (!mkdir($this->dir) && !is_dir($this->dir)) {
            self::fail('could not create the test directory');
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $entry) {
            @unlink($entry);
        }
        @rmdir($this->dir);
    }

    private function path(): string
    {
        return $this->dir . '/counter.state';
    }

    private function sequence(int $cap = 100): FileSequence
    {
        return new FileSequence($this->path(), $cap);
    }

    public function testFirstDrawIsOne(): void
    {
        self::assertSame(1, $this->sequence()->next('2026-08-06'));
    }

    public function testSubsequentDrawsIncrement(): void
    {
        $sequence = $this->sequence();

        self::assertSame(1, $sequence->next('w'));
        self::assertSame(2, $sequence->next('w'));
        self::assertSame(3, $sequence->next('w'));
    }

    public function testANewWindowResetsToOne(): void
    {
        $sequence = $this->sequence();
        $sequence->next('day-1');
        $sequence->next('day-1');

        self::assertSame(1, $sequence->next('day-2'));
    }

    public function testTheStateSurvivesANewInstanceOnTheSamePath(): void
    {
        $this->sequence()->next('w');
        $this->sequence()->next('w');

        self::assertSame(3, $this->sequence()->next('w'));
    }

    public function testTheStateFileHoldsExactlyOneWindowCounterLine(): void
    {
        $this->sequence()->next('day-1');
        $this->sequence()->next('day-1');

        self::assertSame("day-1|2\n", file_get_contents($this->path()));
    }

    public function testTheCapIsIssuableAndTheNextDrawIsRefused(): void
    {
        $sequence = $this->sequence(cap: 3);

        self::assertSame(1, $sequence->next('w'));
        self::assertSame(2, $sequence->next('w'));
        self::assertSame(3, $sequence->next('w'));

        $this->expectException(SequenceExhaustedException::class);
        $this->expectExceptionMessage('exhausted for window "w": 3 of 3 issued');

        $sequence->next('w');
    }

    public function testExhaustionNeverWrapsToOne(): void
    {
        $sequence = $this->sequence(cap: 1);
        $sequence->next('w');

        try {
            $sequence->next('w');
            self::fail('the sequence should have refused');
        } catch (SequenceExhaustedException) {
            // expected
        }

        // The state must be untouched by the refusal — not reset, not advanced.
        self::assertSame("w|1\n", file_get_contents($this->path()));
    }

    public function testARefusedDrawDoesNotConsumeTheNextWindowsCapacity(): void
    {
        $sequence = $this->sequence(cap: 1);
        $sequence->next('day-1');

        try {
            $sequence->next('day-1');
        } catch (SequenceExhaustedException) {
            // expected
        }

        self::assertSame(1, $sequence->next('day-2'));
    }

    public function testACapOfOneIsUsable(): void
    {
        self::assertSame(1, $this->sequence(cap: 1)->next('w'));
    }

    public function testACapBelowOneIsRefusedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('$cap must be >= 1');

        new FileSequence($this->path(), 0);
    }

    public function testAnAbsentStateFileIsAFreshStartNotAnError(): void
    {
        self::assertFalse(is_file($this->path()));
        self::assertSame(1, $this->sequence()->next('w'));
    }

    public function testABlankStateFileIsAlsoAFreshStart(): void
    {
        // What `touch` and most deploy scripts leave behind.
        file_put_contents($this->path(), "  \n");

        self::assertSame(1, $this->sequence()->next('w'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function corruptStates(): iterable
    {
        yield 'no separator' => ["garbage\n"];
        yield 'counter is not numeric' => ["w|abc\n"];
        yield 'counter is negative' => ["w|-3\n"];
        yield 'too many separators' => ["a|b|3\n"];
        yield 'empty window' => ["|3\n"];
        yield 'counter missing' => ["w|\n"];
    }

    #[DataProvider('corruptStates')]
    public function testACorruptStateFileIsRefusedNotSilentlyReset(string $contents): void
    {
        file_put_contents($this->path(), $contents);

        $this->expectException(FileException::class);
        $this->expectExceptionMessage('corrupt');

        $this->sequence()->next('w');
    }

    public function testACorruptStateFileIsNotOverwrittenByTheRefusal(): void
    {
        file_put_contents($this->path(), "garbage\n");

        try {
            $this->sequence()->next('w');
        } catch (FileException) {
            // expected
        }

        // The evidence a human needs to diagnose it must survive.
        self::assertSame("garbage\n", file_get_contents($this->path()));
    }

    public function testPeekReportsTheLastIssuedNumber(): void
    {
        $sequence = $this->sequence();
        $sequence->next('w');
        $sequence->next('w');

        self::assertSame(2, $sequence->peek('w'));
    }

    public function testPeekIsZeroForAnUnknownWindowOrAnAbsentFile(): void
    {
        $sequence = $this->sequence();

        self::assertSame(0, $sequence->peek('never-used'));

        $sequence->next('w');

        self::assertSame(0, $sequence->peek('other'));
    }

    public function testPeekDoesNotIssueANumber(): void
    {
        $sequence = $this->sequence();
        $sequence->next('w');
        $sequence->peek('w');

        self::assertSame(2, $sequence->next('w'));
    }

    public function testRemainingCountsDownFromTheCap(): void
    {
        $sequence = $this->sequence(cap: 5);

        self::assertSame(5, $sequence->remaining('w'));

        $sequence->next('w');
        $sequence->next('w');

        self::assertSame(3, $sequence->remaining('w'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidWindows(): iterable
    {
        yield 'empty' => [''];
        yield 'contains the separator' => ['a|b'];
        yield 'contains a newline' => ["a\nb"];
        yield 'contains a carriage return' => ["a\rb"];
    }

    #[DataProvider('invalidWindows')]
    public function testAnUnstorableWindowIsRefused(string $window): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->sequence()->next($window);
    }

    public function testAWindowGoingBackwardsReissuesNumbersWhichIsTheRecordedLimit(): void
    {
        // ADR-0038's named limit: windows are opaque, so the class cannot order them and any
        // change resets. Pinned as a test so the limit is visible rather than discovered.
        $sequence = $this->sequence();
        $sequence->next('day-2');
        $sequence->next('day-2');

        self::assertSame(1, $sequence->next('day-1'));
    }

    public function testTwoSequencesOnDifferentPathsAreIndependent(): void
    {
        $a = new FileSequence($this->dir . '/a.state', 10);
        $b = new FileSequence($this->dir . '/b.state', 10);

        $a->next('w');
        $a->next('w');

        self::assertSame(1, $b->next('w'));
    }
}
