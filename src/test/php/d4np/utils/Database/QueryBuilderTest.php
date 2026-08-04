<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Database;

use D4np\Utils\Database\DatabaseConnection;
use D4np\Utils\Database\Operator;
use D4np\Utils\Database\QueryBuilder;
use D4np\Utils\Database\Sort;
use D4np\Utils\Support\DatabaseException;
use D4np\Utils\Tests\Database\Fixture\PretendDriverPdo;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Spec FR-07: values bound, identifiers allowlisted and driver-quoted, keywords closed, and
 * `LIMIT`/`OFFSET` non-negative.
 *
 * The identifier cases are the point of the class — prepared statements cannot bind a table or
 * column name, so the allowlist is the only defence there is — and they are `#[Group('T-02')]`
 * because spec §7's T-02 suite names *"identifier injection throws DatabaseException"* explicitly.
 */
#[Group('T-02')]
#[RequiresPhpExtension('pdo_sqlite')]
final class QueryBuilderTest extends TestCase
{
    private function connection(): DatabaseConnection
    {
        $connection = new DatabaseConnection(new PDO('sqlite::memory:'));
        $connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, age INTEGER)');

        return $connection;
    }

    private function builder(): QueryBuilder
    {
        return new QueryBuilder($this->connection(), 'users');
    }

    public function testSelectsEverythingByDefault(): void
    {
        self::assertSame('SELECT * FROM "users"', $this->builder()->toSql());
    }

    public function testNamedColumnsAreQuoted(): void
    {
        self::assertSame('SELECT "id", "name" FROM "users"', $this->builder()->select('id', 'name')->toSql());
    }

    public function testWhereBindsTheValueRatherThanInliningIt(): void
    {
        $query = $this->builder()->where('name', Operator::Equals, 'Ada');

        self::assertSame('SELECT * FROM "users" WHERE "name" = ?', $query->toSql());
        self::assertSame(['Ada'], $query->bindings());
        // The value must not appear in the SQL text at all.
        self::assertStringNotContainsString('Ada', $query->toSql());
    }

    public function testConditionsCombineWithAnd(): void
    {
        $query = $this->builder()
            ->where('name', Operator::Equals, 'Ada')
            ->where('age', Operator::GreaterThan, 30);

        self::assertSame('SELECT * FROM "users" WHERE "name" = ? AND "age" > ?', $query->toSql());
        self::assertSame(['Ada', 30], $query->bindings());
    }

    public function testWhereInBindsOnePlaceholderPerValue(): void
    {
        $query = $this->builder()->whereIn('id', [1, 2, 3]);

        self::assertSame('SELECT * FROM "users" WHERE "id" IN (?, ?, ?)', $query->toSql());
        self::assertSame([1, 2, 3], $query->bindings());
    }

    public function testWhereInRefusesAnEmptyList(): void
    {
        $this->expectException(DatabaseException::class);

        $this->builder()->whereIn('id', []);
    }

    public function testNullChecksRenderAsIsNull(): void
    {
        self::assertSame('SELECT * FROM "users" WHERE "name" IS NULL', $this->builder()->whereNull('name')->toSql());
        self::assertSame('SELECT * FROM "users" WHERE "name" IS NOT NULL', $this->builder()->whereNotNull('name')->toSql());
    }

    public function testOrderByUsesTheEnumKeyword(): void
    {
        $query = $this->builder()->orderBy('name')->orderBy('age', Sort::Desc);

        self::assertSame('SELECT * FROM "users" ORDER BY "name" ASC, "age" DESC', $query->toSql());
    }

    public function testLimitAndOffsetRender(): void
    {
        self::assertSame('SELECT * FROM "users" LIMIT 10 OFFSET 20', $this->builder()->limit(10)->offset(20)->toSql());
    }

    public function testZeroIsAcceptableForLimitAndOffset(): void
    {
        self::assertSame('SELECT * FROM "users" LIMIT 0 OFFSET 0', $this->builder()->limit(0)->offset(0)->toSql());
    }

    public function testNegativeLimitIsRefused(): void
    {
        $this->expectException(DatabaseException::class);

        $this->builder()->limit(-1);
    }

    public function testNegativeOffsetIsRefused(): void
    {
        $this->expectException(DatabaseException::class);

        $this->builder()->offset(-5);
    }

    /**
     * The heart of FR-07. Each of these is a real way an identifier has been used to inject SQL;
     * none of them can be bound as a parameter, so each must be refused outright.
     *
     * @return iterable<string, array{string}>
     */
    public static function hostileIdentifiers(): iterable
    {
        yield 'statement terminator' => ['id; DROP TABLE users'];
        yield 'comment' => ['id -- '];
        yield 'block comment' => ['id /* x */'];
        yield 'union' => ['id UNION SELECT password FROM admins'];
        yield 'quote break-out (double)' => ['id" FROM users; --'];
        yield 'quote break-out (backtick)' => ['id` FROM users; --'];
        yield 'quote break-out (bracket)' => ['id] FROM users; --'];
        yield 'subquery' => ['(SELECT 1)'];
        yield 'wildcard' => ['*'];
        yield 'qualified name' => ['users.id'];
        yield 'space' => ['user name'];
        yield 'leading digit' => ['1id'];
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'newline smuggling' => ["id\nDROP TABLE users"];
        // PCRE's `$` matches before a trailing newline, so FR-07's allowlist transcribed
        // literally admits this one. It did, until the pattern was anchored with `\z`.
        yield 'trailing newline (the `$` anchor hole)' => ["id\n"];
        yield 'trailing CRLF' => ["id\r\n"];
        yield 'null byte' => ["id\0"];
        yield 'unicode lookalike' => ['ｉd'];
    }

    #[DataProvider('hostileIdentifiers')]
    public function testHostileColumnNamesAreRefused(string $identifier): void
    {
        $this->expectException(DatabaseException::class);

        $this->builder()->select($identifier);
    }

    #[DataProvider('hostileIdentifiers')]
    public function testHostileTableNamesAreRefused(string $identifier): void
    {
        $this->expectException(DatabaseException::class);

        new QueryBuilder($this->connection(), $identifier);
    }

    #[DataProvider('hostileIdentifiers')]
    public function testHostileWhereColumnsAreRefused(string $identifier): void
    {
        $this->expectException(DatabaseException::class);

        $this->builder()->where($identifier, Operator::Equals, 1);
    }

    #[DataProvider('hostileIdentifiers')]
    public function testHostileOrderByColumnsAreRefused(string $identifier): void
    {
        $this->expectException(DatabaseException::class);

        $this->builder()->orderBy($identifier);
    }

    /**
     * A reserved word is a legal column name; quoting is what makes it usable.
     */
    public function testAReservedWordIsAcceptableBecauseIdentifiersAreQuoted(): void
    {
        self::assertSame('SELECT "order", "select" FROM "users"', $this->builder()->select('order', 'select')->toSql());
    }

    public function testTheBuilderIsImmutable(): void
    {
        $base = $this->builder();
        $filtered = $base->where('name', Operator::Equals, 'Ada');

        self::assertSame('SELECT * FROM "users"', $base->toSql());
        self::assertSame([], $base->bindings());
        self::assertNotSame($base, $filtered);
    }

    /**
     * The quoting has to follow the driver, and SQLite is a poor witness for that on its own —
     * it accepts double quotes, backticks *and* brackets, so a query built with the wrong style
     * would still run. Asserting the rendered text is what actually pins the per-driver choice.
     */
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function driverQuoting(): iterable
    {
        yield 'mysql uses backticks' => ['mysql', 'SELECT `id` FROM `users`'];
        yield 'sqlsrv uses brackets' => ['sqlsrv', 'SELECT [id] FROM [users]'];
        yield 'dblib uses brackets' => ['dblib', 'SELECT [id] FROM [users]'];
        yield 'pgsql uses double quotes' => ['pgsql', 'SELECT "id" FROM "users"'];
        yield 'oci uses double quotes' => ['oci', 'SELECT "id" FROM "users"'];
        yield 'sqlite uses double quotes' => ['sqlite', 'SELECT "id" FROM "users"'];
    }

    #[DataProvider('driverQuoting')]
    public function testQuotingFollowsTheDriver(string $driver, string $expected): void
    {
        $connection = new DatabaseConnection(new PretendDriverPdo($driver));

        self::assertSame($expected, (new QueryBuilder($connection, 'users'))->select('id')->toSql());
    }

    public function testEndToEndAgainstARealDriver(): void
    {
        $connection = $this->connection();
        $connection->execute('INSERT INTO users (name, age) VALUES (?, ?)', ['Ada', 36]);
        $connection->execute('INSERT INTO users (name, age) VALUES (?, ?)', ['Alan', 41]);
        $connection->execute('INSERT INTO users (name, age) VALUES (?, ?)', ['Grace', 45]);

        $rows = (new QueryBuilder($connection, 'users'))
            ->select('name')
            ->where('age', Operator::GreaterThanOrEqual, 41)
            ->orderBy('age', Sort::Desc)
            ->limit(2)
            ->get();

        self::assertSame([['name' => 'Grace'], ['name' => 'Alan']], $rows);
    }

    public function testFirstReturnsNullWhenNothingMatches(): void
    {
        self::assertNull($this->builder()->where('name', Operator::Equals, 'nobody')->first());
    }

    /**
     * A value that looks like SQL stays a value, end to end through a real driver.
     */
    public function testAHostileValueIsStoredAndMatchedAsData(): void
    {
        $connection = $this->connection();
        $payload = "'; DROP TABLE users; --";
        $connection->execute('INSERT INTO users (name, age) VALUES (?, ?)', [$payload, 1]);

        $row = (new QueryBuilder($connection, 'users'))->where('name', Operator::Equals, $payload)->first();

        self::assertSame($payload, $row['name'] ?? null);
        self::assertSame([['n' => 1]], $connection->select('SELECT COUNT(*) AS n FROM users'));
    }
}
