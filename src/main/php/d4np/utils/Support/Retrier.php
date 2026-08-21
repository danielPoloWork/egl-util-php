<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Runs an operation under a {@see RetryPolicy} (spec r21 FR-49, RFC-0003; ADR-0066).
 *
 * The loop lives here rather than in each caller, which is the point: a policy object that left
 * every consumer to write its own `for` loop would have moved the ad-hoc code rather than replaced
 * it. `HttpClient` and transaction callers consume this **opt-in** — nothing in this library retries
 * on its own, because a library that silently retries has changed a caller's failure semantics
 * without being asked, and a non-idempotent operation retried once is a duplicate write.
 *
 * **Retry is transparent to the caller's error handling.** When the attempts or the deadline run
 * out, the *last* failure is rethrown as it was — not wrapped. A wrapper would force every caller
 * who already catches `HttpClientException` to catch something else and unwrap it, which is a
 * breaking change to their code disguised as a feature. What retrying happened is reported through
 * the optional `$onRetry` observer instead, which also keeps `Support` clear of `Errors`: the caller
 * logs, in the place the decision to log belongs (ADR-0029).
 *
 * **A non-retryable failure propagates immediately**, on the attempt it happened, with no delay
 * spent — the second of the three failure modes FR-49 names.
 *
 * ## The deadline bounds the loop, not an attempt — and that distinction is the point
 *
 * ADR-0049 found that PHP's per-phase stream timeout **re-arms** and therefore bounds no request: a
 * dripping origin outlasts it forever. FR-49 exists because an attempt *count* bounds no retry loop
 * for the same reason — three attempts against a thirty-second hang is a ninety-second call.
 *
 * The honest limit has to be stated in the same breath: **a deadline cannot end an attempt that is
 * already running.** Control is inside the caller's operation, and this class gets it back only when
 * that operation returns or throws. So the deadline is checked before each wait and before each
 * retry, and what it guarantees is that no *new* attempt begins past it. An operation that hangs
 * forever still hangs forever, and bounding that is the operation's own business —
 * `HttpClient`'s wall-clock deadline from ADR-0049 is exactly the tool for it. A deadline here plus
 * an unbounded attempt is the same shape of false comfort ADR-0049 removed, one level up.
 *
 * **A delay that would not fit inside the deadline ends the loop rather than being shortened.**
 * Sleeping the remainder and then attempting leaves the attempt no time to succeed, and shortening
 * the backoff to fit means retrying sooner than the policy says — at precisely the moment the
 * evidence says the dependency is struggling.
 *
 * ```php
 * $retrier = new Retrier($policy, new SystemClock(), new SystemSleeper());
 * $response = $retrier->run(fn () => $client->get($url));
 * ```
 *
 * In tests, {@see FrozenSleeper} advances the {@see FrozenClock} it shares with this class, so the
 * deadline arithmetic runs for real while nothing waits.
 */
final class Retrier
{
    public function __construct(
        private readonly RetryPolicy $policy,
        private readonly ClockInterface $clock,
        private readonly Sleeper $sleeper,
    ) {
    }

    /**
     * Runs `$operation`, retrying per the policy.
     *
     * @template T
     *
     * @param callable(int): T                    $operation receives the 1-based attempt number
     * @param (callable(Throwable, int, int): void)|null $onRetry  receives the failure, the attempt
     *                                                            that just failed, and the delay in
     *                                                            milliseconds about to be waited
     *
     * @return T
     *
     * @throws Throwable the last failure, unwrapped, when the attempts or the deadline are spent;
     *                   or the first non-retryable failure, immediately
     */
    public function run(callable $operation, ?callable $onRetry = null): mixed
    {
        $startedAt = $this->clock->now();
        $deadlineMs = $this->policy->deadlineMs();
        $attempt = 1;

        while (true) {
            try {
                return $operation($attempt);
            } catch (Throwable $failure) {
                if (!$this->policy->isRetryable($failure) || $attempt >= $this->policy->maxAttempts()) {
                    throw $failure;
                }

                $delayMs = $this->policy->delayFor($attempt + 1);

                if ($deadlineMs !== null && $this->elapsedMsSince($startedAt) + $delayMs >= $deadlineMs) {
                    throw $failure;
                }

                if ($onRetry !== null) {
                    $onRetry($failure, $attempt, $delayMs);
                }

                $this->sleeper->sleep($delayMs);
                $attempt++;
            }
        }
    }

    /**
     * Milliseconds elapsed since `$startedAt`, by the injected clock.
     *
     * Integer microsecond arithmetic rather than a float from `format('U.u')`: a Unix timestamp with
     * six fractional digits needs sixteen significant digits, which is at the edge of what a
     * `float` carries exactly. `getTimestamp() * 1_000_000` peaks around `1.8e15` for dates this
     * code will see, comfortably inside `PHP_INT_MAX`.
     */
    private function elapsedMsSince(DateTimeImmutable $startedAt): int
    {
        return \intdiv(self::microsecondsOf($this->clock->now()) - self::microsecondsOf($startedAt), 1000);
    }

    private static function microsecondsOf(DateTimeImmutable $instant): int
    {
        return $instant->getTimestamp() * 1_000_000 + (int) $instant->format('u');
    }
}
