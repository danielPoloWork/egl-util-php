<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Http;

use D4np\Utils\Http\NativeSessionApi;
use D4np\Utils\Http\SameSite;
use D4np\Utils\Http\Session;
use D4np\Utils\Http\SessionStore;
use D4np\Utils\Support\HttpException;
use D4np\Utils\Tests\Http\Fixture\RecordingSessionApi;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Spec FR-15's session hardening.
 *
 * **What this file can and cannot cover, stated up front.** PHP will not run a session in CLI —
 * `session_start()`, `session_set_cookie_params()` and `session_regenerate_id()` all return
 * `false`, verified. Two structural moves put the logic back in reach anyway: the cookie **policy**
 * is a pure value (`cookieParams()`), and the session functions come through a `SessionApi` seam
 * (ADR-0026 §8) that a fake can record.
 *
 * The second move exists mostly for one property. `start()` must apply the cookie parameters
 * *before* the session starts; applied after, they have no effect and the cookie goes out without
 * FR-15's flags — a session that works perfectly and is unprotected. Both orderings produce a
 * working session, so nothing about the outcome distinguishes them. Only the call sequence does,
 * which is what `RecordingSessionApi` makes visible.
 *
 * What remains outside this file is genuinely behavioural: that a real browser cookie carries the
 * flags, and that a real session identifier changes across `regenerate()`. That is roadmap item
 * **6.3**'s integration suite against a `php -S` process.
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
        self::assertSame(['Lax', 'Strict', 'None'], \array_map(
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

    // ---- start(): ordering, guards and error paths, through the seam -----------------------------

    /**
     * **The reason the seam exists.**
     *
     * `session_set_cookie_params()` has no effect once the session has started. Get the order wrong
     * and everything still works — a session is created, values round-trip, tests pass — except the
     * cookie went out without `httponly`, `secure` or `samesite`. The failure is invisible in every
     * observable outcome, so the sequence itself has to be the assertion.
     */
    public function testStartAppliesTheCookiePolicyBeforeStartingTheSession(): void
    {
        $api = new RecordingSessionApi();

        (new Session(api: $api))->start();

        self::assertSame(
            ['setCookieParams', 'start'],
            $api->calls,
            'parameters set after start() are silently ignored, and the cookie loses FR-15 flags',
        );
    }

    /**
     * And the policy that reaches PHP is the same value `cookieParams()` publishes — otherwise the
     * flag assertions above would be testing a value nothing consumes.
     */
    public function testStartAppliesExactlyThePublishedPolicy(): void
    {
        $api = new RecordingSessionApi();
        $session = new Session(sameSite: SameSite::Strict, path: '/app', api: $api);

        $session->start();

        self::assertSame($session->cookieParams(), $api->params);
    }

    /**
     * A request reaching two entry points should not fail on the second, so an already-active
     * session is a no-op — and, importantly, must not re-apply parameters that would be ignored.
     */
    public function testStartOnAnAlreadyActiveSessionDoesNothing(): void
    {
        $api = new RecordingSessionApi(status: PHP_SESSION_ACTIVE);

        (new Session(api: $api))->start();

        self::assertSame([], $api->calls);
    }

    public function testStartRefusesWhenSessionsAreDisabledInTheBuild(): void
    {
        $api = new RecordingSessionApi(status: PHP_SESSION_DISABLED);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/disabled in this PHP build/');

        (new Session(api: $api))->start();
    }

    /**
     * If the flags cannot be applied, the session must **not** start. Starting anyway would produce
     * exactly the unprotected session this class exists to prevent, and would do it silently.
     */
    public function testStartDoesNotStartASessionWhoseCookiePolicyCouldNotBeApplied(): void
    {
        $api = new RecordingSessionApi(setCookieParamsSucceeds: false);

        try {
            (new Session(api: $api))->start();
            self::fail('expected the failed cookie policy to be refused');
        } catch (HttpException $e) {
            self::assertMatchesRegularExpression('/Could not apply the session cookie parameters/', $e->getMessage());
        }

        self::assertSame(['setCookieParams'], $api->calls, 'an unprotected session must never be started');
    }

    public function testStartRefusesWhenTheSessionCannotBeStarted(): void
    {
        $api = new RecordingSessionApi(startSucceeds: false);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/Could not start the session/');

        (new Session(api: $api))->start();
    }

    // ---- regenerate(): the session-fixation defence ----------------------------------------------

    /**
     * `regenerate()` before `start()` is a programming error, and saying so is more useful than
     * PHP's own warning-and-`false`.
     */
    public function testRegenerateBeforeStartIsRefused(): void
    {
        $api = new RecordingSessionApi();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/before the session has started/');

        (new Session(api: $api))->regenerate();
    }

    public function testRegenerateReplacesTheIdentifierOnceTheSessionIsRunning(): void
    {
        $api = new RecordingSessionApi();
        $session = new Session(api: $api);

        $session->start();
        $session->regenerate();

        self::assertSame(['setCookieParams', 'start', 'regenerateId'], $api->calls);
    }

    /**
     * A failed regeneration is refused rather than ignored: swallowing it leaves the caller
     * believing a privilege transition rotated the identifier when it did not, which is the whole
     * of session fixation.
     */
    public function testRegenerateRefusesWhenPhpCannotRegenerate(): void
    {
        $api = new RecordingSessionApi(regenerateSucceeds: false);
        $session = new Session(api: $api);
        $session->start();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/Could not regenerate/');

        $session->regenerate();
    }

    // ---- destroy() -------------------------------------------------------------------------------

    public function testDestroyDestroysTheServerSideRecordWhenOneExists(): void
    {
        $_SESSION = ['a' => '1'];
        $api = new RecordingSessionApi(status: PHP_SESSION_ACTIVE);

        (new Session(api: $api))->destroy();

        self::assertSame([], $_SESSION);
        self::assertSame(['destroy'], $api->calls);
    }

    public function testDestroyClearsTheDataWithoutCallingPhpWhenNoSessionIsActive(): void
    {
        $_SESSION = ['a' => '1'];
        $api = new RecordingSessionApi();

        (new Session(api: $api))->destroy();

        self::assertSame([], $_SESSION);
        self::assertSame([], $api->calls, 'session_destroy() without an active session only emits a warning');
    }

    // ---- the seam must not become the production path --------------------------------------------

    /**
     * Everything above runs against a fake, which is only meaningful if the real wiring is the real
     * thing. A default quietly changed to a test double would leave this whole file green while the
     * library did nothing.
     */
    public function testTheDefaultSessionApiIsPhpsOwn(): void
    {
        $api = (new \ReflectionProperty(Session::class, 'api'))->getValue(new Session());

        self::assertInstanceOf(NativeSessionApi::class, $api);
    }
}
