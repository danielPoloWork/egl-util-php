<?php

declare(strict_types=1);

namespace D4np\Utils\Security;

use D4np\Utils\Support\File;
use D4np\Utils\Support\FileException;
use D4np\Utils\Support\RateLimitStoreException;
use D4np\Utils\Support\SystemClock;
use Psr\Clock\ClockInterface;

/**
 * **Enforcement scope: every PHP-FPM worker on one machine, and no further.**
 *
 * Spec r22 FR-50, RFC-0003; ADR-0061 §6, ADR-0067. Behind a load balancer this means each node
 * enforces its own independent limit: the effective limit is N× the configured one, and an attacker
 * who spreads requests across nodes is throttled by none of them. Multi-node enforcement needs a
 * store every node shares — a consumer-implemented {@see RateLimitStore} over Redis or equivalent.
 * This library ships the algorithm and the seam; it deliberately ships no network client.
 *
 * Within that scope it is exact, and the reason is
 * {@see File::update()} — the locked read-modify-write ADR-0038 built and suite T-14 proved under
 * real multi-process contention. **The compare and the replacement happen inside one exclusive
 * lock**, which is what makes this a genuine CAS rather than a check-then-act with extra steps: the
 * version read by {@see read()} may be stale by the time {@see writeIfVersion()} runs, and the
 * comparison *inside* the lock is what catches that.
 *
 * The version is a **content hash**, not a counter, and that choice follows from the same lock: two
 * workers that read the same bytes compute the same version without coordinating, and any write by
 * either changes it. A counter would need its own storage and its own increment race.
 *
 * **The TTL lives inside the file**, ahead of the state, rather than being inferred from the
 * filesystem's modification time. An `mtime` is changed by a backup, a `touch`, an rsync, or a
 * container image rebuild — none of which mean the bucket is fresh, and all of which would silently
 * extend or reset a throttle.
 *
 * The key is 64 hex characters by the time it arrives (the limiter hashes at its own boundary,
 * ADR-0061 §4), so **no user-controlled byte ever becomes part of a filename** — the traversal this
 * store would otherwise be the library's own instance of.
 *
 * **Sizing: each key costs two inodes**, a `<hash>.bucket` and the `<hash>.bucket.lock` sidecar
 * {@see File::update()} holds its lock on (ADR-0005 keeps the lock off the file that gets atomically
 * renamed). Nothing here prunes them — expired state reads as absent but its file stays until it is
 * overwritten, so a limiter keyed on user input wants a periodic sweep of this directory. That is
 * left to the deployment on purpose: a library that deleted files on a schedule of its own choosing
 * would be doing it inside somebody's request.
 */
final class FileRateLimitStore implements RateLimitStore
{
    /** Expiry instant, then the limiter's opaque state. */
    private const EXPIRY_BYTES = Uint64::BYTES;

    private readonly ClockInterface $clock;

    /**
     * @param string $directory where the per-key files live. It must exist and be writable; nothing
     *                          is created here, because a limiter silently writing into a directory
     *                          it invented is a limiter nobody is watching the size of
     */
    public function __construct(
        private readonly string $directory,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    public function read(string $key): ?RateLimitRecord
    {
        $path = $this->pathFor($key);

        if (!\is_file($path)) {
            return null;
        }

        $contents = @\file_get_contents($path);

        if ($contents === false) {
            throw new RateLimitStoreException(\sprintf(
                'Cannot read the rate-limit state at "%s". Refused rather than treated as an '
                . 'absent bucket: an unreadable file would otherwise restore a full bucket to '
                . 'whichever key an attacker had already exhausted.',
                $path,
            ));
        }

        return self::recordFrom($contents, $path, self::microsecondsOf($this->clock));
    }

    public function writeIfVersion(string $key, string $state, int $ttlMicros, ?string $expectedVersion): bool
    {
        $path = $this->pathFor($key);
        $nowMicros = self::microsecondsOf($this->clock);
        $written = false;

        try {
            File::update($path, function (string $current) use (
                $state,
                $ttlMicros,
                $expectedVersion,
                $path,
                $nowMicros,
                &$written,
            ): string {
                // Inside the exclusive lock: this comparison is the whole CAS. Whatever read()
                // returned may be stale, and this is where that is caught.
                $existing = $current === ''
                    ? null
                    : self::recordFrom($current, $path, $nowMicros);

                if ($expectedVersion === null) {
                    if ($existing !== null) {
                        return $current;
                    }
                } else {
                    if ($existing === null) {
                        return $current;
                    }

                    // Named locals on both sides: see ArrayRateLimitStore's note — a registered
                    // secret-comparison path whose values are method calls asserts nothing.
                    $currentVersion = $existing->version();

                    if (!\hash_equals($currentVersion, $expectedVersion)) {
                        return $current;
                    }
                }

                $written = true;

                return Uint64::encode($nowMicros + $ttlMicros) . $state;
            });
        } catch (FileException $failure) {
            throw new RateLimitStoreException(\sprintf(
                'Cannot write the rate-limit state at "%s": %s',
                $path,
                $failure->getMessage(),
            ), 0, $failure);
        }

        return $written;
    }

    /**
     * Parses stored bytes into a record, or `null` when they have expired.
     *
     * @throws RateLimitStoreException if the file is shorter than its own expiry field
     */
    private static function recordFrom(string $contents, string $path, int $nowMicros): ?RateLimitRecord
    {
        if (\strlen($contents) <= self::EXPIRY_BYTES) {
            throw new RateLimitStoreException(\sprintf(
                'The rate-limit state at "%s" is %d bytes, too short to carry its own expiry. '
                . 'Refused rather than read as absent, for the same reason an unreadable file is: '
                . 'a corrupt bucket must not become a fresh one.',
                $path,
                \strlen($contents),
            ));
        }

        if (Uint64::decode($contents) <= $nowMicros) {
            return null;
        }

        return new RateLimitRecord(
            \substr($contents, self::EXPIRY_BYTES),
            \hash('sha256', $contents),
        );
    }

    private function pathFor(string $key): string
    {
        return $this->directory . \DIRECTORY_SEPARATOR . $key . '.bucket';
    }

    private static function microsecondsOf(ClockInterface $clock): int
    {
        $now = $clock->now();

        return $now->getTimestamp() * 1_000_000 + (int) $now->format('u');
    }
}
