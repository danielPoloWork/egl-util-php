<?php

declare(strict_types=1);

namespace D4np\Utils\Security;

/**
 * What a {@see RateLimitStore} hands back: opaque state, and the version to quote when replacing it
 * (spec r22 FR-50, RFC-0003; ADR-0061 §2, ADR-0067).
 *
 * Both fields are opaque to their other side, and that is the seam's whole discipline. The **state**
 * is the limiter's — a store that inspected it would be reimplementing the token bucket, which is
 * the "every consumer reimplements the math per backend" outcome ADR-0061 rejected. The **version**
 * is the store's — a counter, a content hash, a row's `xmin`; the limiter only quotes it back to
 * {@see RateLimitStore::writeIfVersion()} and never reasons about it.
 */
final class RateLimitRecord
{
    public function __construct(
        private readonly string $state,
        private readonly string $version,
    ) {
    }

    public function state(): string
    {
        return $this->state;
    }

    public function version(): string
    {
        return $this->version;
    }
}
