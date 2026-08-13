<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\SystemClock;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * `SystemClock` — spec FR-45 (RFC-0003), the production half of the time seam (ADR-0062).
 *
 * A wall clock is mostly untestable by definition — the suite pins what *is* decidable: the PSR
 * contract, freshness (no caching), agreement with the system time it claims to read, and the
 * timezone rule (PHP's default unless one was injected).
 */
final class SystemClockTest extends TestCase
{
    public function testImplementsThePsr20Contract(): void
    {
        self::assertInstanceOf(ClockInterface::class, new SystemClock());
    }

    public function testNowAgreesWithTheSystemTime(): void
    {
        $now = (new SystemClock())->now();

        // Two seconds of tolerance: enough for any scheduler hiccup between the two reads,
        // narrow enough that a clock reading anything but the system time still fails.
        self::assertLessThanOrEqual(2, \abs($now->getTimestamp() - \time()));
    }

    public function testEachCallReturnsAFreshInstance(): void
    {
        $clock = new SystemClock();

        // Distinct objects, not one cached instant handed out twice: a caller mutating nothing
        // must still see time move across calls, which a memoized instant would prevent.
        self::assertNotSame($clock->now(), $clock->now());
    }

    public function testTimeDoesNotRunBackwardAcrossCalls(): void
    {
        $clock = new SystemClock();

        $first = $clock->now();
        $second = $clock->now();

        self::assertGreaterThanOrEqual($first->getTimestamp(), $second->getTimestamp());
    }

    public function testDefaultTimezoneIsPhpsDefault(): void
    {
        $now = (new SystemClock())->now();

        self::assertSame(\date_default_timezone_get(), $now->getTimezone()->getName());
    }

    public function testAnInjectedTimezoneIsHonoured(): void
    {
        $utc = (new SystemClock(new DateTimeZone('UTC')))->now();
        $rome = (new SystemClock(new DateTimeZone('Europe/Rome')))->now();

        self::assertSame('UTC', $utc->getTimezone()->getName());
        self::assertSame('Europe/Rome', $rome->getTimezone()->getName());
    }

    public function testTheTimezoneChangesTheLabelNotTheInstant(): void
    {
        // Instant arithmetic is timezone-independent — the property every in-library consumer
        // of the seam relies on (ADR-0062). Two clocks in different zones read the same moment.
        $utc = (new SystemClock(new DateTimeZone('UTC')))->now();
        $rome = (new SystemClock(new DateTimeZone('Europe/Rome')))->now();

        self::assertLessThanOrEqual(2, \abs($utc->getTimestamp() - $rome->getTimestamp()));
    }

    public function testNowReturnsDateTimeImmutable(): void
    {
        // The PSR-20 return type is the contract; pinning it here means a future edit cannot
        // quietly widen it to DateTimeInterface and hand callers a mutable DateTime.
        self::assertInstanceOf(DateTimeImmutable::class, (new SystemClock())->now());
    }
}
