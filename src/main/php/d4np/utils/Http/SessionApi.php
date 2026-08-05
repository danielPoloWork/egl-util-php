<?php

declare(strict_types=1);

namespace D4np\Utils\Http;

/**
 * PHP's own session functions, behind a seam (ADR-0026 §8).
 *
 * This interface adds no behaviour. It exists because `session_start()`,
 * `session_set_cookie_params()` and `session_regenerate_id()` all return `false` in CLI — verified
 * — which puts every guard, every ordering rule and every error path in {@see Session} beyond the
 * reach of the unit suite.
 *
 * The property that matters most is one no functional test could otherwise reach: **the cookie
 * parameters must be applied before the session starts.** Applied afterwards they have no effect,
 * and the session cookie goes out without the flags FR-15 exists to pin — a session that works
 * perfectly and is unprotected. Both orderings "work"; only one is correct. With this seam a fake
 * records the call order and the rule is asserted directly.
 *
 * The same reasoning that gave `CsrfToken` its {@see SessionStore} (ADR-0026 §2): a seam introduced
 * for exactly one reason, kept no wider than that reason, over logic too security-relevant to leave
 * resting on an integration suite alone.
 *
 * @see NativeSessionApi the production implementation, which is delegation and nothing else
 */
interface SessionApi
{
    /**
     * @return int one of `PHP_SESSION_DISABLED`, `PHP_SESSION_NONE`, `PHP_SESSION_ACTIVE`
     */
    public function status(): int;

    /**
     * @param array{lifetime: int, path: string, domain: string, secure: bool, httponly: bool, samesite: 'Lax'|'Strict'|'None'} $params
     */
    public function setCookieParams(array $params): bool;

    public function start(): bool;

    /**
     * Regenerate the identifier, **deleting the old session record**.
     *
     * The deletion is not a detail: keeping the old record leaves the previous identifier valid,
     * which is the half of session fixation that actually matters. It is fixed here rather than
     * exposed as a parameter so no caller can turn it off.
     */
    public function regenerateId(): bool;

    public function destroy(): bool;
}
