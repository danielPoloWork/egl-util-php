<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Security;

use D4np\Utils\Security\RateLimitPolicy;
use DateInterval;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec r22 FR-50 (RFC-0003), ADR-0061 §1 / ADR-0067: the two numbers, and what is refused.
 *
 * The assertion worth reading is {@see testTheIntervalRoundsUpSoTheRateIsNeverFasterThanConfigured()}.
 * Rounding a refill interval to the nearest microsecond is invisible; rounding it *down* makes the
 * limiter marginally more permissive than it was told to be, and a security control that errs toward
 * permissive errs in the direction nobody audits.
 */
final class RateLimitPolicyTest extends TestCase
{
    public function testTheWholeBucketOverAWindowIsTheCommonShape(): void
    {
        $policy = RateLimitPolicy::perWindow(5, new DateInterval('PT1M'));

        self::assertSame(5, $policy->capacity());
        self::assertSame(12_000_000, $policy->refillIntervalMicros(), 'five tokens a minute is one every 12 s');
    }

    public function testCapacityAndRefillRateAreIndependent(): void
    {
        // A burst of ten, but only one token back per minute.
        $policy = RateLimitPolicy::of(capacity: 10, refillTokens: 1, per: new DateInterval('PT1M'));

        self::assertSame(10, $policy->capacity());
        self::assertSame(60_000_000, $policy->refillIntervalMicros());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function windows(): iterable
    {
        yield 'a second' => ['PT1S', 1_000_000];
        yield 'a minute' => ['PT1M', 60_000_000];
        yield 'an hour' => ['PT1H', 3_600_000_000];
        yield 'a day' => ['P1D', 86_400_000_000];
    }

    #[DataProvider('windows')]
    public function testAWindowIsMeasuredNotMultipliedOutOfCalendarFields(string $spec, int $expectedMicros): void
    {
        $policy = RateLimitPolicy::of(capacity: 1, refillTokens: 1, per: new DateInterval($spec));

        self::assertSame($expectedMicros, $policy->refillIntervalMicros());
    }

    /**
     * A calendar month has no fixed length, so it is anchored to a constant reference date.
     *
     * Anchoring is what makes `P1M` mean anything here at all — and the reference has to be a
     * constant, or the same policy would become a different one depending on the month it was
     * constructed in.
     */
    public function testACalendarMonthIsAnchoredToAFixedReferenceDate(): void
    {
        $first = RateLimitPolicy::of(capacity: 1, refillTokens: 1, per: new DateInterval('P1M'));
        $second = RateLimitPolicy::of(capacity: 1, refillTokens: 1, per: new DateInterval('P1M'));

        self::assertSame($first->refillIntervalMicros(), $second->refillIntervalMicros());
        self::assertSame(
            31 * 86_400_000_000,
            $first->refillIntervalMicros(),
            'anchored at 2000-01-01, a month is January: 31 days',
        );
    }

    public function testASubSecondWindowIsSupported(): void
    {
        $per = new DateInterval('PT0S');
        $per->f = 0.5;

        $policy = RateLimitPolicy::of(capacity: 1, refillTokens: 1, per: $per);

        self::assertSame(500_000, $policy->refillIntervalMicros());
    }

    /**
     * The direction of the rounding is the decision.
     *
     * Ten tokens over three seconds is 300 000 µs per token exactly; ten over one second is
     * 100 000 exactly. Three tokens over one second is **333 333.33…**, and the choice is whether
     * the effective rate ends up just over or just under what was configured. Up means under —
     * strict — which is the same direction ADR-0061 §5 chose for clock skew.
     */
    public function testTheIntervalRoundsUpSoTheRateIsNeverFasterThanConfigured(): void
    {
        $policy = RateLimitPolicy::of(capacity: 3, refillTokens: 3, per: new DateInterval('PT1S'));

        self::assertSame(
            333_334,
            $policy->refillIntervalMicros(),
            'the exact quotient is 333 333.33…; rounding down to 333 333 would refill three tokens '
            . 'in 999 999 µs — marginally faster than the second that was asked for',
        );
    }

    public function testAnIntervalNeverRoundsBelowOneMicrosecond(): void
    {
        // A million tokens per second would divide to under a microsecond each.
        $policy = RateLimitPolicy::of(capacity: 1, refillTokens: 10_000_000, per: new DateInterval('PT1S'));

        self::assertGreaterThanOrEqual(1, $policy->refillIntervalMicros());
    }

    // -----------------------------------------------------------------------------------------
    // What is refused
    // -----------------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function refusedConfigurations(): iterable
    {
        yield 'zero capacity is a lockout, not a limit' => [0, 1, 'PT1M'];
        yield 'negative capacity' => [-1, 1, 'PT1M'];
        yield 'zero refill tokens never refills' => [1, 0, 'PT1M'];
        yield 'negative refill tokens' => [1, -5, 'PT1M'];
        yield 'a zero window refills instantly' => [1, 1, 'PT0S'];
    }

    #[DataProvider('refusedConfigurations')]
    public function testNonsenseIsRefusedAtConstruction(int $capacity, int $refillTokens, string $window): void
    {
        $this->expectException(InvalidArgumentException::class);

        RateLimitPolicy::of($capacity, $refillTokens, new DateInterval($window));
    }

    /**
     * An inverted window would restore tokens backwards.
     *
     * Its own case rather than a row in the table above, because an inverted `DateInterval` cannot be
     * built from a spec string — it needs the `invert` flag set, which is exactly how a caller
     * computing a window from two dates in the wrong order would produce one.
     */
    public function testAnInvertedWindowIsRefused(): void
    {
        $backwards = new DateInterval('PT1M');
        $backwards->invert = 1;

        $this->expectException(InvalidArgumentException::class);
        RateLimitPolicy::of(capacity: 1, refillTokens: 1, per: $backwards);
    }

    public function testACapacityOfOneIsValid(): void
    {
        self::assertSame(1, RateLimitPolicy::perWindow(1, new DateInterval('PT1M'))->capacity());
    }
}
