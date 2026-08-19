<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\FrozenClock;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * `FrozenClock` — spec FR-45 (RFC-0003), the shipped test double for the time seam (ADR-0062).
 *
 * The contract worth pinning is the *absence* of wall-clock behaviour: the instant never moves
 * unless `advance()` moves it, `advance()` is cumulative, and an inverted interval moves time
 * backward — the clock-skew scenario ADR-0061's refill clamp is tested against.
 */
final class FrozenClockTest extends TestCase
{
    private const FROZEN_AT = '2026-02-01T10:30:00.250000+00:00';

    private function make(): FrozenClock
    {
        return new FrozenClock(new DateTimeImmutable(self::FROZEN_AT));
    }

    public function testImplementsThePsr20Contract(): void
    {
        self::assertInstanceOf(ClockInterface::class, $this->make());
    }

    public function testNowReturnsTheConstructedInstant(): void
    {
        $now = $this->make()->now();

        self::assertSame(self::FROZEN_AT, $now->format('Y-m-d\TH:i:s.uP'));
    }

    public function testRepeatedCallsReturnTheSameInstant(): void
    {
        $clock = $this->make();

        // A frozen clock does not tick between reads — that is its entire promise, and the
        // property every deterministic time-dependent test builds on.
        self::assertEquals($clock->now(), $clock->now());
        self::assertSame(
            $clock->now()->format('Y-m-d\TH:i:s.uP'),
            $clock->now()->format('Y-m-d\TH:i:s.uP'),
        );
    }

    public function testAdvanceMovesTheInstantForward(): void
    {
        $clock = $this->make();

        $clock->advance(new DateInterval('PT90S'));

        self::assertSame('2026-02-01T10:31:30.250000+00:00', $clock->now()->format('Y-m-d\TH:i:s.uP'));
    }

    public function testAdvanceIsCumulative(): void
    {
        $clock = $this->make();

        $clock->advance(new DateInterval('PT1M'));
        $clock->advance(new DateInterval('PT1M'));

        // Two advances stack; an implementation re-applying each interval to the ORIGINAL
        // instant would read 10:31:00 here and pass the single-advance test above.
        self::assertSame('2026-02-01T10:32:00.250000+00:00', $clock->now()->format('Y-m-d\TH:i:s.uP'));
    }

    public function testAnInvertedIntervalMovesTimeBackward(): void
    {
        $clock = $this->make();

        $backward = new DateInterval('PT10S');
        $backward->invert = 1;
        $clock->advance($backward);

        // Backward time is a first-class scenario, not an error: a clock-skew test simulates a
        // node whose clock runs behind the state it reads by doing exactly this (ADR-0061 §5).
        self::assertSame('2026-02-01T10:29:50.250000+00:00', $clock->now()->format('Y-m-d\TH:i:s.uP'));
    }

    public function testAdvanceMutatesTheInjectedReference(): void
    {
        // The double is DELIBERATELY mutable (ADR-0062): the holder advances the clock while the
        // code under test keeps the same injected reference. This is the seam's whole mechanism —
        // an immutable advance() would return a clock nothing under test holds.
        $clock = $this->make();
        $sameReference = $clock;

        $clock->advance(new DateInterval('PT1H'));

        self::assertSame(
            '2026-02-01T11:30:00.250000+00:00',
            $sameReference->now()->format('Y-m-d\TH:i:s.uP'),
        );
    }

    public function testMicrosecondsSurviveConstructionAndAdvance(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-02-01T00:00:00.123456+00:00'));

        $clock->advance(new DateInterval('PT1S'));

        self::assertSame('0.123456', $clock->now()->format('0.u'));
    }

    public function testTheHeldInstantIsIndependentOfTheWallClock(): void
    {
        // Frozen means frozen: the instant is the constructed one, not "now, remembered" —
        // asserted against a date far from any test run's actual time.
        self::assertSame('2026-02-01', $this->make()->now()->format('Y-m-d'));
    }
}
