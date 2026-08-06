<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Persistence\Fixture;

use D4np\Utils\Database\DatabaseConnection;
use D4np\Utils\Database\Sort;
use D4np\Utils\Database\SqlStatement;
use D4np\Utils\Persistence\RowNormalizer;
use D4np\Utils\Persistence\TableGateway;

/**
 * The subclass shape `TableGateway`'s docblock promises: CRUD inherited, one bespoke query added
 * on top of the {@see TableGateway::query()} seam.
 *
 * This is the migration target for the surveyed estate's per-entity data-access classes — the
 * generic half stops being written per table, and what remains in the application is the query
 * that table actually owns.
 *
 * @extends TableGateway<Person>
 */
final class PersonGateway extends TableGateway
{
    public function __construct(DatabaseConnection $connection, ?RowNormalizer $normalizer = null)
    {
        parent::__construct($connection, 'people', Person::class, 'id', $normalizer);
    }

    /**
     * A read the gateway does not model, built on the seam rather than on hand-written SQL.
     *
     * @return list<Person>
     */
    public function oldestFirst(int $limit): array
    {
        return $this->fetchAll(
            SqlStatement::fromQueryBuilder($this->query()->orderBy('age', Sort::Desc)->limit($limit)),
            Person::class,
        );
    }

    /**
     * `Repository::fetchAll()` reached with hand-written SQL — the path T-13 covers that the
     * gateway's own methods do not, since here the caller supplies the statement.
     *
     * @return list<Person>
     */
    public function named(string $name): array
    {
        return $this->fetchAll(
            SqlStatement::literal('SELECT id, name, age, status FROM people WHERE name = ?', [$name]),
            Person::class,
        );
    }

    /**
     * A gateway write inside `Repository::withTransaction()`, so T-13 can assert that binding
     * survives the transaction wrapper (T-02 asserts the same for the connection).
     *
     * @param array<array-key, mixed> $values
     */
    public function insertInTransaction(array $values): int
    {
        /** @var int */
        return $this->withTransaction(fn (): int => $this->insert($values));
    }
}
