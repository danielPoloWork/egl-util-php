<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Persistence;

use D4np\Utils\Database\DatabaseConnection;
use D4np\Utils\Database\SqlStatement;
use D4np\Utils\Persistence\RowNormalizer;
use D4np\Utils\Persistence\TableGateway;
use D4np\Utils\Support\DatabaseException;
use D4np\Utils\Support\HydrationException;
use D4np\Utils\Support\ReflectionCache;
use D4np\Utils\Tests\Persistence\Fixture\Mismatched;
use D4np\Utils\Tests\Persistence\Fixture\Person;
use D4np\Utils\Tests\Persistence\Fixture\PersonGateway;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * `TableGateway` — spec r3 FR-35 (RFC-0002), ADR-0044, catalogued as *Table Data Gateway*.
 *
 * Against real SQLite, for the reason every suite in these two groups gives: the claims are about
 * what a database did with the statement, and a doubled connection would return whatever it was
 * told to.
 *
 * Three groups of assertions carry the item's promises, and each is a property behaviour alone
 * would not notice: **empty criteria are refused** on every filtered operation (a silent `[]` is
 * how a request filter disappears), **column names from the caller are allowlisted** wherever they
 * arrive — criteria keys and value keys alike — and **reads project the DTO**, which is what keeps
 * strict hydration satisfiable on a table with columns the DTO does not want.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class TableGatewayTest extends TestCase
{
    private DatabaseConnection $connection;

    protected function setUp(): void
    {
        $this->connection = new DatabaseConnection(new PDO('sqlite::memory:'));
        $this->connection->execute(SqlStatement::literal(
            'CREATE TABLE people ('
            . 'id INTEGER PRIMARY KEY, name TEXT, age INTEGER, status TEXT, secret TEXT'
            . ')',
        ));
    }

    /**
     * @return TableGateway<Person>
     */
    private function gateway(?RowNormalizer $normalizer = null, ?ReflectionCache $cache = null): TableGateway
    {
        return new TableGateway($this->connection, 'people', Person::class, 'id', $normalizer, $cache);
    }

    private function seed(int $id, string $name, ?int $age, ?string $status = 'active'): void
    {
        $this->connection->execute(SqlStatement::literal(
            'INSERT INTO people (id, name, age, status, secret) VALUES (?, ?, ?, ?, ?)',
            [$id, $name, $age, $status, 'not-for-the-dto'],
        ));
    }

    // ---- reads -----------------------------------------------------------------------------------

    public function testFindReturnsTheHydratedRow(): void
    {
        $this->seed(7, 'Ada', 36);

        $person = $this->gateway()->find(7);

        self::assertInstanceOf(Person::class, $person);
        self::assertSame('Ada', $person->name);
        self::assertSame(36, $person->age);
    }

    public function testFindReturnsNullWhenThereIsNoSuchRow(): void
    {
        self::assertNull($this->gateway()->find(404));
    }

    public function testAllReturnsEveryRow(): void
    {
        $this->seed(1, 'Ada', 36);
        $this->seed(2, 'Grace', 45);

        $people = $this->gateway()->all();

        self::assertCount(2, $people);
        self::assertContainsOnlyInstancesOf(Person::class, $people);
    }

    public function testAllReturnsAnEmptyListForAnEmptyTable(): void
    {
        self::assertSame([], $this->gateway()->all());
    }

    public function testFindByMatchesEveryCriterionWithAnd(): void
    {
        $this->seed(1, 'Ada', 36, 'active');
        $this->seed(2, 'Grace', 36, 'archived');
        $this->seed(3, 'Alan', 41, 'active');

        $people = $this->gateway()->findBy(['age' => 36, 'status' => 'active']);

        self::assertCount(1, $people);
        self::assertSame('Ada', $people[0]->name);
    }

    public function testFindOneByReturnsTheFirstMatchOrNull(): void
    {
        $this->seed(1, 'Ada', 36);
        $this->seed(2, 'Grace', 45);

        self::assertSame('Ada', $this->gateway()->findOneBy(['status' => 'active'])?->name);
        self::assertNull($this->gateway()->findOneBy(['status' => 'retired']));
    }

    /**
     * `= NULL` matches nothing in SQL, so a criterion of `null` has to become `IS NULL` or the
     * read silently returns no rows — the same distinction `MutationBuilder` makes for writes.
     */
    public function testANullCriterionMatchesRowsWhereTheColumnIsNull(): void
    {
        $this->seed(1, 'Ada', 36, 'active');
        $this->seed(2, 'Nobody', null, null);

        $people = $this->gateway()->findBy(['status' => null]);

        self::assertCount(1, $people);
        self::assertSame('Nobody', $people[0]->name);
        self::assertNull($people[0]->age);
    }

    // ---- the projection ---------------------------------------------------------------------------

    /**
     * The table has a `secret` column and `Person` does not declare it. Under strict hydration
     * (ADR-0008) a `SELECT *` would raise on every read; the gateway projects the DTO instead, so
     * the extra column is simply not selected.
     */
    public function testReadsProjectTheDtoRatherThanTheTable(): void
    {
        $this->seed(1, 'Ada', 36);

        // Would be a HydrationException if the gateway selected `*`.
        $person = $this->gateway()->find(1);

        self::assertInstanceOf(Person::class, $person);
    }

    public function testTheProjectionIsTheDtosOwnColumnList(): void
    {
        $this->seed(1, 'Ada', 36);

        // Proved from the outside: a `secret` value exists in the row, and nothing the gateway
        // returns can carry it, because the column never entered the statement.
        $person = $this->gateway()->find(1);
        self::assertInstanceOf(Person::class, $person);
        self::assertSame(['id', 'name', 'age', 'status'], \array_keys(\get_object_vars($person)));
    }

    public function testTheProjectionUsesTheSharedReflectionCacheWhenGivenOne(): void
    {
        $cache = new ReflectionCache();
        $this->seed(1, 'Ada', 36);

        $this->gateway(cache: $cache)->find(1);

        // ADR-0006's point: one cache, consulted rather than duplicated per collaborator.
        self::assertTrue($cache->isCached(Person::class));
    }

    /**
     * A DTO declaring a column the table lacks raises — but **not where it looks like it should**,
     * and the difference was found by this test failing.
     *
     * The expectation written first was a `DatabaseException` from the driver. On SQLite there is
     * none: its double-quoted-string misfeature accepts `"nickname"` as a *string literal* when no
     * such column resolves, so the statement succeeds and every row comes back carrying
     * `'"nickname"' => 'nickname'` — quotes included in the key. Probed directly rather than
     * reasoned about.
     *
     * What refuses it is the layer below: strict hydration sees a key the DTO does not declare and
     * a declared property with no key, and raises. So the mismatch is still loud on SQLite, and
     * loud at the driver on MySQL, PostgreSQL and SQL Server, where those quotes are identifier
     * quotes and an unknown column is an error. Two mechanisms, one outcome — which is the useful
     * part: the gateway does not depend on either one being the loud one.
     */
    public function testADtoDeclaringAColumnTheTableLacksIsRefusedByStrictHydration(): void
    {
        $this->seed(1, 'Ada', 36);
        $gateway = new TableGateway($this->connection, 'people', Mismatched::class, 'id');

        $this->expectException(HydrationException::class);

        $gateway->all();
    }

    /**
     * …and the one case where nothing catches it, asserted rather than left as silence.
     *
     * With no rows there is no hydration, and SQLite has already accepted the statement — so a
     * gateway wired to the wrong DTO looks healthy until the table has a row in it. The blind spot
     * is SQLite-only (every other supported driver rejects the unknown identifier at prepare time)
     * and it is recorded in ADR-0044 rather than papered over: closing it would mean a schema
     * round trip per gateway, which is a real cost for a mistake that surfaces on the first row.
     */
    public function testOnSqliteTheSameMismatchIsInvisibleWhileTheTableIsEmpty(): void
    {
        $gateway = new TableGateway($this->connection, 'people', Mismatched::class, 'id');

        self::assertSame([], $gateway->all());
    }

    // ---- writes -----------------------------------------------------------------------------------

    public function testInsertWritesTheRowAndReportsOneAffected(): void
    {
        $affected = $this->gateway()->insert(['id' => 1, 'name' => 'Ada', 'age' => 36, 'status' => 'active']);

        self::assertSame(1, $affected);
        self::assertSame('Ada', $this->gateway()->find(1)?->name);
    }

    public function testUpdateChangesOnlyTheAddressedRow(): void
    {
        $this->seed(1, 'Ada', 36);
        $this->seed(2, 'Grace', 45);

        $affected = $this->gateway()->update(1, ['age' => 37]);

        self::assertSame(1, $affected);
        self::assertSame(37, $this->gateway()->find(1)?->age);
        self::assertSame(45, $this->gateway()->find(2)?->age);
    }

    public function testUpdateByChangesEveryMatchingRow(): void
    {
        $this->seed(1, 'Ada', 36, 'active');
        $this->seed(2, 'Grace', 45, 'active');
        $this->seed(3, 'Alan', 41, 'archived');

        $affected = $this->gateway()->updateBy(['status' => 'active'], ['status' => 'reviewed']);

        self::assertSame(2, $affected);
        self::assertCount(1, $this->gateway()->findBy(['status' => 'archived']));
    }

    public function testDeleteRemovesOnlyTheAddressedRow(): void
    {
        $this->seed(1, 'Ada', 36);
        $this->seed(2, 'Grace', 45);

        $affected = $this->gateway()->delete(1);

        self::assertSame(1, $affected);
        self::assertNull($this->gateway()->find(1));
        self::assertNotNull($this->gateway()->find(2));
    }

    public function testDeleteByRemovesEveryMatchingRow(): void
    {
        $this->seed(1, 'Ada', 36, 'archived');
        $this->seed(2, 'Grace', 45, 'archived');
        $this->seed(3, 'Alan', 41, 'active');

        self::assertSame(2, $this->gateway()->deleteBy(['status' => 'archived']));
        self::assertCount(1, $this->gateway()->all());
    }

    /**
     * Zero rows affected is a fact, not a failure — item 10.3's reasoning, inherited here: only
     * the caller knows whether an idempotent update that changed nothing is a problem.
     */
    public function testAWriteThatMatchesNothingReportsZeroRatherThanRaising(): void
    {
        self::assertSame(0, $this->gateway()->update(404, ['age' => 1]));
        self::assertSame(0, $this->gateway()->delete(404));
    }

    // ---- the refusals ------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{callable(TableGateway<Person>): mixed}>
     */
    public static function emptyCriteriaOperations(): iterable
    {
        yield 'findBy'    => [static fn (TableGateway $g): mixed => $g->findBy([])];
        yield 'findOneBy' => [static fn (TableGateway $g): mixed => $g->findOneBy([])];
        yield 'updateBy'  => [static fn (TableGateway $g): mixed => $g->updateBy([], ['age' => 1])];
        yield 'deleteBy'  => [static fn (TableGateway $g): mixed => $g->deleteBy([])];
    }

    /**
     * The refusal that matters most: `[]` is what `$request['filters'] ?? []` collapses to, and
     * the consequences run from returning the whole table to emptying it.
     *
     * @param callable(TableGateway<Person>): mixed $operation
     */
    #[DataProvider('emptyCriteriaOperations')]
    public function testEveryFilteredOperationRefusesEmptyCriteria(callable $operation): void
    {
        $this->seed(1, 'Ada', 36);

        $this->expectException(DatabaseException::class);

        $operation($this->gateway());
    }

    public function testTheWholeTableRemainsReachableThroughItsOwnName(): void
    {
        // The refusal above is not a capability being removed: `all()` is the same read, named.
        $this->seed(1, 'Ada', 36);

        self::assertCount(1, $this->gateway()->all());
    }

    public function testAHostileTableNameIsRefusedWhenTheGatewayIsConstructed(): void
    {
        // At wiring time, not on whichever query happens to run first (ADR-0022's fail-fast line).
        $this->expectException(DatabaseException::class);

        new TableGateway($this->connection, 'people; DROP TABLE people', Person::class);
    }

    /**
     * @return iterable<string, array{callable(TableGateway<Person>): mixed}>
     */
    public static function callerSuppliedColumnSurfaces(): iterable
    {
        $hostile = 'age = 0 OR 1=1; --';

        yield 'findBy criterion'  => [static fn (TableGateway $g): mixed => $g->findBy([$hostile => 1])];
        yield 'updateBy criterion' => [static fn (TableGateway $g): mixed => $g->updateBy([$hostile => 1], ['age' => 1])];
        yield 'updateBy value'    => [static fn (TableGateway $g): mixed => $g->updateBy(['id' => 1], [$hostile => 1])];
        yield 'deleteBy criterion' => [static fn (TableGateway $g): mixed => $g->deleteBy([$hostile => 1])];
        yield 'insert column'     => [static fn (TableGateway $g): mixed => $g->insert([$hostile => 1])];
    }

    /**
     * Every surface where a column name can arrive from the caller runs the allowlist. An array
     * key is the likeliest way a request reaches a gateway, and it is the one place a value's
     * binding offers no protection at all.
     *
     * @param callable(TableGateway<Person>): mixed $operation
     */
    #[DataProvider('callerSuppliedColumnSurfaces')]
    public function testACallerSuppliedColumnNameIsRefusedOnEverySurface(callable $operation): void
    {
        $this->seed(1, 'Ada', 36);

        $this->expectException(DatabaseException::class);

        $operation($this->gateway());

        self::assertNotNull($this->gateway()->find(1));
    }

    public function testAValueThatLooksLikeSqlIsStoredAsData(): void
    {
        $this->gateway()->insert(['id' => 1, 'name' => "'); DROP TABLE people; --", 'age' => 1]);

        // The table still exists, and the payload came back as the string it always was.
        self::assertSame("'); DROP TABLE people; --", $this->gateway()->find(1)?->name);
    }

    // ---- inherited mechanics ------------------------------------------------------------------------

    public function testASubclassAddsItsOwnQueriesOnTheSeam(): void
    {
        $this->seed(1, 'Ada', 36);
        $this->seed(2, 'Grace', 45);
        $this->seed(3, 'Alan', 41);

        $oldest = (new PersonGateway($this->connection))->oldestFirst(2);

        self::assertSame(['Grace', 'Alan'], \array_map(static fn (Person $p): string => $p->name, $oldest));
    }

    public function testASubclassInheritsTheCrudSurfaceUnchanged(): void
    {
        $gateway = new PersonGateway($this->connection);
        $gateway->insert(['id' => 9, 'name' => 'Katherine', 'age' => 52, 'status' => 'active']);

        self::assertSame('Katherine', $gateway->find(9)?->name);
    }

    public function testAConfiguredNormalizerRunsBeforeHydration(): void
    {
        $this->connection->execute(SqlStatement::literal(
            'INSERT INTO people (id, name, age, status, secret) VALUES (?, ?, ?, ?, ?)',
            [1, '  Ada  ', 36, 'active', 'x'],
        ));

        // Repository's opt-in normalization (ADR-0042/ADR-0043) is inherited, not re-implemented.
        self::assertSame('Ada', $this->gateway(new RowNormalizer())->find(1)?->name);
    }

    public function testAFailingStatementPropagatesRatherThanReturningASentinel(): void
    {
        $gateway = new TableGateway($this->connection, 'no_such_table', Person::class);

        $this->expectException(DatabaseException::class);

        $gateway->all();
    }

    public function testAMissingColumnInTheRowStillRaisesHydration(): void
    {
        // `age` is nullable, `name` is not: a NULL name is a row Person cannot represent, and the
        // Dto group's strict hydration is what says so rather than the gateway inventing a check.
        $this->connection->execute(SqlStatement::literal(
            'INSERT INTO people (id, name, age, status, secret) VALUES (?, ?, ?, ?, ?)',
            [1, null, 36, 'active', 'x'],
        ));

        $this->expectException(HydrationException::class);

        $this->gateway()->all();
    }
}
