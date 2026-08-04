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
     * Five conditions, per NFR-03's own wording — a `where()`, an `orderBy()` and a `limit()`
     * each add a clause too, but the NFR names the `WHERE` count specifically, so that is what
     * this counts: exactly five calls that each contribute one condition.
     */
    public function benchBuildFiveConditionSelect(): void
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
