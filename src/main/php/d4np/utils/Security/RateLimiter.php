<?php

declare(strict_types=1);

namespace D4np\Utils\Security;

use D4np\Utils\Support\RateLimitStoreException;
use D4np\Utils\Support\SystemClock;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * A token bucket per key, behind a compare-and-swap store (spec r22 FR-50, RFC-0003; ADR-0061,
 * ADR-0067).
 *
 * Issue #91 asked for an in-library throttle because the hand-rolled versions are *"usually
 * bypassable (per-node state, resettable windows)"*. Both halves of that complaint are answered
 * structurally here: the store seam is compare-and-swap so per-node state is a stated scope rather
 * than an accident ({@see RateLimitStore}), and a token bucket has no window edge to straddle — the
 * "resettable windows" defect **is** the fixed-window boundary burst, 2× the intended rate for an
 * attacker who times requests across it.
 *
 * **Read {@see RateLimitStore}'s enforcement-scope note before deploying this.** A rate limit exists
 * at the scope its store is shared, and nowhere else.
 *
 * ## Keys are hashed at this boundary, and that is the whole key-safety story
 *
 * The caller supplies a namespace and a key — `'login'` and the target username. This class, never
 * the store, canonicalizes them:
 *
 * ```
 * storage_key = hex(sha256( len(ns) ‖ ns ‖ len(key) ‖ key ))
 * ```
 *
 * Every store therefore receives a fixed-length, fixed-alphabet token, and three problems are gone
 * by construction rather than by per-store discipline:
 *
 * - **Store-syntax injection.** A user-controlled key cannot carry a Redis separator, a SQL
 *   wildcard, or — the one that would be a vulnerability in this library's own shipped store — a
 *   **path traversal** into the file store's directory. User input never becomes a filename.
 * - **Unbounded keys.** An attacker cannot inflate per-key storage with kilobyte usernames; every
 *   key costs the same 64 hex characters.
 * - **Content-shaped timing.** Two raw keys differing in the first byte versus the last are
 *   indistinguishable after hashing, so any store-side comparison is content-oblivious *by
 *   construction* — satisfied once here instead of by auditing `hash_equals()` across stores this
 *   library will never see.
 *
 * The length prefixes are domain separation: `('ab', 'c')` and `('a', 'bc')` must not collide. Same
 * discipline as ADR-0054's fixed offsets and ADR-0065's fixed-width expiry.
 *
 * ## A skewed clock cannot mint tokens
 *
 * Elapsed time is `max(0, now − lastRefill)` and the refilled count is capped at capacity. A node
 * whose clock runs behind the one that wrote the state sees no elapsed time and refills **zero** — it
 * can under-grant, never over-grant. Skew degrades toward strictness, which for a security control
 * is the correct direction to degrade. Monotonic time is deliberately not assumed: the state crosses
 * nodes, and no node's monotonic counter means anything on another.
 *
 * ## A store failure is never a decision
 *
 * Nothing here catches {@see RateLimitStoreException}. The caller — the only party who knows whether
 * *this* endpoint prefers lockout or exposure while the backend is down — makes that call at its own
 * `catch`. **If an endpoint chooses fail-open, it should do so loudly**: log at error, raise an
 * alert. A `catch` that returns "allowed" and says nothing recreates the silent-failure hole this
 * design exists to close, because protection that evaporates under infrastructure stress disappears
 * exactly when attacks are cheapest.
 *
 * ```php
 * $limiter = new RateLimiter(
 *     RateLimitPolicy::perWindow(5, new DateInterval('PT15M')),
 *     new FileRateLimitStore('/var/lib/app/throttle'),
 * );
 *
 * if (!$limiter->attempt('login', $username)->allowed()) {
 *     // refuse, and do not call Hash::verify()
 * }
 * ```
 */
final class RateLimiter
{
    /**
     * How many times a losing compare-and-swap is retried before the attempt is refused.
     *
     * Three, and the number is an argument rather than a guess. A conflict means this exact key is
     * being written concurrently — for a login throttle that **is** the attack signature — so every
     * extra retry is work an attacker sets the price of, which is the objection that ruled out a
     * sliding-window log in ADR-0061. Three survives incidental contention (two genuine concurrent
     * logins for one account) and gives up quickly under a hammering.
     *
     * Exhaustion **refuses**; it never answers "unknown". Contention on a throttled key is evidence
     * for denial, not against it.
     */
    private const CAS_ATTEMPTS = 3;

    /** Token count and last-refill instant, each a fixed-width big-endian integer. */
    private const STATE_BYTES = Uint64::BYTES * 2;

    private readonly ClockInterface $clock;

    public function __construct(
        private readonly RateLimitPolicy $policy,
        private readonly RateLimitStore $store,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * Spends one token for `$namespace`/`$key`.
     *
     * @param string $namespace what is being limited — `'login'`, `'password-reset'`. Separate
     *                          namespaces are separate buckets for the same key, so one user's
     *                          login attempts do not exhaust their reset attempts
     * @param string $key       who or what is being limited. For credential-stuffing defence this
     *                          is the **target identity**, not the source address: keyed on IP
     *                          alone, a limiter is defeated by address rotation
     *
     * @throws RateLimitStoreException if the store cannot be read or written, or answers with
     *                                 something that is not the state it was given — never
     *                                 converted into a decision here
     */
    public function attempt(string $namespace, string $key): RateLimitDecision
    {
        $storageKey = self::storageKeyFor($namespace, $key);
        $capacity = $this->policy->capacity();
        $interval = $this->policy->refillIntervalMicros();

        // A bucket may be forgotten once it would have refilled to capacity, because a full bucket
        // and an absent one are indistinguishable. Expiring any earlier would hand a throttled key
        // a fresh burst, which is the one way a TTL can lose enforcement.
        $ttlMicros = $capacity * $interval;

        $nowMicros = self::microsecondsOf($this->clock->now());
        $retryAfter = $interval;

        for ($casAttempt = 1; $casAttempt <= self::CAS_ATTEMPTS; $casAttempt++) {
            $record = $this->store->read($storageKey);

            if ($record === null) {
                $tokens = $capacity;
                $lastRefill = $nowMicros;
                $expectedVersion = null;
            } else {
                [$tokens, $lastRefill] = self::decodeState($record->state());
                $expectedVersion = $record->version();

                // max(0, …) is the clock-skew rule: a behind-clock node sees no elapsed time and
                // refills nothing. Without the clamp, a negative elapsed would move lastRefill
                // BACKWARDS, and the next node to read would over-refill from it.
                $elapsed = \max(0, $nowMicros - $lastRefill);
                $refilled = \intdiv($elapsed, $interval);

                if ($refilled > 0) {
                    // lastRefill advances by whole tokens only, so the sub-token remainder carries
                    // into the next read instead of being truncated away on every attempt.
                    $tokens += $refilled;
                    $lastRefill += $refilled * $interval;
                }

                // The ONE ceiling, covering both an over-long idle refill and a token count that
                // exceeds a capacity the policy has since shrunk. There used to be a second
                // `min()` inside the refill above; the planted-defect campaign found that the two
                // masked each other — removing either one left the suite green — so neither was
                // ever actually tested. One clamp, one test (ADR-0022's stance on redundant guards).
                $tokens = \min($capacity, $tokens);
            }

            if ($tokens < 1) {
                return RateLimitDecision::refuse(\max(0, $lastRefill + $interval - $nowMicros));
            }

            $remaining = $tokens - 1;

            if ($this->store->writeIfVersion(
                $storageKey,
                self::encodeState($remaining, $lastRefill),
                $ttlMicros,
                $expectedVersion,
            )) {
                return RateLimitDecision::grant($remaining);
            }

            $retryAfter = \max(0, $lastRefill + $interval - $nowMicros);
        }

        return RateLimitDecision::refuse($retryAfter);
    }

    /**
     * `hex(sha256( len(ns) ‖ ns ‖ len(key) ‖ key ))`.
     *
     * The length prefixes are fixed-width, which is what makes the concatenation unambiguous without
     * a delimiter a key could contain.
     */
    private static function storageKeyFor(string $namespace, string $key): string
    {
        return \hash('sha256', Uint64::encode(\strlen($namespace)) . $namespace
            . Uint64::encode(\strlen($key)) . $key);
    }

    private static function encodeState(int $tokens, int $lastRefillMicros): string
    {
        return Uint64::encode($tokens) . Uint64::encode($lastRefillMicros);
    }

    /**
     * @return array{int, int} the token count and the last-refill instant
     *
     * @throws RateLimitStoreException if the state is not exactly the width this class wrote
     */
    private static function decodeState(string $state): array
    {
        if (\strlen($state) !== self::STATE_BYTES) {
            throw new RateLimitStoreException(\sprintf(
                'The store returned %d bytes of state where %d were written. The state is this '
                . 'limiter\'s opaque bytes and a store must hand them back unchanged; a store that '
                . 'rewrites, truncates or pads them cannot be reasoned about, so this refuses '
                . 'rather than guessing a token count from whatever arrived.',
                \strlen($state),
                self::STATE_BYTES,
            ));
        }

        return [Uint64::decode($state), Uint64::decode($state, Uint64::BYTES)];
    }

    private static function microsecondsOf(DateTimeImmutable $instant): int
    {
        return $instant->getTimestamp() * 1_000_000 + (int) $instant->format('u');
    }
}
