<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Security;

use D4np\Utils\Security\ArrayRateLimitStore;
use D4np\Utils\Security\RateLimiter;
use D4np\Utils\Security\RateLimitPolicy;
use D4np\Utils\Security\RateLimitRecord;
use D4np\Utils\Security\RateLimitStore;
use D4np\Utils\Support\FrozenClock;
use D4np\Utils\Support\RateLimitStoreException;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec r22 FR-50 (RFC-0003), ADR-0061 / ADR-0067: the token bucket, its CAS loop, and the four
 * adversarial cases issue #91 named.
 *
 * No test sleeps: every instant comes from {@see FrozenClock}, which is why the refill arithmetic can
 * be asserted exactly rather than approximately.
 *
 * Three assertions here are **mechanisms** per ADR-0027, because a suite that only watches
 * allow/deny outcomes stays green when any of them silently vanishes: that the key path is
 * hash-then-lookup, that elapsed time clamps at zero, and that the CAS loop is bounded by a
 * constant. The last one especially — an unbounded loop does not fail a behavioural test, it hangs
 * it.
 */
final class RateLimiterTest extends TestCase
{
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-21 12:00:00'));
    }

    private function limiter(int $capacity = 3, string $window = 'PT1M', ?RateLimitStore $store = null): RateLimiter
    {
        return new RateLimiter(
            RateLimitPolicy::perWindow($capacity, new DateInterval($window)),
            $store ?? new ArrayRateLimitStore($this->clock),
            $this->clock,
        );
    }

    private function advance(int $seconds): void
    {
        $this->clock->advance(new DateInterval('PT' . $seconds . 'S'));
    }

    // -----------------------------------------------------------------------------------------
    // The bucket
    // -----------------------------------------------------------------------------------------

    public function testTheFirstAttemptIsAllowedAndSpendsOneToken(): void
    {
        $decision = $this->limiter(capacity: 3)->attempt('login', 'ada');

        self::assertTrue($decision->allowed());
        self::assertSame(2, $decision->remaining());
        self::assertSame(0, $decision->retryAfterMicros());
    }

    /**
     * The burst is exactly the capacity — the adversarial case issue #91 names, at the boundary.
     */
    public function testABurstOfExactlyCapacityIsAllowedAndTheNextIsNot(): void
    {
        $limiter = $this->limiter(capacity: 3);

        $remaining = [];
        for ($i = 0; $i < 3; $i++) {
            $decision = $limiter->attempt('login', 'ada');
            self::assertTrue($decision->allowed(), 'attempt ' . ($i + 1) . ' is inside the capacity');
            $remaining[] = $decision->remaining();
        }

        self::assertSame([2, 1, 0], $remaining);

        $refused = $limiter->attempt('login', 'ada');
        self::assertFalse($refused->allowed(), 'the attempt after the burst must be refused');
        self::assertSame(0, $refused->remaining());
        self::assertGreaterThan(0, $refused->retryAfterMicros());
    }

    public function testOneTokenComesBackAfterOneRefillInterval(): void
    {
        // Three tokens over a minute is one token every 20 seconds.
        $limiter = $this->limiter(capacity: 3, window: 'PT1M');

        for ($i = 0; $i < 3; $i++) {
            $limiter->attempt('login', 'ada');
        }
        self::assertFalse($limiter->attempt('login', 'ada')->allowed());

        $this->advance(19);
        self::assertFalse($limiter->attempt('login', 'ada')->allowed(), 'a second short of one token');

        $this->advance(1);
        self::assertTrue($limiter->attempt('login', 'ada')->allowed(), 'exactly one interval elapsed');
        self::assertFalse($limiter->attempt('login', 'ada')->allowed(), 'and only one token arrived');
    }

    /**
     * The refill cannot carry a bucket past its capacity.
     *
     * The idle gap has to sit **inside the bucket's TTL** for this to test anything, and getting
     * that wrong is how the first version of this test passed for the wrong reason: it idled an
     * hour, the state had expired (the TTL is `capacity × interval`, sixty seconds here), the store
     * returned nothing, and the limiter built a fresh full bucket without ever running the refill
     * branch. Removing the clamp left it green. Three tokens over a minute is one every 20 s, so
     * spending one and idling 59 s accrues two against one already held — four against a capacity
     * of three.
     */
    public function testRefillIsCappedAtCapacity(): void
    {
        $limiter = $this->limiter(capacity: 3, window: 'PT1M');

        self::assertTrue($limiter->attempt('login', 'ada')->allowed(), 'two left');

        $this->advance(59);

        $allowed = self::countAllowed($limiter, 6);

        self::assertSame(
            3,
            $allowed,
            'two accrued on top of the two still held is four, and the bucket holds three',
        );
    }

    /**
     * A capacity that shrank between deployments must not grant the old one.
     *
     * The clearly reachable half of the same clamp, and the case its comment names: state written
     * under a larger capacity is read back by a limiter configured with a smaller one. Nothing about
     * the stored bytes says which policy wrote them, so the ceiling has to be applied at read.
     */
    public function testStateWrittenUnderALargerCapacityIsClampedToTheCurrentOne(): void
    {
        $store = new ArrayRateLimitStore($this->clock);
        $generous = new RateLimiter(
            RateLimitPolicy::perWindow(10, new DateInterval('PT10M')),
            $store,
            $this->clock,
        );
        $generous->attempt('login', 'ada');

        $tightened = new RateLimiter(
            RateLimitPolicy::perWindow(2, new DateInterval('PT10M')),
            $store,
            $this->clock,
        );

        $allowed = self::countAllowed($tightened, 6);

        self::assertSame(2, $allowed, 'nine stored tokens must not survive a capacity cut to two');
    }

    public function testAnExpiredBucketBecomesAFullOneRatherThanAnEmptyOne(): void
    {
        $limiter = $this->limiter(capacity: 3, window: 'PT1M');

        for ($i = 0; $i < 3; $i++) {
            $limiter->attempt('login', 'ada');
        }
        self::assertFalse($limiter->attempt('login', 'ada')->allowed());

        // The TTL is capacity x interval = 60 s, chosen so that a forgotten bucket and a full one
        // are indistinguishable. Past it, the state is gone and a fresh bucket is the correct — and
        // only honest — reading, because by then it would have refilled to capacity anyway.
        $this->advance(61);

        $allowed = self::countAllowed($limiter, 6);

        self::assertSame(3, $allowed, 'expiry can restore a full bucket, and never more than one');
    }

    /**
     * The sub-token remainder carries instead of being truncated on every read.
     *
     * With a naive `lastRefill = now`, each attempt would discard the fraction of an interval that
     * had accrued, and a key polled faster than its refill rate would never refill at all — a
     * limiter that gets *stricter* the more often it is asked, which no test of a single refill
     * would notice.
     */
    public function testThePartialRefillCarriesAcrossReads(): void
    {
        $limiter = $this->limiter(capacity: 2, window: 'PT20S');

        $limiter->attempt('login', 'ada');
        $limiter->attempt('login', 'ada');
        self::assertFalse($limiter->attempt('login', 'ada')->allowed());

        // One token every 10 s. Poll every 4 s: no single gap reaches an interval.
        for ($i = 0; $i < 2; $i++) {
            $this->advance(4);
            self::assertFalse($limiter->attempt('login', 'ada')->allowed());
        }

        $this->advance(4);
        self::assertTrue(
            $limiter->attempt('login', 'ada')->allowed(),
            'twelve seconds of accrual arrived in three-second-short slices; the remainder must not '
            . 'have been thrown away on each read',
        );

        // The 2 s left over from that twelve must survive into the next token, and THIS is the
        // assertion the plant needed: with `lastRefill = now`, the first token still arrives on
        // schedule and only the carried remainder is lost, so a test that stopped above stayed
        // green while the remainder was being discarded on every successful refill.
        $this->advance(8);
        self::assertTrue(
            $limiter->attempt('login', 'ada')->allowed(),
            'ten seconds are owed and twelve minus ten plus eight is ten: the carried remainder is '
            . 'what makes the second token arrive here rather than two seconds later',
        );
    }

    public function testNamespacesAreSeparateBuckets(): void
    {
        $limiter = $this->limiter(capacity: 1);

        self::assertTrue($limiter->attempt('login', 'ada')->allowed());
        self::assertFalse($limiter->attempt('login', 'ada')->allowed());
        self::assertTrue(
            $limiter->attempt('password-reset', 'ada')->allowed(),
            'exhausting login attempts must not exhaust reset attempts for the same user',
        );
    }

    public function testKeysAreSeparateBuckets(): void
    {
        $limiter = $this->limiter(capacity: 1);

        self::assertTrue($limiter->attempt('login', 'ada')->allowed());
        self::assertFalse($limiter->attempt('login', 'ada')->allowed());
        self::assertTrue($limiter->attempt('login', 'grace')->allowed());
    }

    /**
     * The default-clock path — the one every production caller takes.
     *
     * Every other test here injects a {@see FrozenClock}, so `$clock ?? new SystemClock()` was
     * never executed by anything. The per-diff coverage gate's first real reading (issue #109,
     * ADR-0068: 167/175 changed statements on this item) is what surfaced it: eight never-executed
     * statements, of which this was one in each of three classes. A wiring path that only
     * consumers reach, and no test does, is worth one assertion each.
     *
     * Real wall-clock time, and that is fine: nothing here depends on *which* instant it is, only
     * that a clock exists and the bucket works against it. No sleeping either.
     */
    public function testItWorksWithoutAnInjectedClock(): void
    {
        $limiter = new RateLimiter(
            RateLimitPolicy::perWindow(2, new DateInterval('PT1H')),
            new ArrayRateLimitStore(),
        );

        self::assertTrue($limiter->attempt('login', 'ada')->allowed());
        self::assertTrue($limiter->attempt('login', 'ada')->allowed());
        self::assertFalse(
            $limiter->attempt('login', 'ada')->allowed(),
            'the bucket must behave identically on the system clock; an hour-long window means '
            . 'no refill can arrive inside this test',
        );
    }

    // -----------------------------------------------------------------------------------------
    // Clock skew — a behind-clock node must not mint tokens
    // -----------------------------------------------------------------------------------------

    /**
     * ADR-0061 §5's decided rule, exercised: skew degrades toward strictness.
     *
     * A node whose clock runs behind the one that wrote the state sees negative elapsed time. Clamped
     * at zero it refills nothing. Unclamped, `intdiv` of a negative elapsed would move `lastRefill`
     * **backwards**, and the next node to read would over-refill from that rewound instant — the
     * limiter minting tokens out of disagreement about the time.
     */
    public function testABehindClockNodeRefillsZeroAndNeverNegative(): void
    {
        $store = new ArrayRateLimitStore($this->clock);
        $policy = RateLimitPolicy::perWindow(2, new DateInterval('PT20S'));

        $ahead = new RateLimiter($policy, $store, $this->clock);
        $ahead->attempt('login', 'ada');
        $ahead->attempt('login', 'ada');
        self::assertFalse($ahead->attempt('login', 'ada')->allowed());

        // A second node, one hour behind the first, reading the same state.
        $behindClock = new FrozenClock($this->clock->now()->sub(new DateInterval('PT1H')));
        $behindNode = new RateLimiter($policy, $store, $behindClock);
        self::assertFalse(
            $behindNode->attempt('login', 'ada')->allowed(),
            'the behind-clock node must not grant: it sees no elapsed time, so it refills nothing',
        );

        // And the state it left behind must not have rewound: the on-time node still refuses until
        // a real interval has passed.
        self::assertFalse($ahead->attempt('login', 'ada')->allowed());

        $this->advance(10);
        self::assertTrue(
            $ahead->attempt('login', 'ada')->allowed(),
            'one interval after the skewed read, the on-time node grants exactly one token',
        );
    }

    /**
     * `max(0, …)` is asserted as a **mechanism** as well (ADR-0027).
     *
     * The behavioural test above catches the rewind, but only because the store is shared between
     * two clocks in one process — a shape a future refactor could lose. The clamp itself is one
     * expression, and it is worth pinning as one.
     */
    public function testElapsedTimeIsClampedAtZeroByConstruction(): void
    {
        $source = self::sourceOfAttempt();

        self::assertMatchesRegularExpression(
            '/\$elapsed\s*=\s*\\\\max\(\s*0\s*,/',
            $source,
            'elapsed time must be clamped at zero where it is computed; an unclamped negative '
            . 'elapsed moves lastRefill backwards and the next reader over-refills from it',
        );
    }

    // -----------------------------------------------------------------------------------------
    // Compare-and-swap: bounded, and refusing on exhaustion
    // -----------------------------------------------------------------------------------------

    public function testPersistentCasContentionIsRefusedRatherThanRetriedForever(): void
    {
        $store = new AlwaysConflictingStore();
        $decision = $this->limiter(capacity: 5, store: $store)->attempt('login', 'ada');

        self::assertFalse(
            $decision->allowed(),
            'contention on a throttled key is evidence for denial, not against it — the limiter '
            . 'must never answer "unknown"',
        );
        self::assertSame(
            3,
            $store->writeAttempts,
            'and it must give up after a bounded number of tries: an attacker sets the price of '
            . 'every extra retry',
        );
    }

    public function testASingleCasConflictIsRetriedAndSucceeds(): void
    {
        $store = new ConflictOnceStore(new ArrayRateLimitStore($this->clock));
        $decision = $this->limiter(capacity: 5, store: $store)->attempt('login', 'ada');

        self::assertTrue($decision->allowed(), 'incidental contention must not deny a legitimate attempt');
        self::assertSame(2, $store->writeAttempts);
    }

    /**
     * The bound is a constant, asserted as a **mechanism** (ADR-0027).
     *
     * Behaviour cannot see this one at all: an unbounded loop against a permanently conflicting
     * store does not fail a test, it hangs it — and a hanging suite is diagnosed as a flake or a
     * timeout, not as a missing bound.
     */
    public function testTheCasLoopIsBoundedByAConstant(): void
    {
        $source = self::sourceOfAttempt();

        self::assertStringContainsString(
            '$casAttempt <= self::CAS_ATTEMPTS',
            $source,
            'the retry loop must be bounded by a class constant',
        );
        self::assertDoesNotMatchRegularExpression(
            '/\bwhile\s*\(\s*true\s*\)/',
            $source,
            'an unbounded loop here hangs the suite instead of failing it',
        );

        $bound = (new \ReflectionClass(RateLimiter::class))->getConstant('CAS_ATTEMPTS');
        self::assertIsInt($bound);
        self::assertGreaterThan(1, $bound, 'one attempt is not a retry loop');
        self::assertLessThan(10, $bound, 'a high bound hands an attacker the defender\'s CPU');
    }

    // -----------------------------------------------------------------------------------------
    // The hashed key boundary — injection, traversal, collision
    // -----------------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileKeys(): iterable
    {
        yield 'path traversal' => ['../../../../etc/passwd'];
        yield 'windows traversal' => ['..\\..\\windows\\system32\\config\\sam'];
        yield 'absolute path' => ['/etc/shadow'];
        yield 'null byte' => ["ada\0.bucket"];
        yield 'redis separator' => ['ada:login:*'];
        yield 'sql wildcard' => ["ada%' OR '1'='1"];
        yield 'newlines' => ["ada\r\nSET evil 1"];
        yield 'a kilobyte of padding' => [\str_repeat('a', 1024)];
        yield 'unicode' => ['adaクレデンシャル'];
        yield 'empty' => [''];
        yield 'a directory separator alone' => [\DIRECTORY_SEPARATOR];
        yield 'dot' => ['.'];
        yield 'dot dot' => ['..'];
    }

    /**
     * Whatever the caller supplies, the store sees 64 lowercase hex characters.
     *
     * This is the assertion that makes the file store's filenames safe, and it is made **once**
     * here rather than N times across stores this library will never see — the two-copies-of-the-
     * allowlist failure ADR-0044 spent an item removing.
     */
    #[DataProvider('hostileKeys')]
    public function testEveryKeyReachesTheStoreAsFixedLengthHex(string $key): void
    {
        $store = new RecordingKeyStore();
        $this->limiter(store: $store)->attempt('login', $key);

        self::assertNotSame([], $store->keys);

        foreach ($store->keys as $seen) {
            self::assertMatchesRegularExpression(
                '/\A[0-9a-f]{64}\z/',
                $seen,
                'a user-controlled byte reached the store: this is where a traversal into the file '
                . 'store\'s directory would come from',
            );
        }
    }

    #[DataProvider('hostileKeys')]
    public function testTheNamespaceIsHashedToo(string $namespace): void
    {
        $store = new RecordingKeyStore();
        $this->limiter(store: $store)->attempt($namespace, 'ada');

        foreach ($store->keys as $seen) {
            self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $seen);
        }
    }

    /**
     * The length prefixes are domain separation, and this is the collision they prevent.
     *
     * Without them, `('ab', 'c')` and `('a', 'bc')` concatenate to the same bytes and share one
     * bucket — so a caller could exhaust another namespace's limit by choosing a key that pushes the
     * boundary. Behaviour sees it here as two independent buckets.
     */
    public function testAdjacentNamespaceAndKeySplitsDoNotCollide(): void
    {
        $limiter = $this->limiter(capacity: 1);

        self::assertTrue($limiter->attempt('ab', 'c')->allowed());
        self::assertFalse($limiter->attempt('ab', 'c')->allowed());
        self::assertTrue(
            $limiter->attempt('a', 'bc')->allowed(),
            'the length prefixes must keep these two apart; without them they are the same bytes',
        );
    }

    public function testTheKeyPathIsHashThenLookupWithNoComparison(): void
    {
        $source = self::sourceOf('storageKeyFor');

        self::assertStringContainsString("hash('sha256'", $source);
        self::assertStringContainsString('Uint64::encode(\strlen($namespace))', $source);
        self::assertStringContainsString('Uint64::encode(\strlen($key))', $source);

        foreach (['hash_equals', 'strcmp', '===', '=='] as $comparison) {
            self::assertStringNotContainsString(
                $comparison,
                $source,
                'the key path compares nothing: it hashes and hands the digest on, which is what '
                . 'makes it content-oblivious by construction rather than by audit',
            );
        }
    }

    // -----------------------------------------------------------------------------------------
    // A store failure is never a decision
    // -----------------------------------------------------------------------------------------

    public function testAStoreFailureOnReadPropagates(): void
    {
        $this->expectException(RateLimitStoreException::class);
        $this->limiter(store: new FailingStore())->attempt('login', 'ada');
    }

    public function testAStoreFailureOnWritePropagates(): void
    {
        $this->expectException(RateLimitStoreException::class);
        $this->limiter(store: new FailingOnWriteStore())->attempt('login', 'ada');
    }

    public function testAStoreThatManglesTheStateIsRefusedRatherThanGuessed(): void
    {
        $this->expectException(RateLimitStoreException::class);
        $this->limiter(store: new ManglingStore())->attempt('login', 'ada');
    }

    // -----------------------------------------------------------------------------------------
    // The decision value
    // -----------------------------------------------------------------------------------------

    public function testRetryAfterSecondsRoundsUpSoAClientIsNeverToldToComeBackTooEarly(): void
    {
        $limiter = $this->limiter(capacity: 1, window: 'PT10S');
        $limiter->attempt('login', 'ada');

        $this->advance(9);
        $refused = $limiter->attempt('login', 'ada');

        self::assertFalse($refused->allowed());
        self::assertSame(1_000_000, $refused->retryAfterMicros());
        self::assertSame(1, $refused->retryAfterSeconds());
    }

    /**
     * A **fractional** wait, which is the only shape that can see the rounding direction.
     *
     * The first version of this test used a whole-second interval, where `ceil` and `floor` agree —
     * so it asserted the value and not the rule, and a plant that swapped the rounding stayed green.
     * Two tokens over three seconds is 1.5 s each: rounded up that is 2, rounded down 1, and a
     * client that comes back after 1 s is refused again for another half second.
     */
    public function testAFractionalWaitRoundsUpRatherThanDown(): void
    {
        $limiter = new RateLimiter(
            RateLimitPolicy::of(capacity: 1, refillTokens: 2, per: new DateInterval('PT3S')),
            new ArrayRateLimitStore($this->clock),
            $this->clock,
        );
        $limiter->attempt('login', 'ada');

        $refused = $limiter->attempt('login', 'ada');

        self::assertFalse($refused->allowed());
        self::assertSame(1_500_000, $refused->retryAfterMicros());
        self::assertSame(
            2,
            $refused->retryAfterSeconds(),
            'a client told to retry after 1 s comes back half a second early and is refused again, '
            . 'which from its side is indistinguishable from a broken limit',
        );
    }

    public function testASubSecondWaitNeverReportsZeroSeconds(): void
    {
        $limiter = new RateLimiter(
            RateLimitPolicy::of(capacity: 1, refillTokens: 4, per: new DateInterval('PT1S')),
            new ArrayRateLimitStore($this->clock),
            $this->clock,
        );
        $limiter->attempt('login', 'ada');

        $refused = $limiter->attempt('login', 'ada');

        self::assertFalse($refused->allowed());
        self::assertSame(250_000, $refused->retryAfterMicros());
        self::assertSame(
            1,
            $refused->retryAfterSeconds(),
            'a client told to retry after 0 s retries immediately and is refused again',
        );
    }

    // -----------------------------------------------------------------------------------------

    /**
     * How many of `$attempts` consecutive attempts were granted.
     *
     * Extracted rather than inlined three times, and PHPStan is the reason it had to be: inside one
     * method it memoizes an identical `$limiter->attempt(...)->allowed()` call and then reports the
     * loop's condition as always true or always false, because nothing tells it the limiter is
     * stateful. In its own method there is no earlier identical call to remember.
     */
    private static function countAllowed(RateLimiter $limiter, int $attempts): int
    {
        $allowed = 0;

        for ($i = 0; $i < $attempts; $i++) {
            if ($limiter->attempt('login', 'ada')->allowed()) {
                $allowed++;
            }
        }

        return $allowed;
    }

    private static function sourceOfAttempt(): string
    {
        return self::sourceOf('attempt');
    }

    private static function sourceOf(string $method): string
    {
        $reflected = new \ReflectionMethod(RateLimiter::class, $method);
        $lines = \file((string) $reflected->getFileName());
        self::assertIsArray($lines);

        return \implode('', \array_slice(
            $lines,
            $reflected->getStartLine() - 1,
            $reflected->getEndLine() - $reflected->getStartLine() + 1,
        ));
    }
}

/** Records the keys the limiter hands down, and otherwise behaves like an empty store. */
final class RecordingKeyStore implements RateLimitStore
{
    /** @var list<string> */
    public array $keys = [];

    public function read(string $key): ?RateLimitRecord
    {
        $this->keys[] = $key;

        return null;
    }

    public function writeIfVersion(string $key, string $state, int $ttlMicros, ?string $expectedVersion): bool
    {
        $this->keys[] = $key;

        return true;
    }
}

/** Never accepts a write — the permanent-contention shape. */
final class AlwaysConflictingStore implements RateLimitStore
{
    public int $writeAttempts = 0;

    public function read(string $key): ?RateLimitRecord
    {
        return null;
    }

    public function writeIfVersion(string $key, string $state, int $ttlMicros, ?string $expectedVersion): bool
    {
        $this->writeAttempts++;

        return false;
    }
}

/** Loses the first CAS, then delegates — incidental contention. */
final class ConflictOnceStore implements RateLimitStore
{
    public int $writeAttempts = 0;

    public function __construct(private readonly RateLimitStore $inner)
    {
    }

    public function read(string $key): ?RateLimitRecord
    {
        return $this->inner->read($key);
    }

    public function writeIfVersion(string $key, string $state, int $ttlMicros, ?string $expectedVersion): bool
    {
        $this->writeAttempts++;

        if ($this->writeAttempts === 1) {
            return false;
        }

        return $this->inner->writeIfVersion($key, $state, $ttlMicros, $expectedVersion);
    }
}

final class FailingStore implements RateLimitStore
{
    public function read(string $key): ?RateLimitRecord
    {
        throw new RateLimitStoreException('the backend is unreachable');
    }

    public function writeIfVersion(string $key, string $state, int $ttlMicros, ?string $expectedVersion): bool
    {
        throw new RateLimitStoreException('the backend is unreachable');
    }
}

final class FailingOnWriteStore implements RateLimitStore
{
    public function read(string $key): ?RateLimitRecord
    {
        return null;
    }

    public function writeIfVersion(string $key, string $state, int $ttlMicros, ?string $expectedVersion): bool
    {
        throw new RateLimitStoreException('the backend went away mid-request');
    }
}

/** Hands back state of the wrong width — a store that rewrote the limiter's opaque bytes. */
final class ManglingStore implements RateLimitStore
{
    public function read(string $key): RateLimitRecord
    {
        return new RateLimitRecord('not sixteen bytes of state at all', 'v1');
    }

    public function writeIfVersion(string $key, string $state, int $ttlMicros, ?string $expectedVersion): bool
    {
        return true;
    }
}
