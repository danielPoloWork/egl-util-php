<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Database\Fixture;

use PDO;

/**
 * Counts how many times anything asks the connection for its driver name.
 *
 * `QueryBuilder` quotes identifiers with characters that depend on the driver, and before roadmap
 * item 4.6 it re-asked the connection on *every* quote — one `PDO::getAttribute()` round trip per
 * identifier. That is now resolved once per builder (ADR-0020).
 *
 * The saving this makes is small enough that an end-to-end timing assertion would be flaky, so
 * the property is pinned **by counting instead of by timing**: the count is exact, deterministic,
 * and independent of how loaded the machine is. A regression that reintroduced the per-identifier
 * lookup would change 1 back to N and fail here, where a benchmark might merely look noisy.
 */
final class DriverLookupCountingPdo extends PDO
{
    public int $driverLookups = 0;

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function getAttribute(int $attribute): mixed
    {
        if ($attribute === PDO::ATTR_DRIVER_NAME) {
            $this->driverLookups++;
        }

        return parent::getAttribute($attribute);
    }
}
