<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Database\Fixture;

use PDO;

/**
 * A real SQLite connection that reports itself as some other driver.
 *
 * Driver-specific behaviour is otherwise untestable here. Only `pdo_sqlite` is available in CI,
 * and SQLite is a *particularly* poor witness for identifier quoting because it accepts double
 * quotes, backticks and brackets alike — a query built with entirely the wrong style still runs,
 * so executing one proves nothing about whether the right style was chosen.
 *
 * This reports a chosen driver name and otherwise behaves like the real SQLite connection
 * underneath, which lets two things be checked that nothing else here can reach:
 *
 * 1. `QueryBuilder` picking the correct quoting characters per driver.
 * 2. `DatabaseConnection` issuing `SET NAMES utf8mb4` when — and only when — the driver is MySQL.
 *    Item 4.1 shipped that line unexecuted by any test and said so; this covers the dispatch and
 *    the statement text.
 *
 * **What it does not prove:** that a real MySQL server accepts what we send. This is a stand-in
 * for driver *dispatch*, not a substitute for the driver matrix T-02 (item 4.4) is meant to bring.
 */
final class PretendDriverPdo extends PDO
{
    /** @var list<string> every statement passed to exec(), in order */
    public array $executed = [];

    public function __construct(
        private readonly string $driverName,
    ) {
        parent::__construct('sqlite::memory:');
    }

    public function getAttribute(int $attribute): mixed
    {
        if ($attribute === PDO::ATTR_DRIVER_NAME) {
            return $this->driverName;
        }

        return parent::getAttribute($attribute);
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        // A real MySQL driver honours this; SQLite underneath would return false and make the
        // connection look like one that refuses a pinned default, which is a different fixture's
        // job (see StubbornlyEmulatingPdo).
        if ($attribute === PDO::ATTR_EMULATE_PREPARES) {
            return true;
        }

        return parent::setAttribute($attribute, $value);
    }

    public function exec(string $statement): int|false
    {
        $this->executed[] = $statement;

        // SQLite does not understand MySQL's session-charset statement; swallow it so the rest of
        // the connection behaves normally, and record it so a test can assert it was issued.
        if (\str_starts_with($statement, 'SET NAMES')) {
            return 0;
        }

        return parent::exec($statement);
    }
}
