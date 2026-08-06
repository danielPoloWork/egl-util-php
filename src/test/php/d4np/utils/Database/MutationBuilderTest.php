<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Database;

use D4np\Utils\Database\DatabaseConnection;
use D4np\Utils\Database\MutationBuilder;
use D4np\Utils\Database\SqlStatement;
use D4np\Utils\Support\DatabaseException;
use D4np\Utils\Tests\Database\Fixture\InjectionPayloads;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * `MutationBuilder` — the write half of spec r3 FR-35 (RFC-0002), ADR-0044.
 *
 * Two properties carry the security claim and are asserted separately, because they fail
 * separately: **no value ever reaches the SQL text** (it is a `?`, and the value is in the
 * bindings), and **no identifier reaches it unchecked** (the allowlist refuses, it does not
 * escape). The corpus proof at the PDO boundary is item 10.5's T-13 job; what is asserted here is
 * the text this class renders and the refusals it makes.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class MutationBuilderTest extends TestCase
{
    private DatabaseConnection $connection;

    protected function setUp(): void
    {
        $this->connection = new DatabaseConnection(new PDO('sqlite::memory:'));
    }

    // ---- INSERT --------------------------------------------------------------------------------

    public function testInsertRendersPlaceholdersAndCarriesTheValues(): void
    {
        $builder = MutationBuilder::insert($this->connection, 'users', ['name' => 'Ada', 'age' => 36]);

        self::assertSame('INSERT INTO "users" ("name", "age") VALUES (?, ?)', $builder->toSql());
        self::assertSame(['Ada', 36], $builder->bindings());
    }

    public function testInsertBindsAValueThatLooksLikeSql(): void
    {
        $builder = MutationBuilder::insert($this->connection, 'users', [
            'name' => "Ada'); DROP TABLE users; --",
        ]);

        self::assertSame('INSERT INTO "users" ("name") VALUES (?)', $builder->toSql());
        self::assertSame(["Ada'); DROP TABLE users; --"], $builder->bindings());
        self::assertStringNotContainsString('DROP', $builder->toSql());
    }

    public function testInsertRefusesAnEmptyRow(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/no values/');

        MutationBuilder::insert($this->connection, 'users', []);
    }

    // ---- UPDATE --------------------------------------------------------------------------------

    public function testUpdateRendersSetThenWhereAndOrdersTheBindingsToMatch(): void
    {
        $builder = MutationBuilder::update(
            $this->connection,
            'users',
            ['name' => 'Grace', 'age' => 45],
            ['id' => 7],
        );

        self::assertSame(
            'UPDATE "users" SET "name" = ?, "age" = ? WHERE "id" = ?',
            $builder->toSql(),
        );
        // Placeholder order is the whole contract of the pairing: SET values first, criteria last.
        self::assertSame(['Grace', 45, 7], $builder->bindings());
    }

    public function testUpdateJoinsSeveralCriteriaWithAnd(): void
    {
        $builder = MutationBuilder::update(
            $this->connection,
            'users',
            ['age' => 1],
            ['status' => 'active', 'region' => 'eu'],
        );

        self::assertSame(
            'UPDATE "users" SET "age" = ? WHERE "status" = ? AND "region" = ?',
            $builder->toSql(),
        );
        self::assertSame([1, 'active', 'eu'], $builder->bindings());
    }

    public function testUpdateRefusesAnEmptySetClause(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/no values to set/');

        MutationBuilder::update($this->connection, 'users', [], ['id' => 1]);
    }

    /**
     * The refusal this class exists for as much as for the allowlist: an `UPDATE` with no `WHERE`
     * rewrites every row, and `[]` is what an absent request filter looks like.
     */
    public function testUpdateRefusesEmptyCriteriaRatherThanRewritingTheTable(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/unqualified UPDATE/');

        MutationBuilder::update($this->connection, 'users', ['age' => 1], []);
    }

    // ---- DELETE --------------------------------------------------------------------------------

    public function testDeleteRendersItsCriteria(): void
    {
        $builder = MutationBuilder::delete($this->connection, 'users', ['id' => 7]);

        self::assertSame('DELETE FROM "users" WHERE "id" = ?', $builder->toSql());
        self::assertSame([7], $builder->bindings());
    }

    public function testDeleteRefusesEmptyCriteriaRatherThanEmptyingTheTable(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/unqualified DELETE/');

        MutationBuilder::delete($this->connection, 'users', []);
    }

    // ---- null criteria -------------------------------------------------------------------------

    /**
     * `= NULL` is never true in SQL. A bound `null` would make the statement match nothing and
     * report zero rows affected — a silent no-op, which is precisely the failure mode the estate
     * could not see. `QueryBuilder::whereNull()` makes the same distinction for reads.
     */
    public function testANullCriterionRendersIsNullAndBindsNothing(): void
    {
        $builder = MutationBuilder::delete($this->connection, 'users', ['deleted_at' => null]);

        self::assertSame('DELETE FROM "users" WHERE "deleted_at" IS NULL', $builder->toSql());
        self::assertSame([], $builder->bindings());
    }

    public function testANullValueIsStillBoundWhenItIsBeingWritten(): void
    {
        // The asymmetry is deliberate: `SET x = NULL` is how a column is cleared, so on the value
        // side null is an ordinary bound value; only in a comparison is it a trap.
        $builder = MutationBuilder::update($this->connection, 'users', ['age' => null], ['id' => 1]);

        self::assertSame('UPDATE "users" SET "age" = ? WHERE "id" = ?', $builder->toSql());
        self::assertSame([null, 1], $builder->bindings());
    }

    // ---- the allowlist, on every identifier surface ---------------------------------------------

    /**
     * The identifier corpus — the shared one.
     *
     * This suite shipped with a **shorter copy** at item 10.4: ten payloads where the read builder
     * faced nineteen, so the newer of the two builders was held to the weaker list while both
     * suites stayed green. Item 10.5 unified them into {@see InjectionPayloads}, which is the
     * argument ADR-0044 makes about the allowlist, applied to the corpus that tests it.
     *
     * @return iterable<string, array{string}>
     */
    public static function hostileIdentifiers(): iterable
    {
        yield from InjectionPayloads::identifiers();
    }

    #[DataProvider('hostileIdentifiers')]
    public function testAHostileTableNameIsRefused(string $identifier): void
    {
        $this->expectException(DatabaseException::class);

        MutationBuilder::insert($this->connection, $identifier, ['a' => 1]);
    }

    #[DataProvider('hostileIdentifiers')]
    public function testAHostileInsertColumnIsRefused(string $identifier): void
    {
        $this->expectException(DatabaseException::class);

        MutationBuilder::insert($this->connection, 'users', [$identifier => 1]);
    }

    #[DataProvider('hostileIdentifiers')]
    public function testAHostileUpdateColumnIsRefused(string $identifier): void
    {
        $this->expectException(DatabaseException::class);

        MutationBuilder::update($this->connection, 'users', [$identifier => 1], ['id' => 1]);
    }

    #[DataProvider('hostileIdentifiers')]
    public function testAHostileCriterionColumnIsRefused(string $identifier): void
    {
        $this->expectException(DatabaseException::class);

        MutationBuilder::delete($this->connection, 'users', [$identifier => 1]);
    }

    /**
     * PHP turns a numeric-string array key into an int, so `['0' => 'x']` arrives here as `0`.
     * Casting it back to a string and running the allowlist is what makes that a refusal with a
     * message rather than a `TypeError` — or, worse, a column named `0` in the SQL text.
     */
    public function testANumericColumnKeyIsRefusedByTheAllowlist(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/identifier "0" is not allowed/');

        MutationBuilder::insert($this->connection, 'users', ['0' => 'x']);
    }

    // ---- the door into SqlStatement -------------------------------------------------------------

    public function testFromMutationCarriesTheBuildersOwnSqlAndBindings(): void
    {
        $builder = MutationBuilder::insert($this->connection, 'users', ['name' => 'Ada']);

        $statement = SqlStatement::fromMutation($builder);

        self::assertSame($builder->toSql(), $statement->sql);
        self::assertSame($builder->bindings(), $statement->parameters);
    }

    // ---- and it actually runs --------------------------------------------------------------------

    /**
     * The rendering assertions above are about text; this one is about a database agreeing with
     * it. A statement that reads correctly and does not execute is not a statement.
     */
    public function testTheRenderedStatementsRunAgainstARealDatabase(): void
    {
        $this->connection->execute(SqlStatement::literal(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, age INTEGER)',
        ));

        $inserted = $this->connection->execute(SqlStatement::fromMutation(
            MutationBuilder::insert($this->connection, 'users', ['id' => 1, 'name' => 'Ada', 'age' => 36]),
        ));
        $updated = $this->connection->execute(SqlStatement::fromMutation(
            MutationBuilder::update($this->connection, 'users', ['age' => 37], ['id' => 1]),
        ));

        self::assertSame(1, $inserted);
        self::assertSame(1, $updated);
        self::assertSame(
            ['id' => 1, 'name' => 'Ada', 'age' => 37],
            $this->connection->selectOne(SqlStatement::literal('SELECT id, name, age FROM users')),
        );

        $deleted = $this->connection->execute(SqlStatement::fromMutation(
            MutationBuilder::delete($this->connection, 'users', ['id' => 1]),
        ));

        self::assertSame(1, $deleted);
        self::assertNull($this->connection->selectOne(SqlStatement::literal('SELECT id FROM users')));
    }
}
