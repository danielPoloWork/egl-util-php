<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Persistence;

use D4np\Utils\Database\DatabaseConnection;
use D4np\Utils\Database\QueryBuilder;
use D4np\Utils\Database\Sort;
use D4np\Utils\Database\SqlStatement;
use D4np\Utils\Persistence\Page;
use D4np\Utils\Persistence\PageRequest;
use D4np\Utils\Persistence\TableGateway;
use D4np\Utils\Support\DatabaseException;
use D4np\Utils\Tests\Engine\RunsAgainstADatabaseEngine;
use D4np\Utils\Tests\Persistence\Fixture\Person;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `PageRequest`, `Page` and the paginated reads — spec r19 FR-47 (RFC-0003), ADR-0064.
 *
 * Against a real engine, for the reason every suite in this group gives: the claims are about what a
 * database did with the statement, and a doubled connection would return whatever it was told to.
 *
 * The assertions that carry the item's promises are the ones behaviour alone would not notice:
 * **windows do not overlap or skip** across consecutive pages, an **unordered query is refused**
 * before it can produce pages that each look correct while the set is wrong, and a **total that
 * was never requested throws** rather than reporting a zero a template renders as "no results".
 */
#[Group('database-engine')]
final class PaginationTest extends TestCase
{
    use RunsAgainstADatabaseEngine;

    private DatabaseConnection $connection;

    protected function setUp(): void
    {
        $pdo = $this->enginePdo();
        $this->connection = new DatabaseConnection($pdo);
        $this->createFixtureTable($pdo, 'people', [
            'id' => 'key', 'name' => 'text', 'age' => 'int', 'status' => 'text', 'secret' => 'text',
        ]);
    }

    /** @return TableGateway<Person> */
    private function gateway(): TableGateway
    {
        return new TableGateway($this->connection, 'people', Person::class, 'id');
    }

    private function seed(int $id, string $status = 'active'): void
    {
        $this->connection->execute(SqlStatement::literal(
            'INSERT INTO people (id, name, age, status, secret) VALUES (?, ?, ?, ?, ?)',
            [$id, 'person-' . $id, 30, $status, 'not-for-the-dto'],
        ));
    }

    private function seedRange(int $count): void
    {
        for ($id = 1; $id <= $count; $id++) {
            $this->seed($id);
        }
    }

    // ---- PageRequest: refuses rather than clamps -------------------------------------------------

    public function testAPageBelowOneIsRefused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/1-based/');

        PageRequest::of(0, 10);
    }

    public function testANegativePageIsRefused(): void
    {
        $this->expectException(DatabaseException::class);

        PageRequest::of(-1, 10);
    }

    public function testASizeBelowOneIsRefused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/size must be at least 1/');

        PageRequest::of(1, 0);
    }

    public function testAnOffsetThatWouldOverflowIsRefused(): void
    {
        // PHP yields a float rather than trapping on integer overflow, so an offset past
        // PHP_INT_MAX would otherwise reach the driver as something that is no longer an offset.
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/overflow/');

        PageRequest::of(\PHP_INT_MAX, 2);
    }

    public function testTheLargestNonOverflowingWindowIsAccepted(): void
    {
        // The boundary is inclusive, which is what proves the guard above is an overflow check
        // rather than an off-by-one that happens to reject large numbers.
        $request = PageRequest::of(\PHP_INT_MAX, 1);

        self::assertSame(\PHP_INT_MAX - 1, $request->offset());
    }

    public function testTheOffsetIsDerivedFromTheOneBasedPage(): void
    {
        self::assertSame(0, PageRequest::of(1, 25)->offset());
        self::assertSame(25, PageRequest::of(2, 25)->offset());
        self::assertSame(50, PageRequest::of(3, 25)->offset());
    }

    public function testTheTotalIsRequestedByDefault(): void
    {
        self::assertTrue(PageRequest::of(1, 10)->withTotal);
        self::assertFalse(PageRequest::of(1, 10)->withoutTotal()->withTotal);
    }

    // ---- windows do not overlap or skip ----------------------------------------------------------

    public function testConsecutivePagesPartitionTheTableExactlyOnce(): void
    {
        $this->seedRange(10);
        $gateway = $this->gateway();

        $seen = [];
        for ($page = 1; $page <= 4; $page++) {
            foreach ($gateway->paginate(PageRequest::of($page, 3))->items as $person) {
                $seen[] = $person->id;
            }
        }

        // Every row exactly once, in order: the property that makes pagination mean anything, and
        // the one an unordered query silently breaks.
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], $seen);
    }

    public function testTheLastPageIsShorterThanTheWindow(): void
    {
        $this->seedRange(10);

        $page = $this->gateway()->paginate(PageRequest::of(4, 3));

        self::assertCount(1, $page->items);
        self::assertSame(10, $page->items[0]->id);
    }

    public function testAPageBeyondTheEndIsEmptyRatherThanAnError(): void
    {
        $this->seedRange(3);

        $page = $this->gateway()->paginate(PageRequest::of(99, 10));

        self::assertTrue($page->isEmpty());
        self::assertSame(3, $page->total());
    }

    public function testRowsAreHydratedAndTheDtoIsProjected(): void
    {
        $this->seedRange(2);

        $page = $this->gateway()->paginate(PageRequest::of(1, 10));

        // A SELECT * would carry the table's `secret` column and fail strict hydration.
        self::assertInstanceOf(Person::class, $page->items[0]);
        self::assertSame('person-1', $page->items[0]->name);
    }

    // ---- the total ------------------------------------------------------------------------------

    public function testTheTotalCountsEveryMatchingRowNotJustTheWindow(): void
    {
        $this->seedRange(10);

        $page = $this->gateway()->paginate(PageRequest::of(1, 3));

        self::assertCount(3, $page->items);
        self::assertSame(10, $page->total());
        self::assertTrue($page->hasTotal());
    }

    public function testTheTotalRespectsTheFilterOnAFilteredPage(): void
    {
        $this->seedRange(6);
        $this->seed(7, 'archived');
        $this->seed(8, 'archived');

        $page = $this->gateway()->paginateBy(['status' => 'active'], PageRequest::of(1, 2));

        // Counting the filtered set, not the table: a COUNT that dropped the WHERE would say 8.
        self::assertSame(6, $page->total());
        self::assertCount(2, $page->items);
    }

    public function testAskingForATotalThatWasNeverCountedThrows(): void
    {
        $this->seedRange(3);

        $page = $this->gateway()->paginate(PageRequest::of(1, 2)->withoutTotal());

        self::assertFalse($page->hasTotal());
        self::assertCount(2, $page->items);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/withoutTotal/');
        $page->total();
    }

    public function testTheTolerantTotalAccessorsSubstituteInstead(): void
    {
        $this->seedRange(3);

        $page = $this->gateway()->paginate(PageRequest::of(1, 2)->withoutTotal());

        self::assertSame(-1, $page->totalOr(-1));
        self::assertNull($page->pageCount());
        self::assertNull($page->hasNextPage());
    }

    public function testAnEmptyTableReportsATotalOfZero(): void
    {
        $page = $this->gateway()->paginate(PageRequest::of(1, 10));

        self::assertSame(0, $page->total());
        self::assertTrue($page->isEmpty());
    }

    // ---- derived answers -------------------------------------------------------------------------

    public function testPageCountRoundsUpForAPartialLastPage(): void
    {
        $this->seedRange(10);

        self::assertSame(4, $this->gateway()->paginate(PageRequest::of(1, 3))->pageCount());
    }

    public function testPageCountIsOneForAnEmptyResultSetNotZero(): void
    {
        // "Page 1 of 0" is a string no interface wants to render, and page 1 of an empty table is
        // the page a consumer is looking at when they are told there are no results.
        self::assertSame(1, $this->gateway()->paginate(PageRequest::of(1, 10))->pageCount());
    }

    public function testHasNextPageIsFalseOnAnExactlyFullLastPage(): void
    {
        // The case a "this window is full, so there is probably more" guess gets wrong: a total
        // that divides evenly by the size.
        $this->seedRange(6);

        $page = $this->gateway()->paginate(PageRequest::of(2, 3));

        self::assertCount(3, $page->items);
        self::assertFalse($page->hasNextPage());
    }

    public function testHasNextPageIsTrueWhileRowsRemain(): void
    {
        $this->seedRange(7);

        self::assertTrue($this->gateway()->paginate(PageRequest::of(2, 3))->hasNextPage());
    }

    public function testCountReportsTheWindowNotTheTotal(): void
    {
        $this->seedRange(10);

        $page = $this->gateway()->paginate(PageRequest::of(1, 3));

        self::assertSame(3, $page->count());
        self::assertSame(10, $page->total());
    }

    // ---- the ordering requirement ----------------------------------------------------------------

    public function testAnUnorderedQueryIsRefused(): void
    {
        $this->seedRange(5);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/ORDER BY/');

        // Through the Repository seam, where a caller supplies the query: without an ordering the
        // pages would each look correct while repeating and skipping rows between them.
        (new Fixture\PagingRepository($this->connection))->pageOfPeopleUnordered(PageRequest::of(1, 2));
    }

    public function testAnOrderedQuerySuppliedByTheCallerIsAccepted(): void
    {
        $this->seedRange(5);

        $page = (new Fixture\PagingRepository($this->connection))
            ->pageOfPeopleOrdered(PageRequest::of(2, 2));

        self::assertSame([3, 4], \array_map(static fn (Person $p): int => $p->id, $page->items));
        self::assertSame(5, $page->total());
    }

    public function testTheGatewayOrdersByItsKeySoCallersNeedNotThinkAboutIt(): void
    {
        // Inserted out of key order: an unordered read returns them in whatever order the engine
        // chooses -- and the three do not choose alike -- so this assertion is what pins that the
        // gateway imposed an order of its own rather than inheriting one.
        foreach ([5, 1, 4, 2, 3] as $id) {
            $this->seed($id);
        }

        $ids = \array_map(
            static fn (Person $p): int => $p->id,
            $this->gateway()->paginate(PageRequest::of(1, 5))->items,
        );

        self::assertSame([1, 2, 3, 4, 5], $ids);
    }

    // ---- empty criteria, as everywhere else in this gateway --------------------------------------

    public function testPaginateByRefusesEmptyCriteria(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/paginateBy/');

        $this->gateway()->paginateBy([], PageRequest::of(1, 10));
    }

    public function testPaginateByAllowlistsCriteriaColumns(): void
    {
        $this->expectException(DatabaseException::class);

        $this->gateway()->paginateBy(['id = 1 OR 1=1 --' => 'x'], PageRequest::of(1, 10));
    }

    // ---- the builder's own new surface -----------------------------------------------------------

    public function testAsRowCountReplacesTheProjectionAndDropsTheWindow(): void
    {
        $builder = (new QueryBuilder($this->connection, 'people'))
            ->select('id', 'name')
            ->orderBy('id', Sort::Asc)
            ->limit(5)
            ->offset(10);

        $sql = $builder->asRowCount()->toSql();

        self::assertStringContainsString('COUNT(*)', $sql);
        self::assertStringNotContainsString('LIMIT', $sql);
        self::assertStringNotContainsString('OFFSET', $sql);
        self::assertStringNotContainsString('ORDER BY', $sql);
    }

    public function testAsRowCountKeepsTheFilterAndItsBindings(): void
    {
        $builder = (new QueryBuilder($this->connection, 'people'))
            ->where('status', \D4np\Utils\Database\Operator::Equals, 'active');

        $counting = $builder->asRowCount();

        self::assertStringContainsString('WHERE', $counting->toSql());
        self::assertSame(['active'], $counting->bindings());
    }

    public function testAsRowCountLeavesTheOriginalBuilderAlone(): void
    {
        // Every fluent method on this builder clones; the count variant must too, or a gateway
        // that counted once would return counts from then on.
        $builder = (new QueryBuilder($this->connection, 'people'))->select('id')->limit(5);

        $builder->asRowCount();

        self::assertStringNotContainsString('COUNT(*)', $builder->toSql());
        self::assertStringContainsString('LIMIT 5', $builder->toSql());
    }

    public function testIsOrderedReportsWhetherAnOrderingExists(): void
    {
        $builder = new QueryBuilder($this->connection, 'people');

        self::assertFalse($builder->isOrdered());
        self::assertTrue($builder->orderBy('id', Sort::Asc)->isOrdered());
    }

    // ---- the generic is static-analysis only, and says so ----------------------------------------

    public function testPageIsAPlainValueObjectOverItsItems(): void
    {
        $this->seedRange(2);

        $page = $this->gateway()->paginate(PageRequest::of(1, 10));

        self::assertInstanceOf(Page::class, $page);
        self::assertSame(1, $page->page);
        self::assertSame(10, $page->size);
    }
}
