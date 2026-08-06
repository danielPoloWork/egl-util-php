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
}
