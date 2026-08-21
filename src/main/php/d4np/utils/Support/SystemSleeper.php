<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

/**
 * The production half of the sleep seam (spec r21 FR-49, RFC-0003; ADR-0066).
 *
 * The one place in this feature where milliseconds become `usleep()`'s microseconds. Construction
 * cannot fail: there is nothing to configure.
 */
final class SystemSleeper implements Sleeper
{
    public function sleep(int $milliseconds): void
    {
        if ($milliseconds <= 0) {
            return;
        }

        \usleep($milliseconds * 1000);
    }
}
