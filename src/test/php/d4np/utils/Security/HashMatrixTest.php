<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Security;

use D4np\Utils\Security\Hash;
use D4np\Utils\Support\UtilsException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * Spec §7's *"Hash matrix tests (argon2id/bcrypt fallback, rehash triggers)"* — roadmap 5.5.
 *
 * `HashTest` (item 5.3) covers the behaviour of a correctly configured instance. This covers the
 * two **cross-products** the spec names, exhaustively rather than by example, because a policy
 * tested at three of its four corners is a policy with an untested corner.
 *
 * The fallback half is reachable at all only because item 5.3 extracted `selectAlgorithm()`:
 * `defined('PASSWORD_ARGON2ID')` is a compile-time fact, so the availability has to arrive as an
 * argument or half this matrix could not be written (ADR-0022).
 */
#[Group('T-06')]
final class HashMatrixTest extends TestCase
{
    // ---- the fallback matrix: availability × policy, all four cells ----------------------------

    /**
     * @return iterable<string, array{bool, bool, string|null, bool}>
     */
    public static function fallbackMatrix(): iterable
    {
        //                                    available, fallback, expected algo,     warns
        yield 'available + fallback allowed' => [true, true, PASSWORD_ARGON2ID, false];
        yield 'available + fallback refused' => [true, false, PASSWORD_ARGON2ID, false];
        yield 'missing + fallback allowed' => [false, true, PASSWORD_BCRYPT, true];
        yield 'missing + fallback refused' => [false, false, null, false];
    }

    /**
     * @param string|null $expected the algorithm, or `null` when construction must be refused
     */
    #[DataProvider('fallbackMatrix')]
    public function testEveryCellOfTheFallbackMatrix(
        bool $available,
        bool $fallback,
        ?string $expected,
        bool $warns,
    ): void {
        $logger = new RecordingLogger();

        if ($expected === null) {
            $this->expectException(UtilsException::class);
            Hash::selectAlgorithm($available, $fallback, $logger);

            return;
        }

        self::assertSame($expected, Hash::selectAlgorithm($available, $fallback, $logger));
        self::assertCount($warns ? 1 : 0, $logger->records);

        if ($warns) {
            self::assertSame(LogLevel::WARNING, $logger->records[0]['level']);
        }
    }

    /**
     * The refusing cell, asserted separately for the thing the matrix above cannot check once it
     * has entered `expectException()`: that refusing does **not** also log. A hard failure is
     * already loud, and a WARNING beside it would suggest the run continued in a degraded state.
     */
    public function testTheRefusingCellDoesNotAlsoLog(): void
    {
        $logger = new RecordingLogger();

        try {
            Hash::selectAlgorithm(false, false, $logger);
        } catch (UtilsException) {
            // expected
        }

        self::assertSame([], $logger->records);
    }

    /**
     * Every cell must behave identically with no logger attached — the logger records the
     * decision, it must not influence it.
     */
    #[DataProvider('fallbackMatrix')]
    public function testTheDecisionIsTheSameWithoutALogger(
        bool $available,
        bool $fallback,
        ?string $expected,
        bool $warns,
    ): void {
        if ($expected === null) {
            $this->expectException(UtilsException::class);
            Hash::selectAlgorithm($available, $fallback);

            return;
        }

        self::assertSame($expected, Hash::selectAlgorithm($available, $fallback));
    }

    // ---- the rehash-trigger matrix -------------------------------------------------------------

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function rehashMatrix(): iterable
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            return;
        }

        yield 'current defaults' => [password_hash('pw', PASSWORD_ARGON2ID), false];
        yield 'weaker memory_cost' => [password_hash('pw', PASSWORD_ARGON2ID, ['memory_cost' => 8192]), true];
        yield 'weaker time_cost' => [password_hash('pw', PASSWORD_ARGON2ID, ['time_cost' => 1]), true];
        // Surprising, and real: PHP compares parameters for *equality* with the current defaults,
        // not for "at least as strong". A hash hardened beyond the defaults is therefore also
        // reported as needing a rehash — and would be silently *downgraded* on next login.
        yield 'stronger parameters' => [password_hash('pw', PASSWORD_ARGON2ID, ['memory_cost' => 131072, 'time_cost' => 8]), true];
        yield 'different algorithm (bcrypt)' => [password_hash('pw', PASSWORD_BCRYPT), true];
        yield 'bcrypt at a high cost' => [password_hash('pw', PASSWORD_BCRYPT, ['cost' => 13]), true];
        yield 'malformed' => ['not-a-hash', true];
        yield 'empty' => ['', true];
    }

    #[DataProvider('rehashMatrix')]
    public function testEveryRehashTrigger(string $stored, bool $expected): void
    {
        self::assertSame($expected, (new Hash())->needsRehash($stored));
    }

    /**
     * Every hash in the matrix that was made from the same password must still *verify*, whatever
     * its parameters — otherwise "upgrade on login" could never run, because the login it depends
     * on would already have failed.
     */
    #[DataProvider('rehashMatrix')]
    public function testEveryWellFormedHashInTheMatrixStillVerifies(string $stored, bool $expected): void
    {
        $hash = new Hash();

        if ($stored === '' || $stored === 'not-a-hash') {
            self::assertFalse($hash->verify('pw', $stored));

            return;
        }

        self::assertTrue($hash->verify('pw', $stored));
    }

    // ---- the work factor: NFR-05's security property, without a stopwatch ----------------------

    /**
     * **NFR-05 says "deliberately slow", and the machine-independent way to assert that is the
     * work factor, not the wall clock.**
     *
     * A duration depends on the CPU, the memory bandwidth, and what else is running; a cost
     * parameter does not. If a future PHP lowered its Argon2id defaults, the timing on a fast
     * machine might still look plausible while the actual work factor had dropped — this is the
     * assertion that would catch it.
     *
     * The floor is OWASP's Password Storage recommendation for Argon2id (m ≥ 19456 KiB with
     * t ≥ 2). PHP's current defaults (m = 65536, t = 4) clear it comfortably; the point is that
     * they are *checked* against an external standard rather than trusted because they are the
     * defaults.
     */
    public function testTheArgon2idWorkFactorMeetsTheOwaspFloor(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            self::markTestSkipped('this PHP build has no Argon2id support');
        }

        $options = password_get_info((new Hash())->make('pw'))['options'];

        self::assertIsArray($options);
        self::assertArrayHasKey('memory_cost', $options);
        self::assertArrayHasKey('time_cost', $options);

        self::assertGreaterThanOrEqual(19456, $options['memory_cost'], 'below OWASP\'s Argon2id memory floor');
        self::assertGreaterThanOrEqual(2, $options['time_cost'], 'below OWASP\'s Argon2id time floor');
    }

    /**
     * The library must not be quietly overriding PHP's cost parameters — ADR-0022 decided those
     * belong to PHP, so that this library does not own a number that has to keep moving.
     */
    public function testTheLibraryUsesPhpsOwnDefaultsRatherThanItsOwn(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            self::markTestSkipped('this PHP build has no Argon2id support');
        }

        $ours = password_get_info((new Hash())->make('pw'))['options'];
        $phps = password_get_info(password_hash('pw', PASSWORD_ARGON2ID))['options'];

        self::assertSame($phps, $ours);
    }
}
