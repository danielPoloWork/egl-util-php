<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Security;

use D4np\Utils\Security\ArrayRateLimitStore;
use D4np\Utils\Security\FileRateLimitStore;
use D4np\Utils\Security\RateLimiter;
use D4np\Utils\Security\RateLimitPolicy;
use D4np\Utils\Security\RateLimitStore;
use D4np\Utils\Support\FrozenClock;
use D4np\Utils\Support\RateLimitStoreException;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec r22 FR-50 (RFC-0003), ADR-0061 §6 / ADR-0067: both shipped stores, held to one contract.
 *
 * The contract cases run against **both** implementations through a data provider, because the value
 * of the seam is that a consumer's own store can be dropped in — and a contract only one
 * implementation was ever checked against is not a contract. That is the same reasoning the PSR-7
 * bridge's suite uses against two PSR-17 implementations.
 */
final class RateLimitStoreTest extends TestCase
{
    private FrozenClock $clock;

    private string $directory;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-21 12:00:00'));
        $this->directory = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'egl-ratelimit-' . \bin2hex(\random_bytes(8));
        \mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->directory . \DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->directory);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bothStores(): iterable
    {
        yield 'in-memory' => ['array'];
        yield 'file' => ['file'];
    }

    private function store(string $kind): RateLimitStore
    {
        return $kind === 'array'
            ? new ArrayRateLimitStore($this->clock)
            : new FileRateLimitStore($this->directory, $this->clock);
    }

    private static function key(string $suffix = ''): string
    {
        return \hash('sha256', 'key' . $suffix);
    }

    // -----------------------------------------------------------------------------------------
    // The contract, both implementations
    // -----------------------------------------------------------------------------------------

    #[DataProvider('bothStores')]
    public function testAnAbsentKeyReadsAsNull(string $kind): void
    {
        self::assertNull($this->store($kind)->read(self::key()));
    }

    #[DataProvider('bothStores')]
    public function testCreateIfAbsentSucceedsOnceAndThenConflicts(string $kind): void
    {
        $store = $this->store($kind);

        self::assertTrue($store->writeIfVersion(self::key(), 'state-one', 60_000_000, null));
        self::assertFalse(
            $store->writeIfVersion(self::key(), 'state-two', 60_000_000, null),
            'a null expected version means create-only: a concurrent first attempt must lose, or '
            . 'it would silently restore a full bucket',
        );
    }

    #[DataProvider('bothStores')]
    public function testAWriteQuotingTheCurrentVersionSucceeds(string $kind): void
    {
        $store = $this->store($kind);
        $store->writeIfVersion(self::key(), 'state-one', 60_000_000, null);

        $record = $store->read(self::key());
        self::assertNotNull($record);
        self::assertSame('state-one', $record->state());

        self::assertTrue($store->writeIfVersion(self::key(), 'state-two', 60_000_000, $record->version()));

        $updated = $store->read(self::key());
        self::assertNotNull($updated);
        self::assertSame('state-two', $updated->state());
    }

    #[DataProvider('bothStores')]
    public function testAWriteQuotingAStaleVersionIsRefused(string $kind): void
    {
        $store = $this->store($kind);
        $store->writeIfVersion(self::key(), 'state-one', 60_000_000, null);

        $stale = $store->read(self::key());
        self::assertNotNull($stale);

        // Someone else writes in between.
        self::assertTrue($store->writeIfVersion(self::key(), 'state-two', 60_000_000, $stale->version()));

        self::assertFalse(
            $store->writeIfVersion(self::key(), 'state-three', 60_000_000, $stale->version()),
            'this is the lost update the whole seam exists to prevent',
        );

        $current = $store->read(self::key());
        self::assertNotNull($current);
        self::assertSame('state-two', $current->state(), 'and the losing write left nothing behind');
    }

    #[DataProvider('bothStores')]
    public function testTheVersionChangesWithEveryWrite(string $kind): void
    {
        $store = $this->store($kind);
        $store->writeIfVersion(self::key(), 'state-one', 60_000_000, null);

        $first = $store->read(self::key());
        self::assertNotNull($first);
        $store->writeIfVersion(self::key(), 'state-two', 60_000_000, $first->version());
        $second = $store->read(self::key());
        self::assertNotNull($second);

        self::assertNotSame($first->version(), $second->version());
    }

    #[DataProvider('bothStores')]
    public function testStateIsStoredByteForByte(string $kind): void
    {
        $store = $this->store($kind);
        $opaque = \random_bytes(16) . "\0\r\n" . \chr(0xFF);

        $store->writeIfVersion(self::key(), $opaque, 60_000_000, null);
        $record = $store->read(self::key());

        self::assertNotNull($record);
        self::assertSame(
            $opaque,
            $record->state(),
            'the state is the limiter\'s opaque bytes; a store that reinterprets them is '
            . 'reimplementing the bucket',
        );
    }

    #[DataProvider('bothStores')]
    public function testExpiredStateReadsAsAbsent(string $kind): void
    {
        $store = $this->store($kind);
        $store->writeIfVersion(self::key(), 'state', 1_000_000, null);

        $this->clock->advance(new DateInterval('PT1S'));

        self::assertNull($store->read(self::key()), 'the TTL is honoured, and inclusively');
    }

    #[DataProvider('bothStores')]
    public function testStateJustInsideItsTtlIsStillThere(string $kind): void
    {
        $store = $this->store($kind);
        $store->writeIfVersion(self::key(), 'state', 2_000_000, null);

        $this->clock->advance(new DateInterval('PT1S'));

        self::assertNotNull($store->read(self::key()));
    }

    #[DataProvider('bothStores')]
    public function testKeysAreIndependent(string $kind): void
    {
        $store = $this->store($kind);

        $store->writeIfVersion(self::key('a'), 'state-a', 60_000_000, null);
        $store->writeIfVersion(self::key('b'), 'state-b', 60_000_000, null);

        $a = $store->read(self::key('a'));
        $b = $store->read(self::key('b'));
        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertSame('state-a', $a->state());
        self::assertSame('state-b', $b->state());
    }

    #[DataProvider('bothStores')]
    public function testAFullBucketRoundTripsThroughTheLimiter(string $kind): void
    {
        $limiter = new RateLimiter(
            RateLimitPolicy::perWindow(2, new DateInterval('PT20S')),
            $this->store($kind),
            $this->clock,
        );

        self::assertTrue($limiter->attempt('login', 'ada')->allowed());
        self::assertTrue($limiter->attempt('login', 'ada')->allowed());
        self::assertFalse($limiter->attempt('login', 'ada')->allowed());

        $this->clock->advance(new DateInterval('PT10S'));
        self::assertTrue($limiter->attempt('login', 'ada')->allowed());
    }

    // -----------------------------------------------------------------------------------------
    // The file store's own properties
    // -----------------------------------------------------------------------------------------

    /**
     * One state file per key, named by the hash — **plus one sidecar lock**, which is worth pinning
     * rather than glossing.
     *
     * The `.lock` file is `File::update()`'s, from ADR-0005: the lock is held on a sidecar so the
     * atomic rename of the state file cannot pull the lock out from under a waiting process. It means
     * each key costs **two inodes**, not one, which is a fact an operator sizing a directory needs
     * and which nothing else in the tree would tell them. Asserted here because the first version of
     * this test asserted one file and was wrong.
     */
    public function testTheFileStoreWritesOneStateFileAndOneLockPerKeyNamedByTheHash(): void
    {
        $store = new FileRateLimitStore($this->directory, $this->clock);
        $store->writeIfVersion(self::key('a'), 'state', 60_000_000, null);
        $store->writeIfVersion(self::key('b'), 'state', 60_000_000, null);

        $files = \array_map('basename', \glob($this->directory . \DIRECTORY_SEPARATOR . '*') ?: []);
        \sort($files);

        self::assertSame([
            self::key('a') . '.bucket',
            self::key('a') . '.bucket.lock',
            self::key('b') . '.bucket',
            self::key('b') . '.bucket.lock',
        ], $files);

        foreach ($files as $file) {
            self::assertMatchesRegularExpression(
                '/\A[0-9a-f]{64}\.bucket(\.lock)?\z/',
                $file,
                'no user-controlled byte may appear in a filename',
            );
        }
    }

    /**
     * The expiry is inside the file, not inferred from `mtime`.
     *
     * An `mtime` is moved by a backup, a `touch`, an rsync or a container rebuild, none of which mean
     * the bucket is fresh — and all of which would silently extend or reset a throttle. Touching the
     * file must change nothing about when the state expires.
     */
    public function testTheFileStoreIgnoresTheFilesystemTimestamp(): void
    {
        $store = new FileRateLimitStore($this->directory, $this->clock);
        $store->writeIfVersion(self::key(), 'state', 1_000_000, null);

        $path = $this->directory . \DIRECTORY_SEPARATOR . self::key() . '.bucket';
        \touch($path, \time() + 86_400);
        \clearstatcache(true, $path);

        $this->clock->advance(new DateInterval('PT2S'));

        self::assertNull(
            $store->read(self::key()),
            'a future mtime must not resurrect expired state',
        );
    }

    public function testAnUnreadableLengthIsRefusedRatherThanTreatedAsAbsent(): void
    {
        $path = $this->directory . \DIRECTORY_SEPARATOR . self::key() . '.bucket';
        \file_put_contents($path, 'short');

        $this->expectException(RateLimitStoreException::class);
        (new FileRateLimitStore($this->directory, $this->clock))->read(self::key());
    }

    public function testAMissingDirectoryFailsAsAStoreErrorNotAsAnEmptyBucket(): void
    {
        $store = new FileRateLimitStore($this->directory . \DIRECTORY_SEPARATOR . 'nope', $this->clock);

        self::assertNull($store->read(self::key()), 'nothing is there to read');

        $this->expectException(RateLimitStoreException::class);
        $store->writeIfVersion(self::key(), 'state', 60_000_000, null);
    }

    // -----------------------------------------------------------------------------------------
    // The in-memory store's own properties
    // -----------------------------------------------------------------------------------------

    public function testTheArrayStoreDropsExpiredEntriesWhenTheyAreRead(): void
    {
        $store = new ArrayRateLimitStore($this->clock);
        $store->writeIfVersion(self::key(), 'state', 1_000_000, null);

        self::assertSame(1, $store->size());

        $this->clock->advance(new DateInterval('PT2S'));
        $store->read(self::key());

        self::assertSame(
            0,
            $store->size(),
            'a long-running worker must not accumulate a bucket per key seen, forever',
        );
    }

    /**
     * Both stores on the system clock — the default-clock path no other test here reaches.
     *
     * Same gap as `RateLimiterTest::testItWorksWithoutAnInjectedClock()`, and found the same way:
     * the per-diff coverage gate's first real reading (ADR-0068) named eight never-executed
     * statements on this item, and `$clock ?? new SystemClock()` was one of them in each class.
     * A generous TTL keeps the assertion independent of what time it actually is.
     */
    public function testBothStoresWorkWithoutAnInjectedClock(): void
    {
        foreach ([new ArrayRateLimitStore(), new FileRateLimitStore($this->directory)] as $store) {
            self::assertTrue($store->writeIfVersion(self::key(), 'state', 3_600_000_000, null));

            $record = $store->read(self::key());
            self::assertNotNull($record, \get_class($store) . ' lost the state it just wrote');
            self::assertSame('state', $record->state());
        }
    }

    public function testTheArrayStoreEnforcesNothingAcrossInstances(): void
    {
        $first = new ArrayRateLimitStore($this->clock);
        $first->writeIfVersion(self::key(), 'state', 60_000_000, null);

        self::assertNull(
            (new ArrayRateLimitStore($this->clock))->read(self::key()),
            'this is the documented scope, asserted so nobody discovers it in production: a second '
            . 'instance — which under PHP-FPM is the next request — shares nothing',
        );
    }
}
