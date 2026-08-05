<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Http\Fixture;

use D4np\Utils\Http\SessionApi;

/**
 * A {@see SessionApi} that records what was called, in what order, and with what.
 *
 * PHP returns `false` from every session function in CLI — verified — so without this fake, every
 * guard, every error path and the ordering rule in `Session::start()` would be untestable. The
 * ordering is why the recording exists at all: applying the cookie parameters *after* the session
 * has started silently produces a working session whose cookie lacks FR-15's flags, and no
 * assertion on the outcome can tell that apart from the correct sequence. Only the sequence can.
 *
 * `status()` is deliberately **not** recorded. It is a query, it is called more than once, and
 * including it would bury the two calls the ordering assertion is actually about.
 *
 * `start()` flips the status to active, because the guards under test read the status back and a
 * fake that did not would make `regenerate()` unreachable for the wrong reason.
 */
final class RecordingSessionApi implements SessionApi
{
    /**
     * The mutating calls, in the order they were made.
     *
     * @var list<string>
     */
    public array $calls = [];

    /**
     * Whatever `setCookieParams()` was handed, so a test can assert the policy really reaches PHP
     * rather than only being returned by `cookieParams()`.
     *
     * @var array{lifetime: int, path: string, domain: string, secure: bool, httponly: bool, samesite: 'Lax'|'Strict'|'None'}|null
     */
    public ?array $params = null;

    public function __construct(
        private int $status = PHP_SESSION_NONE,
        private readonly bool $setCookieParamsSucceeds = true,
        private readonly bool $startSucceeds = true,
        private readonly bool $regenerateSucceeds = true,
        private readonly bool $destroySucceeds = true,
    ) {
    }

    public function status(): int
    {
        return $this->status;
    }

    public function setCookieParams(array $params): bool
    {
        $this->calls[] = 'setCookieParams';
        $this->params = $params;

        return $this->setCookieParamsSucceeds;
    }

    public function start(): bool
    {
        $this->calls[] = 'start';

        if ($this->startSucceeds) {
            $this->status = PHP_SESSION_ACTIVE;
        }

        return $this->startSucceeds;
    }

    public function regenerateId(): bool
    {
        $this->calls[] = 'regenerateId';

        return $this->regenerateSucceeds;
    }

    public function destroy(): bool
    {
        $this->calls[] = 'destroy';

        if ($this->destroySucceeds) {
            $this->status = PHP_SESSION_NONE;
        }

        return $this->destroySucceeds;
    }
}
