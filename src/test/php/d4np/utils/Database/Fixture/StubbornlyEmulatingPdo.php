<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Database\Fixture;

use PDO;

/**
 * A driver that claims to support emulated prepares and refuses to turn them off.
 *
 * No real driver reachable from this test suite behaves this way — MySQL honours the attribute,
 * and SQLite has no emulation mode at all — which is exactly why this exists. The branch in
 * {@see \D4np\Utils\Database\DatabaseConnection} that *refuses a connection* rather than proceed
 * with client-side interpolation is the single most security-relevant line in spec FR-06's
 * implementation, and without this fixture nothing would ever execute it.
 *
 * It is deliberately a subclass of a real `PDO` on a real SQLite connection rather than a mock:
 * everything except the two overridden attribute calls behaves like the genuine article.
 */
final class StubbornlyEmulatingPdo extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        if ($attribute === PDO::ATTR_EMULATE_PREPARES) {
            return false;
        }

        return parent::setAttribute($attribute, $value);
    }

    public function getAttribute(int $attribute): mixed
    {
        // The distinguishing signal: this driver *does* answer for the attribute (so it has the
        // concept) and reports that it is still emulating.
        if ($attribute === PDO::ATTR_EMULATE_PREPARES) {
            return true;
        }

        return parent::getAttribute($attribute);
    }
}
