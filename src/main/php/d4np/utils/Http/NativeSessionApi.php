<?php

declare(strict_types=1);

namespace D4np\Utils\Http;

/**
 * {@see SessionApi} over PHP's global session functions.
 *
 * Every method is a single delegation with no branch, no ordering and no error handling — all of
 * that lives in {@see Session}, where it can be tested. That split is the whole point of the seam
 * (ADR-0026 §8): what remains here cannot be exercised in CLI, so what remains here is made small
 * enough that there is nothing in it to get wrong. Its behaviour against a real server is roadmap
 * item 6.3's integration suite.
 */
final class NativeSessionApi implements SessionApi
{
    public function status(): int
    {
        return session_status();
    }

    public function setCookieParams(array $params): bool
    {
        return session_set_cookie_params($params);
    }

    public function start(): bool
    {
        return session_start();
    }

    public function regenerateId(): bool
    {
        return session_regenerate_id(true);
    }

    public function destroy(): bool
    {
        return session_destroy();
    }
}
