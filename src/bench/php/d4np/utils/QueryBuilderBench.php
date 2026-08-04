<?php

declare(strict_types=1);

namespace D4np\Utils\Bench;

use D4np\Utils\Database\DatabaseConnection;
use D4np\Utils\Database\Operator;
use D4np\Utils\Database\QueryBuilder;
use D4np\Utils\Database\Sort;
use PDO;
use PhpBench\Attributes as Bench;

/**
 * NFR-03: a 5-condition `SELECT` builds in ≤ 10 µs, executing zero queries.
 *
 * **"Builds" is exactly `toSql()` + `bindings()`, not `get()`/`first()`.** The NFR's two clauses
 * are one fact stated twice: the cost being budgeted is string assembly and array bookkeeping,
 * and the reason it can be that cheap is that nothing here touches the driver. `get()`/`first()`
 * are what run a query — measuring through them would benchmark I/O, not the builder, and would
 * silently violate the NFR's own "0 queries executed" half in the act of proving the first half.
 * `QueryBuilderTest::testBuildingNeverRunsAQuery()` (item 4.4's `QueryLog` fixture, reused) proves
 * the zero-queries half directly; this benchmark proves the timing half.
 *
 * The absolute ≤ 10 µs ceiling carries the same caveat NFR-01's benchmark documents (roadmap 3.5):
 * it is tied to spec NFR-06's reference machine and methodology, not asserted here as a hard CI
 * gate for the same reason — a slower CI runner would fail for a reason having nothing to do with
 * a regression. The nightly, baseline-tracked check belongs to roadmap item 7.1.
 *
 * `DatabaseConnection` is built once in {@see self::setUpConnection()} and reused across every
 * rev, so the benchmark measures `QueryBuilder`, not `PDO`'s own connection overhead. It never
 * runs a statement, so which driver backs it does not matter; SQLite in memory is the cheapest
 * one available.
 */
#[Bench\BeforeMethods('setUpConnection')]
#[Bench\Iterations(10)]
#[Bench\Revs(1000)]
#[Bench\RetryThreshold(5)]
final class QueryBuilderBench
{
    private DatabaseConnection $connection;

    public function setUpConnection(): void
    {
        $this->connection = new DatabaseConnection(new PDO('sqlite::memory:'));
    }

    /**
     * **The workload NFR-03 actually names**: a `SELECT` with five conditions.
     *
     * This subject was corrected at roadmap item 4.6 (ADR-0020). Item 4.5's version of it also
     * selected five named columns and applied `orderBy`/`limit`/`offset`, then reported the
     * resulting figure as NFR-03's — while its own docblock claimed to be counting "exactly five
     * calls that each contribute one condition". The doc and the code disagreed, and the code was
     * measuring roughly three times the work the NFR describes.
     *
     * A column list is not a condition. `SELECT *` keeps this subject to precisely the five
     * `WHERE` conditions NFR-03 budgets, so the number it produces is the one the budget can
     * legitimately be compared against. The heavier shape is still measured, and still reported —
     * as {@see benchBuildRealisticPagedQuery()}, under its own name, against no budget.
     */
    public function benchBuildFiveConditionSelect(): void
    {
        $query = (new QueryBuilder($this->connection, 'users'))
            ->where('status', Operator::Equals, 'active')
            ->where('age', Operator::GreaterThan, 18)
            ->where('name', Operator::NotEquals, '')
            ->where('email', Operator::Like, '%@example.com')
            ->where('id', Operator::LessThan, 999);

        $query->toSql();
        $query->bindings();
    }

    /**
     * A realistic listing query: named columns, five conditions, ordering and pagination.
     *
     * **NFR-03 does not budget this shape**, and this subject deliberately asserts nothing. It
     * exists so that item 4.5's measurement is not silently dropped now that the subject above has
     * been narrowed to the spec's wording — the heavier number is real, it is what an application
     * actually builds, and it is several times the five-condition figure. Keeping it visible is
     * what stops the correction from reading as a benchmark quietly rewritten until it passed.
     */
    public function benchBuildRealisticPagedQuery(): void
    {
        $query = (new QueryBuilder($this->connection, 'users'))
            ->select('id', 'name', 'email', 'age', 'status')
            ->where('status', Operator::Equals, 'active')
            ->where('age', Operator::GreaterThan, 18)
            ->where('name', Operator::NotEquals, '')
            ->whereNotNull('email')
            ->whereIn('status', ['active', 'pending'])
            ->orderBy('name', Sort::Asc)
            ->limit(10)
            ->offset(0);

        $query->toSql();
        $query->bindings();
    }
}
