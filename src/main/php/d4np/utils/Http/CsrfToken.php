<?php

declare(strict_types=1);

namespace D4np\Utils\Http;

use D4np\Utils\Support\HttpException;

/**
 * Synchroniser-pattern CSRF tokens (spec FR-12, ADR-0026).
 *
 * Lives in `Http` rather than `Security` per RFC-0001's R-1 placement note: a CSRF token is
 * meaningless without the session and the request that carry it, and putting it beside them keeps
 * the dependency pointing the way the layering rule allows.
 *
 * ```php
 * $csrf = new CsrfToken($session);
 * $token = $csrf->issue('login');                 // render into the form
 * if (!$csrf->validate($request->postString('_token') ?? '', 'login')) { … }
 * ```
 *
 * **Three properties carry the whole defence, and each is a decision rather than a detail:**
 *
 * 1. **The token is CSPRNG output**, 32 bytes from `random_bytes()` rendered as 64 hex characters.
 *    Not `uniqid()`, not `mt_rand()`, not a hash of session data — a token an attacker can predict
 *    is not a token, and the predictable-source mistakes are the ones that keep recurring.
 * 2. **Comparison is `hash_equals()`**, which takes the same time whichever byte differs first. A
 *    `===` leaks the length of the matching prefix through timing, which is enough to reconstruct
 *    a token byte by byte given enough attempts.
 * 3. **A token is issued once per scope and reused**, not regenerated per call. Re-issuing on
 *    every render would invalidate the token already sitting in another open tab, and the usual
 *    response to that — users retrying until it works — trains people to ignore the failure this
 *    protects them with.
 *
 * **Scope names are validated, and that is a storage decision as much as a security one.** A scope
 * becomes a session-storage key, so a scope taken from user input would let a client grow the
 * session record without bound, one key per request. Scope names are application-chosen labels
 * (`'login'`, `'checkout'`), and this refuses anything that does not look like one rather than
 * trusting that every caller remembered.
 */
final class CsrfToken
{
    /**
     * 32 bytes — 256 bits — rendered as 64 hex characters.
     *
     * Well beyond what a CSRF token needs to resist guessing, and cheap: this is generated once
     * per scope per session, not per request.
     */
    private const TOKEN_BYTES = 32;

    private const STORAGE_PREFIX = '_csrf.';

    /**
     * The shape an application-chosen scope label may take. Deliberately narrow — see the class
     * docblock on why a scope is not a place for user input.
     */
    private const SCOPE = '/^[A-Za-z0-9_.-]{1,64}\z/';

    public function __construct(
        private readonly SessionStore $store,
    ) {
    }

    /**
     * The token for `$scope`, generating and storing one on first use.
     *
     * Stable across calls within a session so that concurrently open forms all carry a token that
     * still validates. {@see rotate()} is the explicit way to get a new one.
     *
     * @throws HttpException if the scope name is not a legal label
     */
    public function issue(string $scope = 'default'): string
    {
        $key = self::keyFor($scope);
        $existing = $this->store->get($key);

        if ($existing !== null && $existing !== '') {
            return $existing;
        }

        $token = \bin2hex(\random_bytes(self::TOKEN_BYTES));
        $this->store->set($key, $token);

        return $token;
    }

    /**
     * Whether `$token` matches the one issued for `$scope`.
     *
     * Returns `false` — never throws — for a missing, empty or mismatched token, because every one
     * of those is the same answer to the caller: this request is not authorised. Throwing would
     * push callers into a `try` around a routine branch.
     *
     * The comparison is `hash_equals()`, constant-time in the length of the *stored* token. The
     * absent-token case returns early, which is a timing difference — and a harmless one, because
     * it distinguishes "you have no session" from "your token is wrong", neither of which helps an
     * attacker who has neither.
     *
     * @throws HttpException if the scope name is not a legal label
     */
    public function validate(string $token, string $scope = 'default'): bool
    {
        $stored = $this->store->get(self::keyFor($scope));

        if ($stored === null || $stored === '' || $token === '') {
            return false;
        }

        return \hash_equals($stored, $token);
    }

    /**
     * Replace the token for `$scope` and return the new one.
     *
     * The right thing to call on a privilege transition — a login, a password change — where the
     * session identity itself changes and any token issued to the previous identity should stop
     * working. Deliberately *not* what {@see validate()} does: rotating on every successful
     * validation would break the second of two open tabs.
     *
     * @throws HttpException if the scope name is not a legal label
     */
    public function rotate(string $scope = 'default'): string
    {
        $this->store->remove(self::keyFor($scope));

        return $this->issue($scope);
    }

    /**
     * Forget the token for `$scope`.
     *
     * @throws HttpException if the scope name is not a legal label
     */
    public function clear(string $scope = 'default'): void
    {
        $this->store->remove(self::keyFor($scope));
    }

    /**
     * @throws HttpException
     */
    private static function keyFor(string $scope): string
    {
        if (\preg_match(self::SCOPE, $scope) !== 1) {
            throw new HttpException(\sprintf(
                'CSRF scope %s is not a legal label. A scope becomes a session-storage key, so it '
                . 'must be an application-chosen name such as "login" or "checkout" — matching %s '
                . '— and never a value taken from the request, which would let a client grow the '
                . 'session record one key at a time.',
                \var_export($scope, true),
                self::SCOPE,
            ));
        }

        return self::STORAGE_PREFIX . $scope;
    }
}
