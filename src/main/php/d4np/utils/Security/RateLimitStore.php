<?php

declare(strict_types=1);

namespace D4np\Utils\Security;

use D4np\Utils\Support\RateLimitStoreException;

/**
 * Where bucket state lives, exposed as **atomic conditional replacement** (spec r22 FR-50,
 * RFC-0003; ADR-0061 §2, ADR-0067).
 *
 * ## A rate limit exists at the scope its store is shared, and nowhere else
 *
 * Behind a load balancer, a per-machine store means each node enforces its own independent limit:
 * the effective limit is N× the configured one, and an attacker who spreads requests across nodes is
 * throttled by none of them. Multi-node enforcement requires a store every node shares — a
 * consumer-implemented `RateLimitStore` over Redis or equivalent. **This library ships the algorithm
 * and the seam; it deliberately ships no network client.**
 *
 * And the half that is about the control rather than the deployment: a rate limiter bounds *attempt
 * frequency through the keys you chose*. Keyed on source IP alone it is defeated by address
 * rotation; credential-stuffing defence keys on the **target identity**, and optionally the source
 * as well.
 *
 * ## Why compare-and-swap, and not get/set
 *
 * This is the load-bearing choice of the whole design. A `get()`/`set()` store **cannot be composed
 * race-free by any caller**: two nodes read one remaining token, both approve, both write zero — the
 * limit is exceeded *by the limiter*, at exactly the concurrency a brute-force attack produces. With
 * get/set, every backend implementor inherits a check-then-act race as the default outcome and no
 * library code can close it. With CAS, atomicity is the interface's stated contract, and a store
 * that cannot honour it has no honest way to exist.
 *
 * Every serious backend has a native CAS to build on: Redis (`WATCH`/`MULTI`, or a Lua script),
 * APCu (`apcu_cas`), any SQL engine (an optimistic `UPDATE … WHERE version = ?`), and a locked file,
 * where exclusivity makes the version check trivially true.
 *
 * ## What an implementation owes
 *
 * - **Atomicity.** `writeIfVersion()` must compare and replace as one indivisible step. Reading the
 *   current version, comparing it in PHP, and then writing is the race this interface exists to
 *   remove.
 * - **Opacity.** `$state` is the limiter's bytes; never parse or rewrite them. `$version` is yours;
 *   the limiter only quotes it back.
 * - **Failing loudly.** Throw {@see RateLimitStoreException} rather than returning `null` or `false`
 *   for a backend that is unreachable. `null` from {@see read()} means *no bucket*, and `false` from
 *   {@see writeIfVersion()} means *the version did not match* — using either to mean "the store is
 *   broken" makes an outage indistinguishable from a fresh key or from contention, and the limiter
 *   would then convert an infrastructure failure into a decision (ADR-0061 §3).
 * - **Honouring the TTL.** State older than its TTL must read as absent. The limiter chooses a TTL
 *   at which a forgotten bucket and a full bucket are indistinguishable, so expiry can never lose
 *   enforcement — but only if it is not expired *early*.
 * - **Stating its scope.** Say in the first sentence of your docblock where the limit is enforced,
 *   the way both shipped stores do.
 */
interface RateLimitStore
{
    /**
     * The stored bucket for `$key`, or `null` if there is none (or it has expired).
     *
     * @param string $key 64 lowercase hex characters — the limiter hashes namespace and caller key
     *                    at its own boundary, so no user-controlled byte ever reaches a store
     *                    (ADR-0061 §4)
     *
     * @throws RateLimitStoreException if the backend cannot be reached or answers with something
     *                                 that is not the state it was given
     */
    public function read(string $key): ?RateLimitRecord;

    /**
     * Replaces `$key`'s state **only if** its current version is `$expectedVersion`, as one atomic
     * step.
     *
     * @param string      $key             as {@see read()}
     * @param string      $state           opaque limiter bytes; store them unchanged
     * @param int         $ttlMicros       how long the state stays readable, in microseconds
     * @param string|null $expectedVersion the version {@see read()} returned, or `null` to mean
     *                                     **create only if absent** — which is how a first attempt
     *                                     for a key avoids racing a concurrent first attempt
     *
     * @return bool `true` if the replacement happened; `false` if the version did not match, which
     *              the limiter reads as contention and retries, bounded
     *
     * @throws RateLimitStoreException if the backend cannot be reached
     */
    public function writeIfVersion(string $key, string $state, int $ttlMicros, ?string $expectedVersion): bool;
}
