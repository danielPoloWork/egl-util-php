<?php

declare(strict_types=1);

namespace D4np\Utils\Security;

/**
 * The answer to one attempt (spec r22 FR-50, RFC-0003; ADR-0061 §7, ADR-0067).
 *
 * **A denial is a normal outcome, not an exception.** Being rate-limited is the control working, and
 * a caller has to branch on it either way — so it arrives as a value. The typed exception,
 * {@see \D4np\Utils\Support\RateLimitStoreException}, is reserved for the *store* failing, which is
 * the case a caller must not accidentally treat as a decision.
 *
 * ```php
 * $decision = $limiter->attempt('login', $username);
 *
 * if (!$decision->allowed()) {
 *     return Response::json(['error' => 'too many attempts'], 429)
 *         ->withHeader('Retry-After', (string) $decision->retryAfterSeconds());
 * }
 * ```
 */
final class RateLimitDecision
{
    private function __construct(
        private readonly bool $allowed,
        private readonly int $remaining,
        private readonly int $retryAfterMicros,
    ) {
    }

    /**
     * @param int<0, max> $remaining tokens left after this attempt was granted
     */
    public static function grant(int $remaining): self
    {
        return new self(true, $remaining, 0);
    }

    /**
     * @param int<0, max> $retryAfterMicros microseconds until the next token arrives
     */
    public static function refuse(int $retryAfterMicros): self
    {
        return new self(false, 0, $retryAfterMicros);
    }

    public function allowed(): bool
    {
        return $this->allowed;
    }

    /**
     * Tokens left in the bucket — the `X-RateLimit-Remaining` figure. Always `0` on a denial.
     */
    public function remaining(): int
    {
        return $this->remaining;
    }

    /**
     * Microseconds until the next token, or `0` when the attempt was allowed.
     */
    public function retryAfterMicros(): int
    {
        return $this->retryAfterMicros;
    }

    /**
     * The same wait in whole seconds, **rounded up** — the `Retry-After` header's unit.
     *
     * Rounding up rather than to nearest: telling a client to come back at a moment when the token
     * has not arrived yet earns it a second denial, and a client that retries on schedule and is
     * refused anyway is indistinguishable, from its side, from a broken limit. A denial with a
     * sub-second wait therefore reports `1`, never `0`.
     */
    public function retryAfterSeconds(): int
    {
        return (int) \ceil($this->retryAfterMicros / 1_000_000);
    }
}
