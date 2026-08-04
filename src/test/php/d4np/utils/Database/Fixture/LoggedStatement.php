<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Database\Fixture;

use PDOStatement;

/**
 * A `PDOStatement` that records what it was asked to run into a {@see QueryLog}.
 *
 * Installed via `PDO::ATTR_STATEMENT_CLASS`, so every statement the library prepares — through
 * `DatabaseConnection`, `QueryBuilder` or `Transaction` — passes through here without any of them
 * knowing. Nothing is stubbed: `parent::execute()` still runs against the real driver, so a test
 * can assert on the log *and* on what the database actually did.
 *
 * The constructor is `protected` because PDO constructs this itself; the `QueryLog` arrives via
 * the second element of the `ATTR_STATEMENT_CLASS` array.
 */
final class LoggedStatement extends PDOStatement
{
    protected function __construct(
        private readonly QueryLog $log,
    ) {
    }

    /**
     * @param array<array-key, mixed>|null $params
     */
    public function execute(?array $params = null): bool
    {
        $this->log->entries[] = ['sql' => $this->queryString, 'params' => $params];

        return parent::execute($params);
    }
}
