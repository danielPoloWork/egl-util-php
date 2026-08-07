<?php

declare(strict_types=1);

namespace D4np\Utils\Persistence;

use D4np\Utils\Database\DatabaseConnection;
use D4np\Utils\Database\Identifier;
use D4np\Utils\Database\MutationBuilder;
use D4np\Utils\Database\Operator;
use D4np\Utils\Database\QueryBuilder;
use D4np\Utils\Database\SqlStatement;
use D4np\Utils\Dto\DataTransferObject;
use D4np\Utils\Support\DatabaseException;
use D4np\Utils\Support\ReflectionCache;

/**
 * A **Table Data Gateway**: one object that gateways one table, handling all of its rows
 * (spec r3 FR-35, RFC-0002; ADR-0044, catalogued in `docs/patterns/README.md`).
 *
 * It is the answer to the shape the surveyed estate repeated per entity — a `Dao` plus a `Query`
 * class plus a `CrudImpl`, each re-deriving how to build safe SQL, none of them agreeing on what
 * a failure returns. Here the mechanics are the library's and only the *contract* stays in the
 * application:
 *
 * ```php
 * $users = new TableGateway($connection, 'users', UserRow::class, 'id');
 *
 * $user  = $users->find(7);                                  // ?UserRow
 * $rows  = $users->findBy(['status' => 'active']);           // list<UserRow>
 * $users->insert(['name' => 'Ada', 'age' => 36]);            // affected rows
 * $users->update(7, ['age' => 37]);
 * $users->deleteBy(['status' => 'archived']);
 * ```
 *
 * **Every identifier is allowlisted and every value is bound**, because this class composes no
 * SQL text of its own: reads go through {@see QueryBuilder} and writes through
 * {@see MutationBuilder}, which share one {@see \D4np\Utils\Database\Identifier}. A column name
 * arriving from a request is therefore refused rather than escaped — including the keys of the
 * `$criteria` and `$values` arrays, which is where a request array most plausibly reaches a
 * gateway.
 *
 * **Criteria may not be empty.** `findBy([])`, `updateBy([], …)` and `deleteBy([])` all raise.
 * An empty array is what an unvalidated `$request['filters'] ?? []` collapses to, and the
 * consequences run from mass disclosure to an emptied table. The deliberate whole-table read has
 * its own name ({@see self::all()}); the deliberate whole-table write is a
 * {@see SqlStatement::literal()} the next reader cannot miss.
 *
 * **The gateway projects the DTO, not the table.** Reads select exactly the columns the DTO
 * declares — never `SELECT *` — for two reasons that point the same way: hydration is strict
 * (ADR-0008), so a table column the DTO does not declare would make every read fail the day
 * somebody adds one; and a projection is the cheaper query anyway. This class is therefore for
 * flat row shapes; a DTO with a nested object or a `Collection` belongs behind a
 * {@see Repository} subclass with a query it owns.
 *
 * The inverse mismatch — a DTO property no column answers — is refused, though **which layer
 * refuses it depends on the driver**, and that is worth knowing before debugging one. On MySQL,
 * PostgreSQL and SQL Server the unknown identifier is an error at prepare time. On SQLite it is
 * not: its double-quoted-string misfeature accepts the quoted name as a *string literal*, so the
 * statement succeeds and strict hydration is what raises, on the first row read. Which leaves one
 * blind spot, named rather than smoothed over: **on SQLite, against an empty table, the mismatch
 * is invisible** — there is no row to hydrate. Both behaviours are asserted in
 * {@see \D4np\Utils\Tests\Persistence\TableGatewayTest}, and ADR-0044 records why closing the gap
 * (a schema round trip per gateway) costs more than the mistake does.
 *
 * Extending it is expected, and is the migration path for the estate's per-entity classes: a
 * subclass annotated `@extends TableGateway<UserRow>` adds the bespoke queries one table needs,
 * built on {@see self::query()} or on {@see Repository}'s own mechanics —
 * `$this->fetchAll(SqlStatement::fromQueryBuilder($this->query()->orderBy('last_seen')), …)`.
 * The class is deliberately not `final` for that reason;
 * {@see \D4np\Utils\Tests\Persistence\Fixture\PersonGateway} is a worked example.
 *
 * **On the array keys.** The `$criteria` and `$values` parameters are typed `array-key`, not
 * `string`, because PHP converts a numeric-string key to an `int` before this class ever sees it —
 * the same annotation-that-was-a-lie item 6.1 found in the superglobal readers (ADR-0025). The
 * keys are cast back and run through the allowlist, so `['0' => …]` is a refusal with a message
 * rather than a `TypeError` or, worse, a column named `0` in the SQL text.
 *
 * **Non-goals** (deliberate absences, not gaps): no `JOIN`, no aggregates, no upsert, no identity
 * map, no change tracking, no lazy loading. This is a gateway, not an ORM — RFC-0002 Alternative
 * #1 rejected that scope, and the escape hatch for anything beyond it is a `Repository` subclass
 * with hand-written SQL.
 *
 * @template T of DataTransferObject
 */
class TableGateway extends Repository
{
    /**
     * The DTO's declared column names, resolved once per instance.
     *
     * @var list<string>|null
     */
    private ?array $projection = null;

    /**
     * The base `SELECT <projection> FROM <table>` builder, resolved once — see {@see self::query()}.
     */
    private ?QueryBuilder $baseQuery = null;

    private readonly ReflectionCache $cache;

    /**
     * @param string             $table    the table this gateway owns — allowlisted here, and again
     *                                     by the builder behind every statement
     * @param class-string<T>    $dtoClass the row shape; its constructor parameters are the projection
     * @param string             $key      the column {@see self::find()}, {@see self::update()} and
     *                                     {@see self::delete()} address a single row by
     * @param ReflectionCache|null $cache  pass the application's shared cache to honour ADR-0006's
     *                                     one-cache commitment; omitted, this instance keeps its
     *                                     own, which holds exactly one class
     *
     * @throws DatabaseException if the table name fails the allowlist
     */
    public function __construct(
        DatabaseConnection $connection,
        private readonly string $table,
        private readonly string $dtoClass,
        private readonly string $key = 'id',
        ?RowNormalizer $normalizer = null,
        ?ReflectionCache $cache = null,
    ) {
        parent::__construct($connection, $normalizer);

        $this->cache = $cache ?? new ReflectionCache();

        // Run the table name through the allowlist here rather than waiting for the first query.
        // The builders check it again on every statement — this call is not what makes the SQL
        // safe — but a gateway wired to an unusable table should say so where it was wired, not
        // on whichever read happens to run first in production. Same reasoning as `Hash` deciding
        // its fallback policy at construction (ADR-0022): fail fast means at wiring time.
        Identifier::forDriver($connection->driver())->quote($table);
    }

    /**
     * The row whose key column equals `$key`, or `null` when there is none.
     *
     * @return T|null
     *
     * @throws DatabaseException
     * @throws \D4np\Utils\Support\HydrationException
     */
    public function find(mixed $key): ?DataTransferObject
    {
        return $this->findOneBy([$this->key => $key]);
    }

    /**
     * Every row of the table.
     *
     * The explicit form of the read `findBy([])` refuses — named so that a whole-table scan is a
     * decision at the call site rather than the accident of an empty filter array.
     *
     * No `ORDER BY` is added. A gateway that invented one would be making a promise the caller
     * did not ask for and paying for it on every read; order the rows in the query that needs
     * them ordered.
     *
     * @return list<T>
     *
     * @throws DatabaseException
     * @throws \D4np\Utils\Support\HydrationException
     */
    public function all(): array
    {
        return $this->fetchAll(SqlStatement::fromQueryBuilder($this->query()), $this->dtoClass);
    }

    /**
     * Every row matching all of `$criteria` (`AND`), with `null` meaning `IS NULL`.
     *
     * @param array<array-key, mixed> $criteria column name => value
     *
     * @return list<T>
     *
     * @throws DatabaseException if a column fails the allowlist, or `$criteria` is empty
     * @throws \D4np\Utils\Support\HydrationException
     */
    public function findBy(array $criteria): array
    {
        return $this->fetchAll(
            SqlStatement::fromQueryBuilder($this->filtered($criteria, 'findBy')),
            $this->dtoClass,
        );
    }

    /**
     * The first row matching `$criteria`, or `null`.
     *
     * @param array<array-key, mixed> $criteria column name => value
     *
     * @return T|null
     *
     * @throws DatabaseException if a column fails the allowlist, or `$criteria` is empty
     * @throws \D4np\Utils\Support\HydrationException
     */
    public function findOneBy(array $criteria): ?DataTransferObject
    {
        return $this->fetchOne(
            SqlStatement::fromQueryBuilder($this->filtered($criteria, 'findOneBy')->limit(1)),
            $this->dtoClass,
        );
    }

    /**
     * Insert one row; returns the number of rows written.
     *
     * The generated key is deliberately not returned: `PDO::lastInsertId()` means different
     * things per driver — an empty string on some, a sequence name argument on PostgreSQL, and
     * nothing useful after a multi-row insert — so a gateway that returned it would be promising
     * portability it cannot keep. A caller that needs it reaches the real PDO through
     * {@see DatabaseConnection::pdo()}, which is the escape hatch that exists for this.
     *
     * @param array<array-key, mixed> $values column name => value
     *
     * @throws DatabaseException if a column fails the allowlist, or `$values` is empty
     */
    public function insert(array $values): int
    {
        return $this->execute(SqlStatement::fromMutation(
            MutationBuilder::insert($this->connection, $this->table, $values),
        ));
    }

    /**
     * Update the row whose key column equals `$key`; returns the number of rows changed.
     *
     * @param array<array-key, mixed> $values column name => new value
     *
     * @throws DatabaseException
     */
    public function update(mixed $key, array $values): int
    {
        return $this->updateBy([$this->key => $key], $values);
    }

    /**
     * Update every row matching `$criteria`; returns the number of rows changed.
     *
     * @param array<array-key, mixed> $criteria column name => value; `null` matches `IS NULL`
     * @param array<array-key, mixed> $values   column name => new value
     *
     * @throws DatabaseException if an identifier fails the allowlist, or either array is empty
     */
    public function updateBy(array $criteria, array $values): int
    {
        return $this->execute(SqlStatement::fromMutation(
            MutationBuilder::update($this->connection, $this->table, $values, $criteria),
        ));
    }

    /**
     * Delete the row whose key column equals `$key`; returns the number of rows deleted.
     *
     * @throws DatabaseException
     */
    public function delete(mixed $key): int
    {
        return $this->deleteBy([$this->key => $key]);
    }

    /**
     * Delete every row matching `$criteria`; returns the number of rows deleted.
     *
     * @param array<array-key, mixed> $criteria column name => value; `null` matches `IS NULL`
     *
     * @throws DatabaseException if a column fails the allowlist, or `$criteria` is empty
     */
    public function deleteBy(array $criteria): int
    {
        return $this->execute(SqlStatement::fromMutation(
            MutationBuilder::delete($this->connection, $this->table, $criteria),
        ));
    }

    /**
     * A {@see QueryBuilder} bound to this table and projecting this DTO.
     *
     * The seam for a subclass that needs a read this class does not model — an ordering, a range,
     * a `LIKE` — without rebuilding the table name and projection, and without reaching for
     * hand-written SQL. `protected` because it is a building block, not part of the gateway's
     * contract with its callers.
     *
     * **The base builder is resolved once per instance, not once per call** (roadmap 10.6,
     * NFR-09). Every call used to construct a fresh `QueryBuilder`, which re-runs
     * {@see Identifier}'s allowlist on the table name — already checked once in
     * {@see self::__construct()} — and again on every projected column, on every single read.
     * Neither can have changed since construction, so re-checking them is pure repetition, not
     * safety: caching costs nothing, because `QueryBuilder` is immutable and every fluent method
     * already returns a `clone` rather than mutating `$this` — a caller chaining off the cached
     * instance can never see, or corrupt, what the next caller receives. Found by profiling
     * `GatewayBench` against NFR-09's 1.5× budget, the same discipline roadmap item 4.6 used for
     * NFR-03: measured first, fixed what the profile actually named.
     *
     * @throws DatabaseException if the table or a projected column fails the allowlist
     */
    protected function query(): QueryBuilder
    {
        return $this->baseQuery ??= (new QueryBuilder($this->connection, $this->table))
            ->select(...$this->projection());
    }

    /**
     * `query()` plus the criteria, refusing an empty set.
     *
     * @param array<array-key, mixed> $criteria
     *
     * @throws DatabaseException
     */
    private function filtered(array $criteria, string $method): QueryBuilder
    {
        if ($criteria === []) {
            throw new DatabaseException(\sprintf(
                '%s() on "%s" was given no criteria. An empty array is what an unvalidated '
                . 'request filter collapses to, and matching every row is not a smaller version '
                . 'of matching some — so it is refused here. Call all() when the whole table is '
                . 'genuinely what you want.',
                $method,
                $this->table,
            ));
        }

        $builder = $this->query();

        foreach ($criteria as $column => $value) {
            $name = (string) $column;

            // `= NULL` is never true in SQL, so a bound null would silently match nothing —
            // QueryBuilder::whereNull() is the same distinction one layer down, and
            // MutationBuilder makes it on the write side.
            $builder = $value === null
                ? $builder->whereNull($name)
                : $builder->where($name, Operator::Equals, $value);
        }

        return $builder;
    }

    /**
     * The DTO's constructor parameter names — the columns a read projects.
     *
     * Resolved through {@see ReflectionCache} rather than fresh reflection because that is the
     * cache ADR-0006 exists to be, and cached again on the instance because a gateway reads far
     * more often than it is constructed.
     *
     * @return list<string>
     */
    private function projection(): array
    {
        return $this->projection ??= $this->cache->for($this->dtoClass)->parameterNames();
    }
}
