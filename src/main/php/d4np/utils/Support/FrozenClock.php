<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

use DateInterval;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * The test double for the time seam (spec FR-45, RFC-0003; ADR-0062).
 *
 * Holds the instant it was constructed with and returns it from every `now()` call until
 * {@see advance()} moves it. Shipped in `src/main` rather than left for consumers to write,
 * because the seam's value is that nobody re-implements this class per project — it is to
 * {@see SystemClock} what a recording transport is to a real one.
 *
 * **Deliberately mutable** — the one class in this library whose job is controlled mutation.
 * The holder of the clock advances it while the code under test keeps the same injected
 * reference; an immutable `advance()` would return a new clock that nothing under test holds.
 *
 * `advance()` honours an **inverted** `DateInterval` (one with `invert = 1`, or built from a
 * negative date string): time moving backward is a first-class test scenario, not an error —
 * clock-skew suites simulate a node whose clock runs behind the state it reads by doing exactly
 * this (ADR-0061 §5, the rate limiter's refill clamp).
 */
final class FrozenClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    /**
     * Move the frozen instant by `$interval` — forward, or backward when the interval is
     * inverted. Cumulative across calls.
     */
    public function advance(DateInterval $interval): void
    {
        $this->now = $this->now->add($interval);
    }
}
