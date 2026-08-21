<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\FrozenClock;
use D4np\Utils\Support\FrozenSleeper;
use D4np\Utils\Support\Sleeper;
use D4np\Utils\Support\SystemSleeper;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec r21 FR-49 (RFC-0003), ADR-0066: the seam for waiting, which PSR-20's clock does not cover.
 *
 * The load-bearing assertion is {@see testTheFrozenSleeperAdvancesTheClockItWasGiven()}. Everything
 * {@see \D4np\Utils\Support\Retrier}'s deadline tests claim rests on it: if the double recorded a
 * request without moving time, those tests would still pass while asserting nothing about a
 * deadline that could never arrive.
 */
final class SleeperTest extends TestCase
{
    private static function clock(): FrozenClock
    {
        return new FrozenClock(new DateTimeImmutable('2026-08-21 12:00:00.000000'));
    }

    // -----------------------------------------------------------------------------------------
    // The double: records, and moves time by exactly what it recorded
    // -----------------------------------------------------------------------------------------

    public function testTheFrozenSleeperAdvancesTheClockItWasGiven(): void
    {
        $clock = self::clock();
        $sleeper = new FrozenSleeper($clock);

        $sleeper->sleep(250);

        self::assertSame(
            '12:00:00.250000',
            $clock->now()->format('H:i:s.u'),
            'without this, no deadline is ever reachable in a test and every deadline assertion is '
            . 'vacuous',
        );
    }

    public function testSubSecondAndWholeSecondPartsBothLand(): void
    {
        $clock = self::clock();
        $sleeper = new FrozenSleeper($clock);

        $sleeper->sleep(1_250);

        self::assertSame('12:00:01.250000', $clock->now()->format('H:i:s.u'));
    }

    public function testAdvancesAreCumulative(): void
    {
        $clock = self::clock();
        $sleeper = new FrozenSleeper($clock);

        $sleeper->sleep(100);
        $sleeper->sleep(400);
        $sleeper->sleep(1_500);

        self::assertSame('12:00:02.000000', $clock->now()->format('H:i:s.u'));
    }

    public function testItRecordsEveryRequestInOrder(): void
    {
        $sleeper = new FrozenSleeper(self::clock());

        $sleeper->sleep(30);
        $sleeper->sleep(0);
        $sleeper->sleep(10);

        self::assertSame([30, 0, 10], $sleeper->requested());
        self::assertSame(40, $sleeper->total());
    }

    public function testAZeroRequestIsRecordedButMovesNothing(): void
    {
        $clock = self::clock();
        $sleeper = new FrozenSleeper($clock);

        $sleeper->sleep(0);

        self::assertSame([0], $sleeper->requested(), 'a full-jitter draw of zero is a real event and '
            . 'the record must show it happened');
        self::assertSame('12:00:00.000000', $clock->now()->format('H:i:s.u'));
    }

    public function testNothingIsRecordedBeforeTheFirstRequest(): void
    {
        $sleeper = new FrozenSleeper(self::clock());

        self::assertSame([], $sleeper->requested());
        self::assertSame(0, $sleeper->total());
    }

    // -----------------------------------------------------------------------------------------
    // Both halves agree on the non-positive contract
    // -----------------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{Sleeper}>
     */
    public static function bothImplementations(): iterable
    {
        yield 'production' => [new SystemSleeper()];
        yield 'test double' => [new FrozenSleeper(self::clock())];
    }

    /**
     * A non-positive duration is a no-op on both halves, not an error.
     *
     * Full jitter draws from zero upward, so a zero delay is part of the specified distribution. A
     * sleeper that refused it would turn the lower bound of the jitter band into a crash — and it
     * would do so rarely, which is the worst way to find out.
     */
    #[DataProvider('bothImplementations')]
    public function testANonPositiveDurationIsANoOpOnBothHalves(Sleeper $sleeper): void
    {
        $sleeper->sleep(0);
        $sleeper->sleep(-5);

        $this->expectNotToPerformAssertions();
    }

    /**
     * The production half really waits — measured, because a `usleep()` that had been optimized or
     * mis-scaled by a factor of a thousand would leave every other test in this suite green.
     *
     * A deliberately tiny duration with a generous floor: the assertion is "time passed at all",
     * not "the scheduler is precise". A millisecond-for-microsecond mix-up would land at 0.05 ms
     * and fail this; the real 50 ms cannot.
     */
    public function testTheSystemSleeperActuallyWaits(): void
    {
        $before = \hrtime(true);
        (new SystemSleeper())->sleep(50);
        $elapsedMs = (\hrtime(true) - $before) / 1_000_000;

        self::assertGreaterThan(
            25.0,
            $elapsedMs,
            'the one place milliseconds become usleep() microseconds; a factor-of-1000 slip here is '
            . 'invisible to every other assertion in this feature',
        );
    }
}
