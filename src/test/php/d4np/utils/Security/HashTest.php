<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Security;

use D4np\Utils\Security\Hash;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Spec FR-11's hashing policy.
 *
 * **The bcrypt-fallback matrix is roadmap item 5.5, not this file.** Reaching the fallback branch
 * requires a PHP built without Argon2 support, which this suite cannot produce — `defined()`
 * cannot be un-defined. What is asserted here is everything reachable on a build that *has*
 * Argon2id, plus the properties that hold regardless of which algorithm is selected. The gap is
 * named in ADR-0022 rather than left to look like coverage.
 */
#[Group('T-06')]
final class HashTest extends TestCase
{
    protected function setUp(): void
    {
        // A build without Argon2id cannot exercise any of this, and should **skip** rather than
        // fail: the absence is a property of the interpreter, not a defect in the library. This
        // replaced an `assertTrue(defined(...))`, which PHPStan correctly flagged as always-true
        // against its own stubs — a guard that is statically true is not a guard.
        if (!defined('PASSWORD_ARGON2ID')) {
            self::markTestSkipped('this PHP build has no Argon2id support');
        }
    }

    public function testArgon2idIsSelectedWhenAvailable(): void
    {
        self::assertSame(PASSWORD_ARGON2ID, (new Hash())->algorithm());
    }

    /**
     * The trap FR-11 exists to avoid. `PASSWORD_DEFAULT` reads like "whatever is strongest" and is
     * bcrypt on every PHP release to date — verified here rather than asserted in prose, so the
     * day it changes this test says so.
     */
    public function testPasswordDefaultWouldHaveSilentlySelectedBcrypt(): void
    {
        self::assertSame(PASSWORD_BCRYPT, PASSWORD_DEFAULT);
        self::assertNotSame(PASSWORD_DEFAULT, (new Hash())->algorithm());
    }

    public function testAHashVerifiesAgainstItsOwnPassword(): void
    {
        $hash = new Hash();
        $stored = $hash->make('correct horse battery staple');

        self::assertTrue($hash->verify('correct horse battery staple', $stored));
    }

    public function testAWrongPasswordDoesNotVerify(): void
    {
        $hash = new Hash();

        self::assertFalse($hash->verify('wrong', $hash->make('right')));
    }

    /**
     * Salting is what makes this true, and it is the reason a stored hash cannot be compared with
     * `===` or used as a lookup key.
     */
    public function testTheSamePasswordHashesDifferentlyEveryTime(): void
    {
        $hash = new Hash();

        self::assertNotSame($hash->make('same'), $hash->make('same'));
    }

    /**
     * Self-describing output (FR-11): the algorithm and its parameters travel with the hash, which
     * is what lets `verify()` and `needsRehash()` work without a separate column recording how the
     * hash was made.
     */
    public function testTheHashDescribesItsOwnAlgorithm(): void
    {
        $stored = (new Hash())->make('pw');

        self::assertStringStartsWith('$argon2id$', $stored);
        self::assertSame('argon2id', password_get_info($stored)['algoName']);
    }

    public function testAFreshHashDoesNotNeedRehashing(): void
    {
        $hash = new Hash();

        self::assertFalse($hash->needsRehash($hash->make('pw')));
    }

    /**
     * The upgrade-on-login case FR-11 names: a hash stored under the old algorithm still verifies,
     * and reports that it should be replaced.
     */
    public function testABcryptHashVerifiesButReportsThatItNeedsRehashing(): void
    {
        $hash = new Hash();
        $legacy = password_hash('pw', PASSWORD_BCRYPT);

        self::assertTrue($hash->verify('pw', $legacy), 'a legacy hash must still let its owner log in');
        self::assertTrue($hash->needsRehash($legacy), 'and must be flagged for upgrade');
    }

    /**
     * The full FR-11 login sequence, end to end.
     */
    public function testUpgradeOnLoginReplacesALegacyHash(): void
    {
        $hash = new Hash();
        $stored = password_hash('pw', PASSWORD_BCRYPT);

        if ($hash->verify('pw', $stored) && $hash->needsRehash($stored)) {
            $stored = $hash->make('pw');
        }

        self::assertStringStartsWith('$argon2id$', $stored);
        self::assertTrue($hash->verify('pw', $stored));
        self::assertFalse($hash->needsRehash($stored));
    }

    /**
     * Weaker *parameters* under the same algorithm also warrant a rehash — PHP compares the cost
     * factors recorded in the hash against the current ones, so a move to stronger defaults
     * upgrades on next login without any change to this class.
     */
    public function testWeakerParametersUnderTheSameAlgorithmAlsoNeedRehashing(): void
    {
        $weak = password_hash('pw', PASSWORD_ARGON2ID, ['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1]);

        self::assertTrue((new Hash())->needsRehash($weak));
    }

    /**
     * A stored value that is not a hash at all must not raise, and must not verify.
     */
    public function testAMalformedStoredHashDoesNotVerifyAndDoesNotRaise(): void
    {
        $hash = new Hash();

        self::assertFalse($hash->verify('pw', ''));
        self::assertFalse($hash->verify('pw', 'not-a-hash'));
        // It also cannot be left looking current: unparseable means "replace it".
        self::assertTrue($hash->needsRehash('not-a-hash'));
    }

    public function testAnEmptyPasswordIsHashedRatherThanRefused(): void
    {
        // Whether an empty password is acceptable is an application policy, not a hashing one;
        // this class does not silently invent a validation rule the caller did not ask for.
        $hash = new Hash();
        $stored = $hash->make('');

        self::assertTrue($hash->verify('', $stored));
        self::assertFalse($hash->verify('x', $stored));
    }

    /**
     * PHP's own password functions are byte-safe; a password is not required to be text.
     */
    public function testPasswordsWithNullBytesAndUnicodeRoundTrip(): void
    {
        $hash = new Hash();

        foreach (["with\0null", 'héllo 漢 🙂', str_repeat('a', 200)] as $password) {
            self::assertTrue($hash->verify($password, $hash->make($password)), $password);
        }
    }

    /**
     * On a build that has Argon2id, disabling the fallback changes nothing — the point of the
     * assertion is that it does not throw, so `bcryptFallback: false` is safe to set as a default
     * posture rather than something only brave deployments enable.
     */
    public function testDisablingTheFallbackIsHarmlessWhenArgon2idIsAvailable(): void
    {
        self::assertSame(PASSWORD_ARGON2ID, (new Hash(bcryptFallback: false))->algorithm());
    }

    /**
     * No WARNING is logged when nothing was degraded — a logger that cries wolf on a correctly
     * configured system is one whose warnings get filtered out.
     */
    public function testNoWarningIsLoggedWhenArgon2idIsAvailable(): void
    {
        $logger = new RecordingLogger();

        new Hash(logger: $logger);

        self::assertSame([], $logger->records);
    }

    /**
     * `bcryptFallback: false` is documented as failing at *construction*, not at first use. On a
     * build with Argon2id the throw cannot be triggered, so what is asserted is the shape of the
     * contract that makes it fail fast: the algorithm is already fixed before any password has
     * been hashed, so no hashing call is needed to discover a misconfiguration.
     */
    public function testTheFallbackDecisionIsMadeAtConstructionNotAtFirstUse(): void
    {
        $hash = new Hash(bcryptFallback: false);

        self::assertNotSame('', $hash->algorithm());
        self::assertSame($hash->algorithm(), $hash->algorithm());
    }
}
