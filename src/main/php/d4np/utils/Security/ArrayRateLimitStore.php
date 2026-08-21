<?php

declare(strict_types=1);

namespace D4np\Utils\Security;

use D4np\Utils\Support\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * **Enforcement scope: one PHP process. Under PHP-FPM that is one request.**
 *
 * Spec r22 FR-50, RFC-0003; ADR-0061 §6, ADR-0067. Read that first sentence again before deploying
 * this behind a web server: it enforces **nothing** across requests, because the array dies with the
 * process. A limiter wired to this in a typical FPM deployment is not a weak limit, it is no limit,
 * and the whole reason ADR-0061 puts the scope in the first line of every store's docblock is that a
 * store which says nothing gets deployed as if it said "global".
 *
 * It exists for two honest uses:
 *
 * - **Tests** — it is to stores what {@see \D4np\Utils\Support\FrozenClock} is to clocks, and it
 *   ships for the same reason ADR-0062 gives: a seam whose only published implementation is the
 *   production one makes every project write its own double, all slightly differently.
 * - **One long-running process** — a CLI daemon, a resident application server, a queue worker.
 *   There, one process genuinely *is* the whole node, and this store is exact.
 *
 * Its CAS is real, not a stub: the version is a per-key counter, and {@see writeIfVersion()} compares
 * before replacing. PHP has one thread of execution per process, so the compare and the write cannot
 * be interleaved — which makes this the seam's simplest correct implementation rather than a
 * pretend one.
 */
final class ArrayRateLimitStore implements RateLimitStore
{
    /** @var array<string, array{state: string, version: int, expiresAtMicros: int}> */
    private array $entries = [];

    private readonly ClockInterface $clock;

    public function __construct(?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    public function read(string $key): ?RateLimitRecord
    {
        $entry = $this->entries[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry['expiresAtMicros'] <= self::microsecondsOf($this->clock)) {
            // Dropped rather than left to accumulate: a limiter keyed on user input would otherwise
            // grow this array for the lifetime of a long-running worker, which is the unbounded
            // storage the hashed key length was already chosen to bound.
            unset($this->entries[$key]);

            return null;
        }

        return new RateLimitRecord($entry['state'], (string) $entry['version']);
    }

    public function writeIfVersion(string $key, string $state, int $ttlMicros, ?string $expectedVersion): bool
    {
        $current = $this->read($key);

        if ($expectedVersion === null) {
            // Create-if-absent: a first attempt must not overwrite a bucket a concurrent first
            // attempt already created, or the second one would silently restore a full bucket.
            if ($current !== null) {
                return false;
            }
        } else {
            if ($current === null) {
                return false;
            }

            // A named local on each side on purpose: `ConstantTimeComparisonTest` asserts that the
            // two compared values never reach a variable-time comparison, and a registered path
            // whose named values are method calls rather than locals makes that assertion
            // vacuously green — the failure mode item 14.4 hit.
            $currentVersion = $current->version();

            if (!\hash_equals($currentVersion, $expectedVersion)) {
                return false;
            }
        }

        $this->entries[$key] = [
            'state' => $state,
            'version' => ($this->entries[$key]['version'] ?? 0) + 1,
            'expiresAtMicros' => self::microsecondsOf($this->clock) + $ttlMicros,
        ];

        return true;
    }

    /**
     * How many keys are currently held — for tests and for a worker that wants to watch its own
     * footprint. Expired entries are only dropped when read, so this counts them until then.
     */
    public function size(): int
    {
        return \count($this->entries);
    }

    private static function microsecondsOf(ClockInterface $clock): int
    {
        $now = $clock->now();

        return $now->getTimestamp() * 1_000_000 + (int) $now->format('u');
    }
}
