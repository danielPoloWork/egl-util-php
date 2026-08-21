<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\HttpClientException;
use D4np\Utils\Support\RetryPolicy;
use D4np\Utils\Support\UtilsException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec r21 FR-49 (RFC-0003), ADR-0066: the policy half — decisions only, no clock and no sleeping.
 *
 * The assertion that matters most here is {@see testJitterActuallyVaries()} together with
 * {@see testThereIsNoWayToTurnJitterOff()}. Every other delay test has the form "the value is
 * between 0 and the ceiling", and an implementation that dropped the jitter and returned the plain
 * exponential satisfies all of them — so those two are what stand between the requirement and the
 * synchronized-retry outage it exists to prevent.
 */
final class RetryPolicyTest extends TestCase
{
    private static function policy(
        int $maxAttempts = 4,
        int $baseDelayMs = 100,
        ?int $deadlineMs = null,
        float $multiplier = 2.0,
        ?int $maxDelayMs = null,
    ): RetryPolicy {
        return RetryPolicy::of(
            maxAttempts: $maxAttempts,
            baseDelayMs: $baseDelayMs,
            retryable: [HttpClientException::class],
            deadlineMs: $deadlineMs,
            multiplier: $multiplier,
            maxDelayMs: $maxDelayMs,
        );
    }

    // -----------------------------------------------------------------------------------------
    // Construction refuses rather than clamps
    // -----------------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{int, int, int|null, float, int|null}>
     */
    public static function refusedConfigurations(): iterable
    {
        //                                                     attempts, base, deadline, mult, maxDelay
        yield 'zero attempts' =>                                     [0, 100, null, 2.0, null];
        yield 'negative attempts' =>                                [-1, 100, null, 2.0, null];
        yield 'negative base delay' =>                             [4,   -1, null, 2.0, null];
        yield 'a multiplier below one shrinks the backoff' =>       [4,  100, null, 0.5, null];
        yield 'a ceiling under the floor flattens the backoff' =>   [4,  100, null, 2.0,   50];
        yield 'a zero deadline is not "no deadline"' =>             [4,  100,    0, 2.0, null];
        yield 'a negative deadline' =>                              [4, 100,   -5, 2.0, null];
    }

    #[DataProvider('refusedConfigurations')]
    public function testAnOutOfRangeBoundIsRefused(
        int $maxAttempts,
        int $baseDelayMs,
        ?int $deadlineMs,
        float $multiplier,
        ?int $maxDelayMs,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        RetryPolicy::of(
            maxAttempts: $maxAttempts,
            baseDelayMs: $baseDelayMs,
            retryable: [HttpClientException::class],
            deadlineMs: $deadlineMs,
            multiplier: $multiplier,
            maxDelayMs: $maxDelayMs,
        );
    }

    public function testAnEmptyRetryableAllowlistIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RetryPolicy::of(maxAttempts: 3, baseDelayMs: 10, retryable: []);
    }

    public function testANonThrowableInTheAllowlistIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RetryPolicy::of(maxAttempts: 3, baseDelayMs: 10, retryable: [RetryPolicy::class]);
    }

    public function testOneAttemptIsAValidNeverRetryPolicy(): void
    {
        self::assertSame(1, self::policy(maxAttempts: 1)->maxAttempts());
    }

    public function testAZeroBaseDelayIsAValidImmediateRetryPolicy(): void
    {
        self::assertSame(0, self::policy(baseDelayMs: 0)->ceilingFor(2));
    }

    public function testNoDeadlineIsSpeltNull(): void
    {
        self::assertNull(self::policy()->deadlineMs());
        self::assertSame(5000, self::policy(deadlineMs: 5000)->deadlineMs());
    }

    // -----------------------------------------------------------------------------------------
    // Which failures earn another attempt
    // -----------------------------------------------------------------------------------------

    public function testAnAllowlistedExceptionIsRetryable(): void
    {
        self::assertTrue(self::policy()->isRetryable(new HttpClientException('timeout')));
    }

    public function testAnUnlistedExceptionIsNotRetryable(): void
    {
        self::assertFalse(self::policy()->isRetryable(new \RuntimeException('nope')));
    }

    public function testNamingAParentTypeCoversItsSubclasses(): void
    {
        $policy = RetryPolicy::of(maxAttempts: 3, baseDelayMs: 10, retryable: [UtilsException::class]);

        self::assertTrue(
            $policy->isRetryable(new HttpClientException('a descendant')),
            'instanceof, not a class-name comparison — which is why UtilsException in the list '
            . 'retries the whole library hierarchy and is almost never what a caller means',
        );
    }

    // -----------------------------------------------------------------------------------------
    // The exponential ceiling the jitter is drawn against
    // -----------------------------------------------------------------------------------------

    public function testTheFirstRetryPaysTheBaseDelay(): void
    {
        self::assertSame(100, self::policy(baseDelayMs: 100)->ceilingFor(2));
    }

    public function testTheCeilingGrowsByTheMultiplierPerAttempt(): void
    {
        $policy = self::policy(baseDelayMs: 100, multiplier: 2.0, maxDelayMs: 100_000);

        self::assertSame([100, 200, 400, 800], [
            $policy->ceilingFor(2),
            $policy->ceilingFor(3),
            $policy->ceilingFor(4),
            $policy->ceilingFor(5),
        ]);
    }

    public function testAMultiplierOfOneIsAConstantBackoff(): void
    {
        $policy = self::policy(baseDelayMs: 250, multiplier: 1.0);

        self::assertSame([250, 250, 250], [
            $policy->ceilingFor(2),
            $policy->ceilingFor(3),
            $policy->ceilingFor(4),
        ]);
    }

    public function testTheCeilingIsClampedToMaxDelay(): void
    {
        $policy = self::policy(baseDelayMs: 100, multiplier: 10.0, maxDelayMs: 500);

        self::assertSame([100, 500, 500], [
            $policy->ceilingFor(2),
            $policy->ceilingFor(3),
            $policy->ceilingFor(4),
        ]);
    }

    public function testTheDefaultCeilingIsThirtyTimesTheBase(): void
    {
        $policy = self::policy(baseDelayMs: 100, multiplier: 100.0);

        self::assertSame(3000, $policy->ceilingFor(3));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function attemptsWithNothingBeforeThem(): iterable
    {
        yield 'the first attempt waits for nothing' => [1];
        yield 'zero is not an attempt' => [0];
        yield 'a negative attempt' => [-3];
    }

    #[DataProvider('attemptsWithNothingBeforeThem')]
    public function testADelayBeforeTheFirstAttemptIsRefused(int $attempt): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::policy()->delayFor($attempt);
    }

    // -----------------------------------------------------------------------------------------
    // Jitter — the requirement that no single-value assertion can defend
    // -----------------------------------------------------------------------------------------

    public function testEveryDelayFallsInsideTheFullJitterBand(): void
    {
        $policy = self::policy(baseDelayMs: 100, maxDelayMs: 100_000);

        for ($attempt = 2; $attempt <= 6; $attempt++) {
            $ceiling = $policy->ceilingFor($attempt);

            for ($draw = 0; $draw < 50; $draw++) {
                $delay = $policy->delayFor($attempt);
                self::assertGreaterThanOrEqual(0, $delay);
                self::assertLessThanOrEqual($ceiling, $delay);
            }
        }
    }

    /**
     * Jitter is present, not merely permitted by the band.
     *
     * An implementation returning the un-jittered exponential passes
     * {@see testEveryDelayFallsInsideTheFullJitterBand()} — the plain value is inside its own band.
     * This is the assertion that fails for it.
     *
     * Statistical, and bounded rather than hoped: 300 draws from `[0, 1600]`. A correct
     * implementation collapsing to one distinct value has probability `(1/1601)^299`, which is not a
     * flake anyone will ever see. A jitterless one yields exactly one value, every run.
     */
    public function testJitterActuallyVaries(): void
    {
        $policy = self::policy(baseDelayMs: 100, maxDelayMs: 100_000);

        $seen = [];
        for ($draw = 0; $draw < 300; $draw++) {
            $seen[$policy->delayFor(6)] = true;
        }

        self::assertGreaterThan(
            1,
            \count($seen),
            'delayFor() returned one value across 300 draws: the jitter is gone, and N clients that '
            . 'failed together will retry together',
        );
    }

    public function testTheJitterSpansMostOfTheBand(): void
    {
        $policy = self::policy(baseDelayMs: 100, maxDelayMs: 100_000);
        $ceiling = $policy->ceilingFor(6);

        $lowest = $ceiling;
        $highest = 0;
        for ($draw = 0; $draw < 300; $draw++) {
            $delay = $policy->delayFor(6);
            $lowest = \min($lowest, $delay);
            $highest = \max($highest, $delay);
        }

        self::assertLessThan(
            (int) ($ceiling * 0.25),
            $lowest,
            'full jitter draws from zero upward; a distribution clustered near the ceiling is '
            . '"equal jitter" or worse, and decorrelates less',
        );
        self::assertGreaterThan((int) ($ceiling * 0.75), $highest);
    }

    /**
     * There is no argument that disables the jitter — a **mechanism** assertion (ADR-0027).
     *
     * Behaviour cannot see the difference between "this build has jitter on" and "this build has no
     * switch at all", which is exactly what the requirement asks for: jitter is part of the
     * requirement, not a flag. A `jitter: false` reachable from a constructor would reintroduce the
     * failure mode on any deployment that set it.
     */
    public function testThereIsNoWayToTurnJitterOff(): void
    {
        $parameters = [];
        foreach ((new \ReflectionMethod(RetryPolicy::class, 'of'))->getParameters() as $parameter) {
            $parameters[] = \strtolower($parameter->getName());
        }

        foreach ($parameters as $name) {
            self::assertStringNotContainsString(
                'jitter',
                $name,
                'no named constructor argument may control the jitter',
            );
            self::assertStringNotContainsString('random', $name);
        }

        $source = self::sourceOf('delayFor');
        self::assertStringContainsString(
            'random_int(0, $this->ceilingFor($attempt))',
            $source,
            'the delay must be drawn over the whole band on every call, with no branch that can '
            . 'return the un-jittered ceiling instead',
        );
        self::assertDoesNotMatchRegularExpression(
            '/\bif\b/',
            $source,
            'delayFor() must contain no conditional at all: a branch is where a jitter bypass would '
            . 'live, and the range check belongs to ceilingFor()',
        );
    }

    private static function sourceOf(string $method): string
    {
        $reflected = new \ReflectionMethod(RetryPolicy::class, $method);
        $lines = \file((string) $reflected->getFileName());
        self::assertIsArray($lines);

        return \implode('', \array_slice(
            $lines,
            $reflected->getStartLine() - 1,
            $reflected->getEndLine() - $reflected->getStartLine() + 1,
        ));
    }
}
