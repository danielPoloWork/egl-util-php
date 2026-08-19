<?php

declare(strict_types=1);

namespace D4np\Utils\Persistence;

use D4np\Utils\Database\DatabaseConnection;
use D4np\Utils\Database\QueryBuilder;
use D4np\Utils\Database\SqlStatement;
use D4np\Utils\Database\Transaction;
use D4np\Utils\Dto\DataTransferObject;
use D4np\Utils\Support\DatabaseException;

/**
 * The data-access base: fetch, execute, and transaction mechanics, with rows normalized and
 * hydrated on the way out (spec r3 FR-34, RFC-0002; ADR-0043).
 *
 * Extend it once per aggregate and give the subclass the queries it owns:
 *
 * ```php
 * final class UserRepository extends Repository
 * {
 *     public function activeOlderThan(int $age): array
 *     {
 *         return $this->fetchAll(
 *             SqlStatement::literal(
 *                 'SELECT id, name, age FROM users WHERE status = ? AND age > ?',
 *                 ['active', $age],
 *             ),
 *             UserDto::class,
 *         );
 *     }
 * }
 * ```
 *
 * **There is no `try`/`catch` anywhere in this class, and that is the requirement rather than
 * an omission.** FR-34 asks that *"every failure throws — there is no silent `[]`/`false`/`-1`
 * path"*, and the surveyed estate's data-access classes contained **74** `catch (Throwable)`
 * blocks that swallowed the failure and returned exactly those sentinels, with the reason
 * accumulated into a local variable nothing read. Satisfying FR-34 therefore meant **not
 * writing** the catches, not adding handling: `DatabaseConnection` already raises
 * {@see DatabaseException} (ADR-0014), hydration raises `HydrationException` naming the path
 * that failed (ADR-0008), and {@see RowNormalizer} raises `DatabaseException` naming the
 * column (ADR-0042). Every one of those propagates untouched, and
 * {@see \D4np\Utils\Tests\Persistence\RepositoryTest} asserts it for each path — because
 * "no catch" is a property a test can lose without anyone noticing.
 *
 * **Hydration is strict**, as {@see DataTransferObject::fromArray()} is by default: a column
 * the DTO does not declare raises rather than being dropped. That makes a `SELECT *` into a
 * typed DTO fail, deliberately — it is the shape that breaks the day someone adds a column,
 * and `QueryBuilder::select()` and an explicit projection exist for the alternative. A
 * consumer who genuinely wants lenient hydration overrides {@see self::hydrate()}, which is
 * `protected` for exactly that reason.
 *
 * **Normalization is opt-in.** ADR-0042 left this open and ADR-0043 settles it: no
 * {@see RowNormalizer} means rows are hydrated exactly as the driver returned them. Passing
 * one is visible at the wiring site, which is where a decision to rewrite values belongs —
 * the same reason the normalizer's own data-changing switches are opt-in.
 */
abstract class Repository
{
    private readonly Transaction $transaction;

    public function __construct(
        protected readonly DatabaseConnection $connection,
        protected readonly ?RowNormalizer $normalizer = null,
    ) {
        $this->transaction = new Transaction($connection);
    }

    /**
     * Every row of `$statement`, hydrated into `$dtoClass`.
     *
     * @template T of DataTransferObject
     *
     * @param class-string<T> $dtoClass
     *
     * @return list<T>
     *
     * @throws DatabaseException                            if the statement fails, or a value
     *                                                      fails normalization
     * @throws \D4np\Utils\Support\HydrationException        if a row does not fit `$dtoClass`
     */
    protected function fetchAll(SqlStatement $statement, string $dtoClass): array
    {
        $hydrated = [];

        foreach ($this->connection->select($statement) as $row) {
            $hydrated[] = $this->hydrate($dtoClass, $this->normalizeRow($row));
        }

        return $hydrated;
    }

    /**
     * One window of `$query`, hydrated, with the matching total when the request asks for it
     * (spec r19 FR-47; ADR-0064).
     *
     * Takes a {@see QueryBuilder} rather than a {@see SqlStatement} because pagination has to
     * *modify* the query — apply the window, and strip it again for the count — and a
     * `SqlStatement` is deliberately opaque text by then. The builder is the last point where
     * that is still possible without any caller-supplied text reaching SQL.
     *
     * **An unordered query is refused.** SQL guarantees no row order without `ORDER BY`, so two
     * windows over the same unordered query may repeat one row and skip another while every
     * individual page looks perfectly valid — a data-correctness bug with no symptom at the call
     * site. Requiring the ordering turns it into a message at the seam instead.
     *
     * **A requested total costs a second statement**, issued before the window. Two statements
     * mean the count and the rows are read a moment apart, so a table under concurrent writes can
     * return a total that does not match the window to the row; that is inherent to counting
     * separately and is why {@see PageRequest::withoutTotal()} exists for callers who do not need
     * the number.
     *
     * @template T of DataTransferObject
     *
     * @param class-string<T> $dtoClass
     *
     * @return Page<T>
     *
     * @throws DatabaseException                      if `$query` carries no `ORDER BY`, or the
     *                                                statement fails, or a value fails normalization
     * @throws \D4np\Utils\Support\HydrationException if a row does not fit `$dtoClass`
     */
    protected function fetchPage(QueryBuilder $query, PageRequest $request, string $dtoClass): Page
    {
        if (!$query->isOrdered()) {
            throw new DatabaseException(
                'A paginated read needs an ORDER BY: without one SQL promises no row order, so '
                . 'consecutive pages of the same query may repeat a row and skip another while '
                . 'each page looks correct on its own. Add an ordering the query can be split on '
                . '— a unique column is what makes the split total.',
            );
        }

        $total = $request->withTotal ? $this->countRowsOf($query) : null;

        $items = $this->fetchAll(
            SqlStatement::fromQueryBuilder(
                $query->limit($request->size)->offset($request->offset()),
            ),
            $dtoClass,
        );

        return new Page($items, $request->page, $request->size, $total);
    }

    /**
     * How many rows `$query` matches, ignoring any window on it.
     *
     * The value arrives as whatever the driver reports a `COUNT(*)` as — an `int` on some, a
     * numeric string on others — so it is checked rather than cast blind. A missing or
     * non-numeric answer means the driver did not honour the contract `COUNT(*)` has, and that
     * **throws rather than defaulting to `0`**: a zero here would render as "no results" over a
     * table that is not empty, which is the silent-sentinel failure FR-34 exists to prevent.
     *
     * @throws DatabaseException
     */
    private function countRowsOf(QueryBuilder $query): int
    {
        $row = $this->connection->selectOne(SqlStatement::fromQueryBuilder($query->asRowCount()));
        $value = $row === null ? null : \reset($row);

        if (!\is_numeric($value)) {
            throw new DatabaseException(\sprintf(
                'The COUNT(*) behind this page returned %s rather than a number. The total is '
                . 'not reported as 0, because a table that is not empty would then render as '
                . 'having no results.',
                \get_debug_type($value),
            ));
        }

        return (int) $value;
    }

    /**
     * The first row of `$statement`, or `null` when it returns none.
     *
     * `null` rather than `false`, and never an empty DTO: "no row" is a distinct outcome and
     * belongs in the type, which is the same choice {@see DatabaseConnection::selectOne()}
     * makes one layer down.
     *
     * @template T of DataTransferObject
     *
     * @param class-string<T> $dtoClass
     *
     * @return T|null
     *
     * @throws DatabaseException
     * @throws \D4np\Utils\Support\HydrationException
     */
    protected function fetchOne(SqlStatement $statement, string $dtoClass): ?DataTransferObject
    {
        $row = $this->connection->selectOne($statement);

        return $row === null ? null : $this->hydrate($dtoClass, $this->normalizeRow($row));
    }

    /**
     * Run a statement that changes rows, and return how many it affected.
     *
     * The count is returned rather than a boolean: `0` rows affected is a fact the caller may
     * legitimately expect (an idempotent update) or treat as a failure, and only the caller
     * knows which. Collapsing it to `true`/`false` is what the estate did, and it is why its
     * callers could not tell "updated nothing" from "did not run".
     *
     * @throws DatabaseException
     */
    protected function execute(SqlStatement $statement): int
    {
        return $this->connection->execute($statement);
    }

    /**
     * Run `$work` inside a transaction, committing on return and rolling back on any
     * `Throwable` (ADR-0016's semantics, including savepoints when one is already open).
     *
     * The closure receives this repository, so the work reads as repository calls rather than
     * raw connection access:
     *
     * ```php
     * $this->withTransaction(function (self $repo): int {
     *     $repo->execute($debit);
     *
     *     return $repo->execute($credit);
     * });
     * ```
     *
     * @template TReturn
     *
     * @param callable(self): TReturn $work
     *
     * @return TReturn whatever `$work` returned
     *
     * @throws \Throwable whatever `$work` threw, after the work has been rolled back
     */
    protected function withTransaction(callable $work): mixed
    {
        return $this->transaction->run(fn (): mixed => $work($this));
    }

    /**
     * Turn one row into a DTO.
     *
     * `protected` so a subclass can choose lenient hydration
     * (`$dtoClass::lenient()->fromArray($row)`) or a bespoke mapping, without this class
     * needing a flag for it. Strict is the default here because it is the default in the `Dto`
     * group (ADR-0008) and mass-assignment safety is the reason.
     *
     * @template T of DataTransferObject
     *
     * @param class-string<T>      $dtoClass
     * @param array<string, mixed> $row
     *
     * @return T
     *
     * @throws \D4np\Utils\Support\HydrationException
     */
    protected function hydrate(string $dtoClass, array $row): DataTransferObject
    {
        return $dtoClass::fromArray($row);
    }

    /**
     * Apply the configured {@see RowNormalizer}, or return the row untouched when there is
     * none.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     *
     * @throws DatabaseException
     */
    private function normalizeRow(array $row): array
    {
        return $this->normalizer?->normalize($row) ?? $row;
    }
}
