<?php

declare(strict_types=1);

namespace D4np\Utils\Http;

use D4np\Utils\Support\HttpException;

/**
 * A session with hardened cookie flags and a real `regenerate()` (spec FR-15, ADR-0026).
 *
 * **The cookie policy is a value, not a side effect.** {@see cookieParams()} returns exactly what
 * {@see start()} will apply, and is a pure function of the constructor arguments. That is not
 * tidiness: PHP makes a live session impossible to exercise in process — verified, `session_start()`,
 * `session_set_cookie_params()` and `session_regenerate_id()` all return `false` in CLI — so
 * without this the three flags FR-15 exists to pin would have no unit assertion at all, only
 * roadmap item 6.3's integration suite. The same move ADR-0022 made for the hashing policy.
 *
 * The three flags, and what each one actually stops:
 *
 * - **`httponly`** — JavaScript cannot read the cookie, so an XSS that gets as far as running
 *   script still cannot exfiltrate the session identifier.
 * - **`secure`** — the cookie is never sent over plain HTTP, so a network attacker cannot harvest
 *   it from an accidental `http://` request.
 * - **`samesite=Lax`** — the browser does not attach it to cross-site POSTs, which is a second,
 *   independent line against CSRF. `Lax` rather than `Strict` because `Strict` also withholds the
 *   cookie from ordinary inbound links, so a logged-in user following one from an email arrives
 *   logged out — the kind of breakage that gets a security control switched off entirely.
 *
 * **`secure` defaults to `true` and the opt-out is explicit and narrow.** A `secure` cookie over
 * plain HTTP is never sent, so local development on `http://localhost` would appear to have no
 * session at all. That is a real need, and the honest response is a named constructor argument
 * whose only correct use is documented — not a silent auto-detection from
 * `$_SERVER['HTTPS']`, which would quietly disable the flag on any deployment sitting behind a
 * misconfigured proxy. Same shape as `Hash`'s `bcryptFallback`: safe by default, opt out on
 * purpose, visible in the wiring.
 *
 * **The session functions themselves come through {@see SessionApi}** (ADR-0026 §8), for the same
 * reason and by the same route. Because PHP returns `false` from all of them in CLI, the guards
 * below, the error paths, and above all the *ordering* in {@see start()} were unreachable by any
 * test. The ordering is the one worth naming: applied after the session has started, the cookie
 * parameters have no effect and the session cookie goes out without FR-15's flags — a session that
 * works perfectly and is unprotected. Both orderings "work", so only an assertion on the call
 * sequence can tell them apart.
 */
final class Session implements SessionStore
{
    /**
     * @param bool $secure whether the cookie is HTTPS-only. **Leave this `true` in anything a
     *                     browser will reach.** Setting it `false` is for local `http://`
     *                     development, and means the session identifier travels in clear text
     * @param SameSite $sameSite `Lax` per FR-15. A closed type rather than a string, so an illegal
     *                            value is a compile-time impossibility instead of a runtime check
     *                            (ADR-0015's reasoning, applied again)
     * @param string $path the cookie path
     * @param SessionApi $api PHP's session functions, behind a seam so this class's guards,
     *                        ordering and error paths can be tested at all (ADR-0026 §8). The
     *                        default is the real thing; nothing but the test suite passes anything
     *                        else
     *
     * @throws HttpException if `None` is paired with an insecure cookie
     */
    public function __construct(
        private readonly bool $secure = true,
        private readonly SameSite $sameSite = SameSite::Lax,
        private readonly string $path = '/',
        private readonly SessionApi $api = new NativeSessionApi(),
    ) {
        // The one constraint the type system cannot express, because it spans two arguments:
        // browsers reject `SameSite=None` without `Secure` and drop the cookie entirely. Failing
        // here means the misconfiguration surfaces at wiring time rather than as "sessions do not
        // work" in production.
        if ($this->sameSite === SameSite::None && !$this->secure) {
            throw new HttpException(
                'SameSite=None requires a Secure cookie; browsers reject the combination and drop '
                . 'the cookie entirely, so this refuses rather than producing a session that '
                . 'silently never persists.',
            );
        }
    }

    /**
     * Exactly the cookie parameters {@see start()} will apply.
     *
     * Pure, so FR-15's flags can be asserted without a live session.
     *
     * @return array{lifetime: int, path: string, domain: string, secure: bool, httponly: bool, samesite: 'Lax'|'Strict'|'None'}
     */
    public function cookieParams(): array
    {
        return [
            // A session cookie: it dies with the browser session rather than persisting on disk
            // with an expiry the server cannot revoke.
            'lifetime' => 0,
            'path' => $this->path,
            'domain' => '',
            'secure' => $this->secure,
            // Never configurable. A session identifier readable from JavaScript defeats the
            // purpose of having one, and no legitimate caller needs it.
            'httponly' => true,
            'samesite' => $this->sameSite->value,
        ];
    }

    /**
     * Start the session, applying {@see cookieParams()} first.
     *
     * The order is not optional: `session_set_cookie_params()` has no effect once the session has
     * started, so setting the flags afterwards would leave the cookie already sent without them.
     *
     * Starting an already-active session is a no-op rather than an error — a request that reaches
     * two entry points should not fail on the second.
     *
     * @throws HttpException if sessions are disabled, or the session cannot be started
     */
    public function start(): void
    {
        if ($this->api->status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if ($this->api->status() === PHP_SESSION_DISABLED) {
            throw new HttpException('Sessions are disabled in this PHP build.');
        }

        if (!$this->api->setCookieParams($this->cookieParams())) {
            throw new HttpException(
                'Could not apply the session cookie parameters. They must be set before the '
                . 'session starts and before any output; this refuses rather than starting a '
                . 'session whose cookie lacks the flags FR-15 requires.',
            );
        }

        if (!$this->api->start()) {
            throw new HttpException('Could not start the session.');
        }
    }

    /**
     * Give the session a new identifier, discarding the old one.
     *
     * **Call this on every privilege transition — login above all.** Without it, an attacker who
     * can fix a victim's session identifier before login (a link carrying one, an XSS writing the
     * cookie) still holds a valid identifier *after* the victim authenticates: session fixation.
     *
     * `session_regenerate_id(true)` — the `true` deletes the old session record rather than
     * leaving it valid, which is the half that actually closes the attack. Leaving it `false`
     * renames the session while the old identifier keeps working.
     *
     * @throws HttpException if there is no active session, or regeneration fails
     */
    public function regenerate(): void
    {
        if ($this->api->status() !== PHP_SESSION_ACTIVE) {
            throw new HttpException(
                'Cannot regenerate a session identifier before the session has started.',
            );
        }

        if (!$this->api->regenerateId()) {
            throw new HttpException('Could not regenerate the session identifier.');
        }
    }

    public function get(string $key): ?string
    {
        $value = $_SESSION[$key] ?? null;

        // Only strings come back. A session written by other code can hold anything, and quietly
        // casting an array or object here would be the coercion `Request` refuses for the same
        // reason (ADR-0025).
        return \is_string($value) ? $value : null;
    }

    public function set(string $key, string $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Empty the session data and destroy the server-side record.
     *
     * The cookie itself is left alone: expiring it is a response concern, and this class does not
     * write headers.
     */
    public function destroy(): void
    {
        $_SESSION = [];

        if ($this->api->status() === PHP_SESSION_ACTIVE) {
            $this->api->destroy();
        }
    }
}
