<?php

declare(strict_types=1);

namespace D4np\Utils\Http;

/**
 * The slice of session storage {@see CsrfToken} needs.
 *
 * **This interface exists for one reason: PHP makes a live session impossible to test in
 * process.** Verified — in CLI, `session_start()`, `session_set_cookie_params()` and
 * `session_regenerate_id()` all return `false`. A `CsrfToken` reading `$_SESSION` directly would
 * therefore be untestable in the unit suite, and CSRF validation is the last thing that should
 * rest on an integration suite alone.
 *
 * It is deliberately three methods wide. ADR-0006 refused an interface for the reflection cache
 * because no consumer needed one yet; here a consumer does, and the justification is the same
 * shape as ADR-0022's `selectAlgorithm()` seam — introduce the seam when there is a real need, and
 * keep it no wider than that need.
 *
 * {@see Session} implements it over `$_SESSION`. Item 6.3's integration suite is what exercises
 * that implementation against a real `php -S` process.
 */
interface SessionStore
{
    public function get(string $key): ?string;

    public function set(string $key, string $value): void;

    public function remove(string $key): void;
}
