<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Http;

use D4np\Utils\Tests\Http\Fixture\BrowserClient;
use D4np\Utils\Tests\Http\Fixture\DevServer;
use D4np\Utils\Tests\Http\Fixture\HttpExchange;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Spec §7's **T-03**: session and CSRF behaviour against a real `php -S` process.
 *
 * This suite owns everything the unit tests structurally cannot reach. PHP returns `false` from
 * `session_start()`, `session_set_cookie_params()` and `session_regenerate_id()` in CLI, so
 * ADR-0026 got as far as asserting the cookie *policy* as a value and the *call sequence* through a
 * seam — but whether a real `Set-Cookie` carries FR-15's flags, and whether a real identifier
 * actually rotates, could only ever be answered here.
 *
 * Two findings from probing shaped it:
 *
 * - **`Secure` is emitted over plain HTTP.** PHP writes the attribute unconditionally; enforcement
 *   is the browser's job. So the flag is assertable against `php -S` with no TLS anywhere, which is
 *   what makes this suite possible at all.
 * - **A failure to start the server fails the suite; it does not skip it.** `php -S` ships with PHP
 *   and this project's CI already spawns processes, so there is no environment where skipping would
 *   be the honest answer — and a suite that skips itself into silence is how T-03 would quietly
 *   stop running without anyone noticing.
 *
 * Note that these tests contribute **no line coverage**: the library code runs inside the server
 * process, not this one. They prove behaviour, not reach.
 */
#[Group('T-03')]
final class SessionCsrfIntegrationTest extends TestCase
{
    private static ?DevServer $server = null;

    public static function setUpBeforeClass(): void
    {
        $server = new DevServer(dirname(__DIR__, 4) . '/resources/t03-server');
        $failure = $server->start();

        if ($failure !== '') {
            $server->stop();
            self::fail("T-03 needs a live `php -S` and could not get one: {$failure}");
        }

        self::$server = $server;
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    private function get(BrowserClient $client, string $query): HttpExchange
    {
        $exchange = $client->get(self::$server?->url($query) ?? '');

        self::assertSame('', $exchange->curlError, 'the request never reached the server');
        self::assertSame(200, $exchange->status, sprintf(
            "expected 200 for `%s`, got %d.\nbody: %s\nserver log: %s",
            $query,
            $exchange->status,
            $exchange->body,
            self::$server?->log() ?? '',
        ));

        return $exchange;
    }

    // ---- FR-15: the flags, on the wire -----------------------------------------------------------

    /**
     * The assertion ADR-0026 could only make against a value. Here it is against the header a
     * browser would actually receive.
     */
    public function testTheSessionCookieCarriesFr15sThreeFlags(): void
    {
        $cookie = $this->get(new BrowserClient(), 'action=id')->sessionCookie();

        self::assertNotNull($cookie, 'no session cookie was set at all');
        self::assertMatchesRegularExpression('/;\s*HttpOnly/i', $cookie, 'an XSS could read the session id');
        self::assertMatchesRegularExpression('/;\s*secure/i', $cookie, 'the id could travel over plain HTTP');
        self::assertMatchesRegularExpression('/;\s*SameSite=Lax/i', $cookie, 'no browser-side CSRF defence');
    }

    /**
     * `Secure` is written even though this exchange is plain HTTP — PHP emits the attribute and
     * leaves enforcement to the browser. Asserted explicitly because it is the non-obvious fact the
     * whole suite depends on, and someone reasonably assuming the opposite would conclude these
     * tests cannot work without TLS.
     */
    public function testSecureIsEmittedEvenOverPlainHttp(): void
    {
        $exchange = $this->get(new BrowserClient(), 'action=id');

        self::assertStringStartsWith('http://', self::$server?->url() ?? '');
        self::assertMatchesRegularExpression('/;\s*secure/i', (string) $exchange->sessionCookie());
    }

    /**
     * The policy is not hard-coded on its way out: constructor arguments reach the header.
     */
    public function testTheConfiguredPolicyReachesTheHeader(): void
    {
        $strict = $this->get(new BrowserClient(), 'action=id&samesite=Strict')->sessionCookie();
        self::assertMatchesRegularExpression('/;\s*SameSite=Strict/i', (string) $strict);

        $insecure = $this->get(new BrowserClient(), 'action=id&secure=0')->sessionCookie();
        self::assertDoesNotMatchRegularExpression('/;\s*secure/i', (string) $insecure);

        $scoped = $this->get(new BrowserClient(), 'action=id&path=/app')->sessionCookie();
        self::assertMatchesRegularExpression('#;\s*path=/app#i', (string) $scoped);
    }

    /**
     * `httponly` has no opt-out in the API, so there is no query parameter that could remove it —
     * asserted against the wire so the guarantee is proven where it is actually consumed.
     */
    public function testHttpOnlySurvivesEveryOtherPolicyChoice(): void
    {
        foreach (['secure=0', 'samesite=Strict', 'secure=0&samesite=Strict&path=/app'] as $variant) {
            $cookie = (string) $this->get(new BrowserClient(), "action=id&{$variant}")->sessionCookie();
            self::assertMatchesRegularExpression('/;\s*HttpOnly/i', $cookie, "lost HttpOnly with {$variant}");
        }
    }

    // ---- the session actually is a session -------------------------------------------------------

    public function testStateSurvivesAcrossRequests(): void
    {
        $client = new BrowserClient();

        $this->get($client, 'action=set&key=colour&value=green');

        self::assertSame('green', $this->get($client, 'action=get&key=colour')->body);
    }

    public function testTwoClientsGetIndependentSessions(): void
    {
        $alice = new BrowserClient();
        $bob = new BrowserClient();

        $this->get($alice, 'action=set&key=colour&value=green');
        $this->get($bob, 'action=set&key=colour&value=blue');

        self::assertSame('green', $this->get($alice, 'action=get&key=colour')->body);
        self::assertSame('blue', $this->get($bob, 'action=get&key=colour')->body);
    }

    // ---- FR-15: regenerate(), the session-fixation defence ----------------------------------------

    public function testRegenerateChangesTheSessionIdentifier(): void
    {
        $client = new BrowserClient();

        $before = $this->get($client, 'action=id')->body;
        $after = $this->get($client, 'action=regenerate')->body;

        self::assertNotSame('', $before);
        self::assertNotSame($before, $after, 'the identifier did not rotate');
    }

    public function testRegenerateKeepsTheSessionDataUnderTheNewIdentifier(): void
    {
        $client = new BrowserClient();

        $this->get($client, 'action=set&key=user&value=alice');
        $this->get($client, 'action=regenerate');

        self::assertSame(
            'alice',
            $this->get($client, 'action=get&key=user')->body,
            'rotating the identifier must not log the user out',
        );
    }

    /**
     * **The point of the whole feature.**
     *
     * `session_regenerate_id(true)` deletes the old record. Without the `true` the session is merely
     * renamed and the previous identifier keeps working — so an attacker who fixed a victim's
     * identifier before login still holds a valid one afterwards. That is session fixation, and it
     * is invisible to every other test here: rotation still happens, data still survives, and the
     * old identifier quietly stays valid.
     *
     * So this replays the pre-rotation identifier deliberately and asserts the server treats it as a
     * stranger.
     */
    public function testTheIdentifierFromBeforeRegenerateIsDeadAfterwards(): void
    {
        $client = new BrowserClient();

        $this->get($client, 'action=set&key=user&value=alice');
        $stale = (string) $client->cookie('PHPSESSID');
        self::assertNotSame('', $stale);

        $this->get($client, 'action=regenerate');
        $rotated = (string) $client->cookie('PHPSESSID');
        self::assertNotSame($stale, $rotated, 'nothing rotated, so this test proves nothing');

        // A second visitor presenting the identifier the attacker would be holding.
        $attacker = new BrowserClient();
        $attacker->presentCookie('PHPSESSID', $stale);

        self::assertSame(
            '(null)',
            $this->get($attacker, 'action=get&key=user')->body,
            'the pre-rotation identifier still reaches the session: this is session fixation',
        );
    }

    public function testDestroyEndsTheSession(): void
    {
        $client = new BrowserClient();

        $this->get($client, 'action=set&key=user&value=alice');
        $this->get($client, 'action=destroy');

        self::assertSame('(null)', $this->get($client, 'action=get&key=user')->body);
    }

    // ---- FR-12: CSRF across real requests --------------------------------------------------------

    public function testATokenValidatesWithinItsOwnSession(): void
    {
        $client = new BrowserClient();

        $token = $this->get($client, 'action=issue&scope=login')->body;

        self::assertSame(64, strlen($token), '32 CSPRNG bytes, hex-encoded');
        self::assertSame('valid', $this->get($client, "action=validate&scope=login&token={$token}")->body);
    }

    /**
     * A token is issued once per scope and reused, so the value stays put across renders — ADR-0026
     * §5, where regenerating per request was rejected for invalidating a second open tab.
     */
    public function testATokenIsStableAcrossRequestsWithinAScope(): void
    {
        $client = new BrowserClient();

        $first = $this->get($client, 'action=issue&scope=login')->body;
        $second = $this->get($client, 'action=issue&scope=login')->body;

        self::assertSame($first, $second);
    }

    /**
     * **The cross-session rejection spec §7 names.** A token is worthless outside the session it was
     * issued to — which is what stops an attacker from fetching a valid token from their own session
     * and posting it with the victim's cookie.
     */
    public function testATokenFromAnotherSessionIsRejected(): void
    {
        $attacker = new BrowserClient();
        $victim = new BrowserClient();

        $stolen = $this->get($attacker, 'action=issue&scope=login')->body;
        $this->get($victim, 'action=issue&scope=login');

        self::assertSame(
            'invalid',
            $this->get($victim, "action=validate&scope=login&token={$stolen}")->body,
            'a token minted in one session was accepted in another',
        );
    }

    public function testATokenIsRejectedOutsideItsScope(): void
    {
        $client = new BrowserClient();

        $token = $this->get($client, 'action=issue&scope=login')->body;
        $this->get($client, 'action=issue&scope=transfer');

        self::assertSame('invalid', $this->get($client, "action=validate&scope=transfer&token={$token}")->body);
    }

    public function testAWrongTokenIsRejected(): void
    {
        $client = new BrowserClient();

        $this->get($client, 'action=issue&scope=login');

        self::assertSame('invalid', $this->get($client, 'action=validate&scope=login&token=' . str_repeat('0', 64))->body);
        self::assertSame('invalid', $this->get($client, 'action=validate&scope=login&token=')->body);
    }

    /**
     * `rotate()` is the explicit call for a privilege transition, where a token issued to the
     * previous identity *should* stop working.
     */
    public function testRotateInvalidatesThePreviousToken(): void
    {
        $client = new BrowserClient();

        $old = $this->get($client, 'action=issue&scope=login')->body;
        $new = $this->get($client, 'action=rotate&scope=login')->body;

        self::assertNotSame($old, $new);
        self::assertSame('invalid', $this->get($client, "action=validate&scope=login&token={$old}")->body);
        self::assertSame('valid', $this->get($client, "action=validate&scope=login&token={$new}")->body);
    }

    /**
     * A CSRF token must not outlive the session it belongs to.
     */
    public function testATokenDoesNotSurviveSessionDestruction(): void
    {
        $client = new BrowserClient();

        $token = $this->get($client, 'action=issue&scope=login')->body;
        $this->get($client, 'action=destroy');

        self::assertSame('invalid', $this->get($client, "action=validate&scope=login&token={$token}")->body);
    }
}
