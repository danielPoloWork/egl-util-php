<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

use InvalidArgumentException;
use Throwable;

/**
 * What to retry, how many times, how long to wait, and when to stop trying at all (spec r21 FR-49,
 * RFC-0003; ADR-0066).
 *
 * Retry-with-backoff is ad-hoc per-project code of exactly the class this library exists to replace,
 * and the 2026-08-09 review board named the three ways it is usually wrong: **no jitter** (every
 * client that failed together retries together, and the retry storm is the outage), **retrying
 * non-retryable failures** (a `400` is not going to become a `200`), and **unbounded total time**.
 * This object is the shape that makes each of those three a decision someone had to make rather than
 * a default nobody noticed.
 *
 * A pure value object: it decides, it does not act. It never sleeps, never reads a clock, and holds
 * no infrastructure — {@see Retrier} is what executes under it. That split is deliberate, so a
 * policy stays a configurable *value* a deployment can build once and pass around, rather than a
 * service carrying a clock and a sleeper.
 *
 * **Jitter is not a flag.** {@see delayFor()} always randomizes, and there is no constructor
 * argument that turns it off. The requirement says so, and it has to be enforced structurally
 * because behaviour cannot see it: an implementation that returned the un-jittered exponential delay
 * satisfies every assertion of the form "the delay is between X and Y". What catches its absence is
 * a distribution test plus a mechanism assertion, both in the suite.
 *
 * **Full jitter**, `random_int(0, min(maxDelay, base × multiplier^(attempt-1)))`, rather than the
 * "equal jitter" variant that keeps half the delay fixed. Full jitter decorrelates maximally, which
 * is the entire point of having jitter; the cost is honest and stated — a draw can legitimately come
 * back at or near zero, so a single attempt may retry almost immediately. Bounding *that* is the
 * deadline's job and the attempt count's, not the delay's.
 *
 * **The retryable allowlist may not be empty.** A policy that retries nothing is not a
 * configuration, it is a mistake that presents as "retries silently never happen" — the same reason
 * FR-35 refuses empty criteria rather than treating them as "match everything".
 *
 * **Non-goal, stated because its absence is a design choice:** there is no circuit breaker here. A
 * breaker is shared state across calls with its own half-open probing and failure window; this is a
 * per-operation policy. Wiring one in would make every `Retrier` a stateful service and is a
 * separate feature, not a parameter.
 *
 * ```php
 * $policy = RetryPolicy::of(
 *     maxAttempts: 4,
 *     baseDelayMs: 100,
 *     retryable: [HttpClientException::class],
 *     deadlineMs: 5_000,
 * );
 * ```
 */
final class RetryPolicy
{
    /**
     * @param positive-int              $maxAttempts total tries, not retries — 1 means "try once, never retry"
     * @param int<0, max>               $baseDelayMs the first retry's un-jittered delay
     * @param float                     $multiplier  growth per attempt; 1.0 is a constant backoff
     * @param int<0, max>               $maxDelayMs  ceiling the exponential is clamped to before jitter
     * @param list<class-string<Throwable>> $retryable exception types that earn another attempt
     * @param positive-int|null         $deadlineMs  total wall clock the loop may consume, or null for none
     */
    private function __construct(
        private readonly int $maxAttempts,
        private readonly int $baseDelayMs,
        private readonly float $multiplier,
        private readonly int $maxDelayMs,
        private readonly array $retryable,
        private readonly ?int $deadlineMs,
    ) {
    }

    /**
     * Every numeric bound is typed plainly rather than as a narrow integer range, and that is a
     * decision rather than an omission: a `positive-int` parameter makes the `< 1` refusal below
     * unreachable from type-correct code, so the only way to test the guard would be an analyser
     * suppression this project forbids. The narrow types live on what this class *returns* instead,
     * where they help a consumer and cost nothing. Same division as `$retryable` below, and the
     * same one `SecretKey` makes for its key length.
     *
     * @param int                           $maxAttempts >= 1; total tries, not retries
     * @param int                           $baseDelayMs >= 0
     * @param list<class-string>            $retryable   typed one step looser than the private
     *                                                   constructor's `class-string<Throwable>` on
     *                                                   purpose: an allowlist usually arrives from
     *                                                   configuration, so "is it a Throwable" has to
     *                                                   be a runtime refusal a caller can actually
     *                                                   reach — the division `SecretKey` makes for
     *                                                   its key length, for the same reason
     * @param int|null                      $deadlineMs  >= 1 when given; null means no deadline
     * @param int|null                      $maxDelayMs  >= $baseDelayMs; defaults to thirty times it
     *
     * @throws InvalidArgumentException if any bound is out of range, if `$retryable` is empty or
     *                                 names a class that is not a `Throwable`, or if `$maxDelayMs`
     *                                 is below `$baseDelayMs` — a ceiling under the floor silently
     *                                 flattens the backoff to a constant, which looks like it works
     */
    public static function of(
        int $maxAttempts,
        int $baseDelayMs,
        array $retryable,
        ?int $deadlineMs = null,
        float $multiplier = 2.0,
        ?int $maxDelayMs = null,
    ): self {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException(\sprintf(
                '$maxAttempts must be >= 1, got %d. It counts total attempts, not retries, so 1 is '
                . 'the "try once and never retry" policy.',
                $maxAttempts,
            ));
        }

        if ($baseDelayMs < 0) {
            throw new InvalidArgumentException(\sprintf('$baseDelayMs must be >= 0, got %d.', $baseDelayMs));
        }

        if ($multiplier < 1.0) {
            throw new InvalidArgumentException(\sprintf(
                '$multiplier must be >= 1.0, got %s. A multiplier below one shrinks the delay on '
                . 'each attempt, which retries a struggling dependency faster the longer it stays '
                . 'down — the opposite of backoff.',
                \var_export($multiplier, true),
            ));
        }

        $ceiling = $maxDelayMs ?? $baseDelayMs * 30;

        if ($ceiling < $baseDelayMs) {
            throw new InvalidArgumentException(\sprintf(
                '$maxDelayMs (%d) is below $baseDelayMs (%d). The ceiling would clamp every delay '
                . 'to itself, turning the exponential backoff into a constant one while still '
                . 'reporting a multiplier — refused rather than clamped, because a flattened '
                . 'backoff has no symptom until the dependency it is hammering falls over.',
                $ceiling,
                $baseDelayMs,
            ));
        }

        if ($deadlineMs !== null && $deadlineMs < 1) {
            throw new InvalidArgumentException(\sprintf(
                '$deadlineMs must be >= 1 when given, got %d. Pass null for "no deadline" rather '
                . 'than zero, so that an unbounded loop is always something someone wrote down.',
                $deadlineMs,
            ));
        }

        if ($retryable === []) {
            throw new InvalidArgumentException(
                '$retryable must name at least one exception type. An empty allowlist is a policy '
                . 'that never retries, which presents as "the retries are not happening" rather '
                . 'than as a configuration error — the reasoning FR-35 applies to empty criteria.',
            );
        }

        foreach ($retryable as $class) {
            if (!\is_a($class, Throwable::class, true)) {
                throw new InvalidArgumentException(\sprintf(
                    '$retryable must contain Throwable class names; "%s" is not one. A name that '
                    . 'never matches would make the policy silently un-retryable.',
                    $class,
                ));
            }
        }

        return new self($maxAttempts, $baseDelayMs, $multiplier, $ceiling, \array_values($retryable), $deadlineMs);
    }

    /**
     * @return positive-int
     */
    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * @return positive-int|null
     */
    public function deadlineMs(): ?int
    {
        return $this->deadlineMs;
    }

    /**
     * Whether `$failure` earns another attempt.
     *
     * `instanceof` against the allowlist, so naming a parent type covers its subclasses — which is
     * why {@see \D4np\Utils\Support\UtilsException} in the list retries the whole library hierarchy
     * and is almost never what a caller means.
     */
    public function isRetryable(Throwable $failure): bool
    {
        foreach ($this->retryable as $class) {
            if ($failure instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * The jittered delay, in milliseconds, to wait *before* attempt `$attempt`.
     *
     * `$attempt` is 1-based and counts the attempt about to be made, so the first retry is attempt 2
     * and pays one multiplier step. Full jitter: the return is drawn uniformly from
     * `[0, min(maxDelayMs, baseDelayMs × multiplier^(attempt - 2))]`.
     *
     * @param int $attempt the attempt this delay precedes, >= 2; 1 has nothing before it
     *
     * @throws InvalidArgumentException if `$attempt` is below 2 ({@see ceilingFor()} raises it)
     */
    public function delayFor(int $attempt): int
    {
        // Full jitter over the exponential ceiling, and deliberately not switchable. A caller who
        // could pass jitter: false would reintroduce the synchronized-retry outage this requirement
        // exists to prevent, so there is no argument that reaches this line.
        return \random_int(0, $this->ceilingFor($attempt));
    }

    /**
     * The un-jittered exponential delay `$attempt` is drawn against — the upper bound of
     * {@see delayFor()}'s range.
     *
     * Public because it is the only way a test, or a caller logging its own backoff, can state what
     * the jitter was applied *to*: the jittered value alone cannot distinguish a correct
     * distribution from a broken one.
     *
     * @param int $attempt >= 2
     *
     * @throws InvalidArgumentException if `$attempt` is below 2
     */
    public function ceilingFor(int $attempt): int
    {
        if ($attempt < 2) {
            throw new InvalidArgumentException(\sprintf('$attempt must be >= 2, got %d.', $attempt));
        }

        return (int) \min(
            (float) $this->maxDelayMs,
            $this->baseDelayMs * $this->multiplier ** ($attempt - 2),
        );
    }
}
