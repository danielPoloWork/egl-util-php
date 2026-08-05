<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Http;

use D4np\Utils\Http\CsrfToken;
use D4np\Utils\Support\HttpException;
use D4np\Utils\Tests\Http\Fixture\ArraySessionStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Spec FR-12's CSRF tokens.
 *
 * Testable at all only because `CsrfToken` takes a {@see \D4np\Utils\Http\SessionStore} rather
 * than reaching for `$_SESSION`: PHP will not start a session in CLI, so the alternative was
 * leaving CSRF validation to item 6.3's integration suite alone — the last thing that should rest
 * on an integration suite alone.
 */
#[Group('T-03')]
final class CsrfTokenTest extends TestCase
{
    private ArraySessionStore $store;

    private CsrfToken $csrf;

    protected function setUp(): void
    {
        $this->store = new ArraySessionStore();
        $this->csrf = new CsrfToken($this->store);
    }

    public function testAnIssuedTokenValidates(): void
    {
        self::assertTrue($this->csrf->validate($this->csrf->issue()));
    }

    /**
     * 32 CSPRNG bytes as 64 hex characters. The predictable-source mistakes — `uniqid()`,
     * `mt_rand()`, a hash of session data — are the ones that keep recurring, so the shape is
     * pinned.
     */
    public function testTheTokenIsSixtyFourHexCharacters(): void
    {
        self::assertSame(1, preg_match('/^[0-9a-f]{64}\z/', $this->csrf->issue()));
    }

    /**
     * Two sessions must not receive the same token. A collision here would mean the generator is
     * not what it claims to be.
     */
    public function testTokensDifferBetweenSessions(): void
    {
        $tokens = [];

        for ($i = 0; $i < 50; $i++) {
            $tokens[] = (new CsrfToken(new ArraySessionStore()))->issue();
        }

        self::assertCount(50, array_unique($tokens));
    }

    /**
     * Stable within a session: re-issuing on every render would invalidate the token already
     * sitting in another open tab.
     */
    public function testIssuingTwiceReturnsTheSameTokenWithinASession(): void
    {
        self::assertSame($this->csrf->issue(), $this->csrf->issue());
    }

    public function testAWrongTokenDoesNotValidate(): void
    {
        $this->csrf->issue();

        self::assertFalse($this->csrf->validate(bin2hex(random_bytes(32))));
    }

    /**
     * Every "not authorised" case is the same answer to the caller, and none of them throws —
     * throwing would push callers into a `try` around a routine branch.
     *
     * @return iterable<string, array{string}>
     */
    public static function rejectedTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['abc'];
        yield 'right length, wrong value' => [str_repeat('0', 64)];
        yield 'prefix of the real token' => ['deadbeef'];
        yield 'non-hex' => [str_repeat('z', 64)];
    }

    #[DataProvider('rejectedTokens')]
    public function testRejectedTokensReturnFalseRatherThanThrowing(string $candidate): void
    {
        $this->csrf->issue();

        self::assertFalse($this->csrf->validate($candidate));
    }

    public function testValidationFailsWhenNoTokenHasBeenIssued(): void
    {
        self::assertFalse($this->csrf->validate(bin2hex(random_bytes(32))));
        self::assertFalse($this->csrf->validate(''));
    }

    /*
     * The constant-time assertion for `validate()` used to live here.
     *
     * It moved to `Security\ConstantTimeComparisonTest` when spec revision **r2** made the mechanism
     * assertion a requirement across *every* secret-comparison path rather than this one method, and
     * added a registry-completeness guard so a new path cannot go unasserted. Two copies of the same
     * property in two files is how they drift, so this is a pointer rather than a duplicate.
     *
     * The property is unchanged and still probe-verified: `hash_equals()` → `===` fails there.
     */

    // ---- per-form scoping ------------------------------------------------------------------------

    public function testScopesGetSeparateTokens(): void
    {
        $login = $this->csrf->issue('login');
        $checkout = $this->csrf->issue('checkout');

        self::assertNotSame($login, $checkout);
        self::assertTrue($this->csrf->validate($login, 'login'));
        self::assertTrue($this->csrf->validate($checkout, 'checkout'));
    }

    /**
     * The point of scoping: a token issued for one form does not authorise another.
     */
    public function testATokenFromOneScopeDoesNotValidateInAnother(): void
    {
        $login = $this->csrf->issue('login');
        $this->csrf->issue('checkout');

        self::assertFalse($this->csrf->validate($login, 'checkout'));
    }

    public function testScopesAreStoredUnderDistinctPrefixedKeys(): void
    {
        $this->csrf->issue('login');
        $this->csrf->issue('checkout');

        self::assertSame(['_csrf.login', '_csrf.checkout'], array_keys($this->store->entries));
    }

    /**
     * A scope becomes a session-storage key, so a scope taken from user input would let a client
     * grow the session record one key per request. Application-chosen labels only.
     *
     * @return iterable<string, array{string}>
     */
    public static function illegalScopes(): iterable
    {
        yield 'empty' => [''];
        yield 'with a slash' => ['a/b'];
        yield 'with a space' => ['a b'];
        yield 'with a newline' => ["a\nb"];
        yield 'with a null byte' => ["a\0b"];
        yield 'over-long' => [str_repeat('a', 65)];
        yield 'unicode' => ['scopé'];
    }

    #[DataProvider('illegalScopes')]
    public function testIllegalScopeNamesAreRefused(string $scope): void
    {
        $this->expectException(HttpException::class);

        $this->csrf->issue($scope);
    }

    #[DataProvider('illegalScopes')]
    public function testValidationAlsoRefusesAnIllegalScope(string $scope): void
    {
        $this->expectException(HttpException::class);

        $this->csrf->validate('anything', $scope);
    }

    public function testLegalScopeShapesAreAccepted(): void
    {
        foreach (['login', 'check-out', 'form_1', 'a.b', str_repeat('x', 64)] as $scope) {
            self::assertSame(1, preg_match('/^[0-9a-f]{64}\z/', $this->csrf->issue($scope)), $scope);
        }
    }

    // ---- rotation --------------------------------------------------------------------------------

    /**
     * The right thing on a privilege transition: any token issued to the previous identity stops
     * working.
     */
    public function testRotateReplacesTheTokenAndInvalidatesTheOldOne(): void
    {
        $original = $this->csrf->issue('login');
        $rotated = $this->csrf->rotate('login');

        self::assertNotSame($original, $rotated);
        self::assertFalse($this->csrf->validate($original, 'login'));
        self::assertTrue($this->csrf->validate($rotated, 'login'));
    }

    public function testRotateOnlyAffectsItsOwnScope(): void
    {
        $login = $this->csrf->issue('login');
        $checkout = $this->csrf->issue('checkout');

        $this->csrf->rotate('login');

        self::assertFalse($this->csrf->validate($login, 'login'));
        self::assertTrue($this->csrf->validate($checkout, 'checkout'));
    }

    /**
     * Deliberately *not* what `validate()` does — rotating on every successful validation would
     * break the second of two open tabs.
     */
    public function testValidationDoesNotConsumeTheToken(): void
    {
        $token = $this->csrf->issue();

        self::assertTrue($this->csrf->validate($token));
        self::assertTrue($this->csrf->validate($token));
    }

    public function testClearForgetsTheToken(): void
    {
        $token = $this->csrf->issue('login');
        $this->csrf->clear('login');

        self::assertFalse($this->csrf->validate($token, 'login'));
        self::assertSame([], $this->store->entries);
    }

    /**
     * A store that has been emptied out from under the token — a destroyed session — must not
     * validate anything.
     */
    public function testATokenDoesNotSurviveTheStoreBeingCleared(): void
    {
        $token = $this->csrf->issue();
        $this->store->entries = [];

        self::assertFalse($this->csrf->validate($token));
    }
}
