<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\FrozenClock;
use D4np\Utils\Support\FrozenSleeper;
use D4np\Utils\Support\HttpClientException;
use D4np\Utils\Support\Retrier;
use D4np\Utils\Support\RetryPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Spec r21 FR-49 (RFC-0003), ADR-0066: the loop, and the deadline that bounds it.
 *
 * **No test here sleeps, and every test here exercises the deadline for real.** That is the point of
 * pairing {@see FrozenSleeper} with {@see FrozenClock}: the sleeper advances the clock by exactly
 * what it was asked to wait, so wall-clock arithmetic runs while nothing blocks. A double that only
 * recorded the request would leave time frozen and the deadline unreachable.
 *
 * Full jitter makes delays random, which would make a deadline assertion flaky. So the deadline
 * tests use `baseDelayMs: 0` — a band of `[0, 0]` is deterministically zero — and move time from the
 * **operation** instead. That keeps the arithmetic exact without pretending the jitter is not there.
 */
final class RetrierTest extends TestCase
{
    private FrozenClock $clock;

    private FrozenSleeper $sleeper;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-08-21 12:00:00'));
        $this->sleeper = new FrozenSleeper($this->clock);
    }

    private function retrier(RetryPolicy $policy): Retrier
    {
        return new Retrier($policy, $this->clock, $this->sleeper);
    }

    /**
     * @param list<class-string> $retryable
     */
    private static function policy(
        int $maxAttempts = 4,
        int $baseDelayMs = 0,
        ?int $deadlineMs = null,
        array $retryable = [HttpClientException::class],
    ): RetryPolicy {
        return RetryPolicy::of(
            maxAttempts: $maxAttempts,
            baseDelayMs: $baseDelayMs,
            retryable: $retryable,
            deadlineMs: $deadlineMs,
        );
    }

    /**
     * Moves the frozen clock on by `$milliseconds`, standing in for time the operation itself spent.
     */
    private function burn(int $milliseconds): void
    {
        $advance = new \DateInterval('PT' . \intdiv($milliseconds, 1000) . 'S');
        $advance->f = ($milliseconds % 1000) / 1000;
        $this->clock->advance($advance);
    }

    // -----------------------------------------------------------------------------------------
    // The happy paths
    // -----------------------------------------------------------------------------------------

    public function testASucceedingOperationRunsOnceAndNeverSleeps(): void
    {
        $calls = 0;

        $result = $this->retrier(self::policy())->run(function () use (&$calls) {
            $calls++;

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(1, $calls);
        self::assertSame([], $this->sleeper->requested());
    }

    public function testTheOperationReceivesTheOneBasedAttemptNumber(): void
    {
        $seen = [];

        $this->retrier(self::policy())->run(function (int $attempt) use (&$seen) {
            $seen[] = $attempt;

            if ($attempt < 3) {
                throw new HttpClientException('not yet');
            }

            return 'done';
        });

        self::assertSame([1, 2, 3], $seen);
    }

    public function testItRetriesUntilTheOperationSucceeds(): void
    {
        $calls = 0;

        $result = $this->retrier(self::policy(maxAttempts: 5))->run(function () use (&$calls) {
            $calls++;

            if ($calls < 4) {
                throw new HttpClientException('flaky');
            }

            return 'eventually';
        });

        self::assertSame('eventually', $result);
        self::assertSame(4, $calls);
        self::assertCount(3, $this->sleeper->requested(), 'one wait between each pair of attempts');
    }

    public function testAValueOfAnyTypeIsReturnedUnchanged(): void
    {
        $payload = ['rows' => [1, 2, 3]];

        self::assertSame($payload, $this->retrier(self::policy())->run(fn () => $payload));
    }

    // -----------------------------------------------------------------------------------------
    // Failure: transparent to the caller's error handling
    // -----------------------------------------------------------------------------------------

    public function testWhenTheAttemptsRunOutTheLastFailureIsRethrownUnwrapped(): void
    {
        $last = new HttpClientException('the final one');
        $calls = 0;

        try {
            $this->retrier(self::policy(maxAttempts: 3))->run(function () use (&$calls, $last) {
                $calls++;

                throw $calls < 3 ? new HttpClientException('an earlier one') : $last;
            });
        } catch (HttpClientException $thrown) {
            self::assertSame(
                $last,
                $thrown,
                'the same instance, not a wrapper: a caller already catching HttpClientException '
                . 'must not have to catch something else and unwrap it',
            );
        }

        self::assertSame(3, $calls);
    }

    public function testANonRetryableFailurePropagatesImmediatelyWithNoDelaySpent(): void
    {
        $calls = 0;

        try {
            $this->retrier(self::policy(maxAttempts: 5))->run(function () use (&$calls) {
                $calls++;

                throw new RuntimeException('a 400 will not become a 200');
            });
        } catch (RuntimeException $thrown) {
            self::assertSame('a 400 will not become a 200', $thrown->getMessage());
        }

        self::assertSame(1, $calls, 'the second of the three failure modes FR-49 names');
        self::assertSame([], $this->sleeper->requested(), 'and not a millisecond wasted on it');
    }

    public function testASinglePermittedAttemptNeverRetries(): void
    {
        $calls = 0;

        try {
            $this->retrier(self::policy(maxAttempts: 1))->run(function () use (&$calls) {
                $calls++;

                throw new HttpClientException('once is all you get');
            });
        } catch (HttpClientException) {
            // expected
        }

        self::assertSame(1, $calls);
        self::assertSame([], $this->sleeper->requested());
    }

    // -----------------------------------------------------------------------------------------
    // The deadline — the part attempt-count cannot do
    // -----------------------------------------------------------------------------------------

    public function testTheDeadlineStopsTheLoopBeforeTheAttemptsAreSpent(): void
    {
        $calls = 0;

        try {
            // Ten attempts permitted; the operation burns 100 ms each time; 250 ms allowed in total.
            $this->retrier(self::policy(maxAttempts: 10, deadlineMs: 250))->run(function () use (&$calls) {
                $calls++;
                $this->burn(100);

                throw new HttpClientException('slow and failing');
            });
        } catch (HttpClientException) {
            // expected
        }

        self::assertSame(
            3,
            $calls,
            'attempts at elapsed 100 and 200 ms are inside the budget; the third puts elapsed at '
            . '300 and there is no room for a fourth',
        );
    }

    public function testWithoutADeadlineTheLoopRunsToTheAttemptLimit(): void
    {
        $calls = 0;

        try {
            $this->retrier(self::policy(maxAttempts: 6, deadlineMs: null))->run(function () use (&$calls) {
                $calls++;
                $this->burn(10_000);

                throw new HttpClientException('slow and failing');
            });
        } catch (HttpClientException) {
            // expected
        }

        self::assertSame(6, $calls, 'a minute of wall clock, and nothing stopped it — which is why '
            . 'the deadline is not optional in the requirement');
    }

    /**
     * The loop ends rather than shortening a wait that will not fit.
     *
     * Sleeping only the remaining budget and attempting anyway leaves the attempt no time to
     * succeed, and shortening the backoff means retrying sooner than the policy says at exactly the
     * moment the evidence says the dependency is struggling. Observable here as the **absence** of a
     * final recorded sleep: three attempts, two waits.
     */
    public function testADelayThatWouldNotFitEndsTheLoopInsteadOfBeingShortened(): void
    {
        $calls = 0;

        try {
            $this->retrier(self::policy(maxAttempts: 10, deadlineMs: 250))->run(function () use (&$calls) {
                $calls++;
                $this->burn(100);

                throw new HttpClientException('slow and failing');
            });
        } catch (HttpClientException) {
            // expected
        }

        self::assertSame(3, $calls);
        self::assertCount(
            2,
            $this->sleeper->requested(),
            'no wait is recorded for the step that gave up: the loop stopped, it did not sleep a '
            . 'clamped remainder first',
        );
    }

    public function testTheDeadlineIsMeasuredFromTheFirstAttemptNotPerAttempt(): void
    {
        $calls = 0;

        try {
            // A single attempt already overshoots: nothing may follow it.
            $this->retrier(self::policy(maxAttempts: 10, deadlineMs: 100))->run(function () use (&$calls) {
                $calls++;
                $this->burn(500);

                throw new HttpClientException('one very slow call');
            });
        } catch (HttpClientException) {
            // expected
        }

        self::assertSame(1, $calls);
    }

    /**
     * The deadline can be spent by the **backoff itself**, not only by slow attempts.
     *
     * Every other deadline test here moves time from inside the operation, which means none of them
     * depends on {@see FrozenSleeper} advancing the clock — planting away that advance leaves them
     * all green. This is the case that does: the operation fails instantly, so the only thing that
     * can move the clock is the waiting, and a fast-failing dependency behind a generous attempt
     * count is exactly how a retry loop runs long without any single attempt being slow.
     *
     * The assertion is exact rather than approximate. With nothing else consuming time, elapsed
     * wall clock must equal the sum of the recorded waits to the millisecond — which is the coupling
     * between the two doubles, asserted from the outside.
     */
    public function testTheDeadlineCanBeSpentEntirelyOnBackoff(): void
    {
        $startedAt = $this->clock->now();
        $calls = 0;

        try {
            $this->retrier(RetryPolicy::of(
                maxAttempts: 500,
                baseDelayMs: 200,
                retryable: [HttpClientException::class],
                deadlineMs: 2_000,
                multiplier: 1.0,
                maxDelayMs: 200,
            ))->run(function () use (&$calls) {
                $calls++;

                throw new HttpClientException('fails instantly, every time');
            });
        } catch (HttpClientException) {
            // expected
        }

        self::assertLessThan(
            500,
            $calls,
            'the deadline must be what stopped this, not the attempt count — otherwise the test is '
            . 'measuring the wrong bound',
        );
        self::assertGreaterThan(0, $this->sleeper->total(), 'waiting is the only thing spending the budget here');

        $elapsedMs = \intdiv(
            $this->clock->now()->getTimestamp() * 1_000_000 + (int) $this->clock->now()->format('u')
            - ($startedAt->getTimestamp() * 1_000_000 + (int) $startedAt->format('u')),
            1000,
        );

        self::assertSame(
            $this->sleeper->total(),
            $elapsedMs,
            'the clock moved by exactly the recorded waits: that is FrozenSleeper advancing '
            . 'FrozenClock, and it is what makes every deadline assertion in this file non-vacuous',
        );
    }

    // -----------------------------------------------------------------------------------------
    // The observer, which is how a caller learns retrying happened
    // -----------------------------------------------------------------------------------------

    public function testTheObserverSeesEveryRetryWithItsFailureAttemptAndDelay(): void
    {
        $observed = [];

        $this->retrier(self::policy(maxAttempts: 4))->run(
            function (int $attempt) {
                if ($attempt < 3) {
                    throw new HttpClientException('attempt ' . $attempt . ' failed');
                }

                return 'ok';
            },
            function (\Throwable $failure, int $attempt, int $delayMs) use (&$observed) {
                $observed[] = [$failure->getMessage(), $attempt, $delayMs];
            },
        );

        self::assertSame(
            [['attempt 1 failed', 1, 0], ['attempt 2 failed', 2, 0]],
            $observed,
            'the attempt that just failed, and the delay about to be waited',
        );
    }

    public function testTheObserverIsNotCalledWhenNothingIsRetried(): void
    {
        $called = false;

        $this->retrier(self::policy())->run(
            fn () => 'first time lucky',
            function () use (&$called): void {
                $called = true;
            },
        );

        self::assertFalse($called);
    }

    public function testTheObserverIsNotCalledForTheFailureThatEndsTheLoop(): void
    {
        $observed = 0;

        try {
            $this->retrier(self::policy(maxAttempts: 3))->run(
                function (): never {
                    throw new HttpClientException('always');
                },
                function () use (&$observed): void {
                    $observed++;
                },
            );
        } catch (HttpClientException) {
            // expected
        }

        self::assertSame(
            2,
            $observed,
            'the observer reports waits, and no wait follows the last failure — the exception is '
            . 'the report for that one',
        );
    }

    // -----------------------------------------------------------------------------------------
    // Mechanism (ADR-0027): the sleep duration cannot be derived from the remaining budget
    // -----------------------------------------------------------------------------------------

    /**
     * The wait handed to the sleeper is the policy's, never arithmetic over the deadline.
     *
     * `testADelayThatWouldNotFitEndsTheLoopInsteadOfBeingShortened()` sees the absence of one sleep,
     * but it cannot see *why*: an implementation that clamped the delay to the remaining budget and
     * then happened to stop for another reason would satisfy it. This pins the mechanism — there is
     * exactly one expression that becomes a sleep, and it comes from `delayFor()`.
     */
    public function testTheWaitIsThePolicysAndNotAClampedRemainder(): void
    {
        $source = self::sourceOfRun();

        self::assertStringContainsString('$delayMs = $this->policy->delayFor(', $source);
        self::assertStringContainsString('$this->sleeper->sleep($delayMs)', $source);
        self::assertDoesNotMatchRegularExpression(
            '/sleep\(\s*(?!\$delayMs\s*\))/',
            $source,
            'the sleeper must be handed the policy\'s delay and nothing else — a min(), a subtraction '
            . 'from the deadline, or any other expression here is the clamp this must not do',
        );
        self::assertDoesNotMatchRegularExpression(
            '/\$delayMs\s*=(?!\s*\$this->policy->delayFor\()/',
            $source,
            '$delayMs may be assigned once, from the policy',
        );
    }

    private static function sourceOfRun(): string
    {
        $reflected = new \ReflectionMethod(Retrier::class, 'run');
        $lines = \file((string) $reflected->getFileName());
        self::assertIsArray($lines);

        return \implode('', \array_slice(
            $lines,
            $reflected->getStartLine() - 1,
            $reflected->getEndLine() - $reflected->getStartLine() + 1,
        ));
    }
}
