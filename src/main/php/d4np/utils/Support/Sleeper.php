<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

/**
 * The seam for *waiting*, which the clock seam does not cover (spec r21 FR-49, RFC-0003; ADR-0066).
 *
 * {@see \Psr\Clock\ClockInterface} answers **what time is it**; nothing in PSR-20 answers **wait a
 * while**. A retry loop needs both, and they are separate capabilities: FR-49's deadline is measured
 * with the clock, and its backoff is spent through this. Injecting only a clock and then calling
 * `usleep()` anyway would leave the sleep unfaked and every retry test would run in real time — the
 * exact outcome ADR-0062 built the clock seam to avoid.
 *
 * Both halves ship, for ADR-0062's reason: a seam whose production half is the only one published
 * makes every project write its own double, and they all write it slightly differently.
 * {@see SystemSleeper} is what production passes; {@see FrozenSleeper} is what tests pass, and it
 * advances a {@see FrozenClock} instead of blocking so the deadline arithmetic is still exercised.
 *
 * Milliseconds, not microseconds, throughout this feature's API — the unit a backoff is configured
 * in. The single conversion to `usleep()`'s microseconds lives in {@see SystemSleeper} and nowhere
 * else, because a duration crossing two units in two places is how one of them ends up a thousand
 * times wrong.
 */
interface Sleeper
{
    /**
     * Waits for `$milliseconds`, or returns immediately for a non-positive value.
     *
     * Non-positive is a no-op rather than an error: full jitter can legitimately draw a zero delay
     * ({@see RetryPolicy::delayFor()}), and a sleeper that refused it would make the jitter
     * distribution's own lower bound a crash.
     */
    public function sleep(int $milliseconds): void;
}
