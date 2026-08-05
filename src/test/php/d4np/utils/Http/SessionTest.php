<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Http;

use D4np\Utils\Http\SameSite;
use D4np\Utils\Http\Session;
use D4np\Utils\Http\SessionStore;
use D4np\Utils\Support\HttpException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Spec FR-15's session hardening.
 *
 * **What this file can and cannot cover, stated up front.** PHP will not run a session in CLI —
 * `session_start()`, `session_set_cookie_params()` and `session_regenerate_id()` all return
 * `false`, verified — so `start()` and `regenerate()` cannot be exercised here at all. That is
 * exactly why the cookie **policy** is a pure value (`cookieParams()`): it lets FR-15's three
 * flags be asserted without a live session, which is the half that would otherwise have no unit
 * test whatsoever.
 *
 * The behaviour against a real server — that the cookie actually carries the flags, that the
 * session identifier actually changes across `regenerate()` — is roadmap item **6.3**'s
 * integration suite against a `php -S` process. Named here rather than left as a silent hole.
 */
#[Group('T-03')]
final class SessionTest extends TestCase
{
    /**
     * FR-15's three flags, asserted as a value because they cannot be asserted as behaviour here.
     */
    public function testTheCookiePolicyCarriesFr15sThreeFlags(): void
    {
        $params = (new Session())->cookieParams();

        self::assertTrue($params['httponly'], 'a session id readable from JavaScript defeats the point');
        self::assertTrue($params['secure'], 'the id must never travel over plain HTTP');
        self::assertSame('Lax', $params['samesite'], 'a second, independent line against CSRF');
    }

    /**
     * A session cookie, not a persistent one: it dies with the browser session rather than sitting
     * on disk with an expiry the server cannot revoke.
     */
    public function testTheCookieIsASessionCookie(): void
    {
        self::assertSame(0, (new Session())->cookieParams()['lifetime']);
    }

    public function testThePathIsConfigurable(): void
    {
        self::assertSame('/', (new Session())->cookieParams()['path']);
        self::assertSame('/app', (new Session(path: '/app'))->cookieParams()['path']);
    }

    /**
     * `secure: false` exists for local `http://` development and nothing else. It is a named
     * argument rather than an auto-detection precisely so it shows up in the wiring — the same
     * shape as `Hash`'s `bcryptFallback`.
     */
    public function testSecureCanBeDisabledExplicitlyForLocalHttp(): void
    {
        self::assertFalse((new Session(secure: false))->cookieParams()['secure']);
    }

    /**
     * `httponly` has no opt-out at all: no legitimate caller needs the session identifier readable
     * from JavaScript, and offering the switch would be offering the vulnerability.
     */
    public function testHttpOnlyHasNoOptOut(): void
    {
        foreach ([new Session(), new Session(secure: false), new Session(sameSite: SameSite::Strict)] as $session) {
            self::assertTrue($session->cookieParams()['httponly']);
        }
    }

    public function testSameSiteIsConfigurableAmongTheLegalValues(): void
    {
        self::assertSame('Strict', (new Session(sameSite: SameSite::Strict))->cookieParams()['samesite']);
        self::assertSame('None', (new Session(sameSite: SameSite::None))->cookieParams()['samesite']);
    }

    /**
     * There is no "illegal SameSite value" test, and its absence is the point.
     *
     * `SameSite` is a closed enum, so an illegal value is a compile-time impossibility rather than
     * a runtime check — the same reasoning ADR-0015 applied to `Sort` and `Operator`, and what
     * PHPStan pushed this toward by typing `session_set_cookie_params()` against a literal union.
     * What is worth asserting instead is that the set is exactly the three the specification
     * defines, so widening it stays a deliberate act.
     */
    public function testSameSiteIsAClosedSetOfExactlyThreePolicies(): void
    {
        self::assertSame(['Lax', 'Strict', 'None'], array_map(
            static fn (SameSite $case): string => $case->value,
            SameSite::cases(),
        ));
    }

    /**
     * The one constraint the type system cannot express, because it spans two arguments: browsers
     * **drop** a `SameSite=None` cookie that is not `Secure`. Refusing at construction turns that
     * into a wiring-time error instead of "sessions do not work" in production.
     */
    public function testSameSiteNoneWithoutSecureIsRefused(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/requires a Secure cookie/');

        new Session(sameSite: SameSite::None, secure: false);
    }

    // ---- storage --------------------------------------------------------------------------------

    public function testItIsASessionStore(): void
    {
        self::assertInstanceOf(SessionStore::class, new Session());
    }

    /**
     * Reads and writes go through `$_SESSION`, which is an ordinary superglobal here even though
     * no session is active — enough to assert the storage contract `CsrfToken` depends on.
     */
    public function testStorageRoundTripsThroughTheSessionSuperglobal(): void
    {
        $_SESSION = [];
        $session = new Session();

        self::assertNull($session->get('absent'));

        $session->set('k', 'v');
        self::assertSame('v', $session->get('k'));
        self::assertSame('v', $_SESSION['k'] ?? null);

        $session->remove('k');
        self::assertNull($session->get('k'));

        $_SESSION = [];
    }

    /**
     * A session written by other code can hold anything. Casting an array or object to a string
     * here would be the same coercion `Request` refuses, for the same reason (ADR-0025).
     */
    public function testNonStringSessionValuesReadBackAsNullRatherThanBeingCoerced(): void
    {
        $_SESSION = ['arr' => ['a'], 'obj' => new \stdClass(), 'int' => 42, 'null' => null];
        $session = new Session();

        self::assertNull($session->get('arr'));
        self::assertNull($session->get('obj'));
        self::assertNull($session->get('int'));
        self::assertNull($session->get('null'));

        $_SESSION = [];
    }

    public function testDestroyEmptiesTheSessionData(): void
    {
        $_SESSION = ['a' => '1', 'b' => '2'];

        (new Session())->destroy();

        self::assertSame([], $_SESSION);
    }

    // ---- the guards that are reachable without a live session ------------------------------------

    /**
     * `regenerate()` before `start()` is a programming error, and saying so is more useful than
     * PHP's own warning-and-`false`.
     */
    public function testRegenerateBeforeStartIsRefused(): void
    {
        self::assertNotSame(PHP_SESSION_ACTIVE, session_status(), 'this test assumes no active session');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/before the session has started/');

        (new Session())->regenerate();
    }
}
