<?php

declare(strict_types=1);

namespace D4np\Utils\Security;

use DateInterval;
use InvalidArgumentException;

/**
 * How many attempts a key gets, and how fast they come back (spec r22 FR-50, RFC-0003; ADR-0061 §1,
 * ADR-0067).
 *
 * A token bucket's two numbers: a **capacity** — the burst a key may spend at once — and a **refill
 * interval**, one token per that many microseconds. A readonly value object with named constructors
 * and nonsense refused at construction, the shape {@see \D4np\Utils\Support\RetryPolicy} takes in
 * the same milestone, because they are the same kind of thing: an explicit mechanism a caller states
 * once.
 *
 * **The refill is stored as an interval per token, not as a rate.** That is what keeps every
 * calculation in {@see RateLimiter} exact integer arithmetic — tokens refilled is
 * `intdiv(elapsed, interval)`, with no float anywhere near a security decision. A rate of
 * "0.7 tokens per second" is a float that accumulates error over a long-lived bucket; "one token
 * every 1 428 571 µs" does not.
 *
 * **Division rounds the interval up, never down**, and that direction is the decision. A
 * configuration of "10 attempts per 3 seconds" does not divide evenly; rounding the interval *down*
 * would refill marginally faster than configured, which for a security control is the wrong way to
 * be wrong. Rounding up means the effective rate is at or just under what was asked for — the same
 * degrade-toward-strictness rule ADR-0061 §5 applies to clock skew.
 *
 * ```php
 * $policy = RateLimitPolicy::of(capacity: 5, refillTokens: 5, per: new DateInterval('PT1M'));
 * // five attempts, and the whole bucket back over a minute
 * ```
 */
final class RateLimitPolicy
{
    private const MICROSECONDS_PER_SECOND = 1_000_000;

    /**
     * @param positive-int $capacity
     * @param positive-int $refillIntervalMicros
     */
    private function __construct(
        private readonly int $capacity,
        private readonly int $refillIntervalMicros,
    ) {
    }

    /**
     * `$refillTokens` tokens are restored over `$per`, and the bucket holds at most `$capacity`.
     *
     * Bounds are typed plainly rather than as narrow integer ranges, and the refusals below are the
     * enforcement — the rule ADR-0066 §4 settled: a `positive-int` parameter makes its own `< 1`
     * throw unreachable from type-correct code, and therefore untestable without an analyser
     * suppression this project forbids. The narrow types live on what this class returns.
     *
     * @throws InvalidArgumentException if `$capacity` or `$refillTokens` is below 1, if `$per` is
     *                                 not a positive duration, or if `$per` is inverted — a
     *                                 backwards refill window would mint tokens rather than
     *                                 restore them
     */
    public static function of(int $capacity, int $refillTokens, DateInterval $per): self
    {
        if ($capacity < 1) {
            throw new InvalidArgumentException(\sprintf(
                '$capacity must be >= 1, got %d. A bucket that holds nothing denies every attempt '
                . 'including the first, which is a lockout rather than a rate limit.',
                $capacity,
            ));
        }

        if ($refillTokens < 1) {
            throw new InvalidArgumentException(\sprintf(
                '$refillTokens must be >= 1, got %d. A bucket that never refills denies every key '
                . 'permanently after its first burst, and nothing would say why.',
                $refillTokens,
            ));
        }

        $perMicros = self::microsecondsIn($per);

        if ($perMicros < 1) {
            throw new InvalidArgumentException(\sprintf(
                'The refill window must be a positive duration; got %d microseconds. A zero or '
                . 'inverted window would restore tokens instantly or backwards, and either turns '
                . 'the limit off while still reporting a capacity.',
                $perMicros,
            ));
        }

        // Round the interval UP: a shorter interval refills faster than configured, and erring
        // toward permissive is the wrong direction for a security control (ADR-0061 §5).
        $interval = (int) \ceil($perMicros / $refillTokens);

        return new self($capacity, \max(1, $interval));
    }

    /**
     * The whole bucket is restored over `$per` — the common shape, so it has a name.
     *
     * @throws InvalidArgumentException as {@see of()}
     */
    public static function perWindow(int $capacity, DateInterval $per): self
    {
        return self::of($capacity, $capacity, $per);
    }

    /**
     * @return positive-int
     */
    public function capacity(): int
    {
        return $this->capacity;
    }

    /**
     * Microseconds a single token takes to come back.
     *
     * @return positive-int
     */
    public function refillIntervalMicros(): int
    {
        return $this->refillIntervalMicros;
    }

    /**
     * Whole microseconds in `$interval`, negative when it is inverted.
     *
     * `DateInterval` carries calendar fields that have no fixed length — a month is not a number of
     * seconds — so the conversion is anchored to a fixed reference date and measured, rather than
     * multiplied out of `$interval->m`. Anchoring is what makes `P1M` mean something here at all,
     * and it is why the reference instant is a constant: the same policy must not become a
     * different one depending on when it was constructed.
     */
    private static function microsecondsIn(DateInterval $interval): int
    {
        $reference = new \DateTimeImmutable('2000-01-01 00:00:00', new \DateTimeZone('UTC'));
        $shifted = $reference->add($interval);

        return ($shifted->getTimestamp() - $reference->getTimestamp()) * self::MICROSECONDS_PER_SECOND
            + ((int) $shifted->format('u') - (int) $reference->format('u'));
    }
}
