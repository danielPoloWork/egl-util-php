<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Http\Fixture;

use D4np\Utils\Http\SessionStore;

/**
 * An in-memory {@see SessionStore}, so CSRF logic can be tested without a live session.
 *
 * PHP will not start a session in CLI — `session_start()` returns `false`, verified — so without
 * this the whole of `CsrfToken` would be untestable until item 6.3's `php -S` integration suite.
 * CSRF validation is the last thing that should rest on an integration suite alone.
 *
 * `$entries` is public so a test can inspect what was stored, which is how the "a token is stored
 * under a scoped key" and "rotate actually replaces it" assertions are written.
 */
final class ArraySessionStore implements SessionStore
{
    /** @var array<string, string> */
    public array $entries = [];

    public function get(string $key): ?string
    {
        return $this->entries[$key] ?? null;
    }

    public function set(string $key, string $value): void
    {
        $this->entries[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->entries[$key]);
    }
}
