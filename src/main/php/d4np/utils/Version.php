<?php

declare(strict_types=1);

namespace D4np\Utils;

/**
 * The single source of truth for the package's released version.
 *
 * Kept in lockstep with the README `Status-vX.Y.Z` badge by
 * {@see \tools\consistency_lint} (roadmap item 1.5); bump both in the same
 * commit that completes a milestone (versioning: pre-1.0, one minor per
 * milestone — RFC-0001 / ROADMAP.md).
 */
final class Version
{
    public const VERSION = '1.0.0';

    private function __construct()
    {
        // Static-only: no instances.
    }
}
