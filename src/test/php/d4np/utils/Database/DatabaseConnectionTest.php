<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Database;

use D4np\Utils\Database\DatabaseConnection;
use D4np\Utils\Database\SqlStatement;
use D4np\Utils\Support\DatabaseException;
use D4np\Utils\Tests\Database\Fixture\PretendDriverPdo;
use D4np\Utils\Tests\Database\Fixture\StubbornlyEmulatingPdo;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Spec FR-06's pinned defaults, against a real driver rather than a mock.
 *
 * SQLite in memory is used because the assertions here are about what PDO *actually does* — an
 * attribute a driver silently refuses, a fetch mode that changes the shape of a row, an error
 * mode that decides between an exception and a `false`. A doubled `PDO` would return whatever the
 * double was told to, which is the belief under test rather than a test of it.
 *
 * The MySQL-only behaviour (`SET NAMES utf8mb4`) cannot be reached this way and is covered by the
 * driver-dispatch assertion below plus, eventually, T-02 (roadmap 4.4).
 */
#[Group('T-02')]
#[RequiresPhpExtension('pdo_sqlite')]
final class DatabaseConnectionTest extends TestCase
{
    private function connect(): DatabaseConnection
    {
        $connection = new DatabaseConnection(new PDO('sqlite::memory:'));
        $connection->execute(new SqlStatement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, age INTEGER)'));

        return $connection;
    }

    public function testErrorModeIsPinnedToExceptions(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

        new DatabaseConnection($pdo);

        self::assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
    }

    public function testDefaultFetchModeIsPinnedToAssoc(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_BOTH);

        new DatabaseConnection($pdo);

        self::assertSame(PDO::FETCH_ASSOC, $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
    }

    /**
     * Note what this does and does not cover: `select()` passes `FETCH_ASSOC` to `fetchAll()`
     * explicitly, so this passes even if the *pinned default* were removed. It is a regression
     * test for `select()`'s own contract. The pin itself is covered by
     * {@see self::testThePinnedFetchModeAppliesToStatementsRunOnTheRawPdo()}, which was added
     * after deliberately removing the pin and finding this test still green.
     */
    public function testSelectReturnsAssociativeArraysOnly(): void
    {
        $connection = $this->connect();
        $connection->execute(new SqlStatement('INSERT INTO users (name, age) VALUES (?, ?)', ['Grace', 45]));

        $rows = $connection->select(new SqlStatement('SELECT name, age FROM users'));

        // FETCH_BOTH would additionally carry 0 and 1; asserting the exact key set is the point.
        self::assertSame([['name' => 'Grace', 'age' => 45]], $rows);
    }

    /**
     * The pinned fetch mode has to reach statements the *consumer* runs, not just this class's own
     * helpers — that is the whole value of pinning a default on a connection someone else owns.
     */
    public function testThePinnedFetchModeAppliesToStatementsRunOnTheRawPdo(): void
    {
        $connection = $this->connect();
        $connection->execute(new SqlStatement('INSERT INTO users (name, age) VALUES (?, ?)', ['Grace', 45]));

        // No fetch mode passed anywhere here: whatever comes back is the pinned default's doing.
        $statement = $connection->pdo()->query('SELECT name, age FROM users');
        self::assertNotFalse($statement);

        self::assertSame([['name' => 'Grace', 'age' => 45]], $statement->fetchAll());
    }

    public function testConstructorIsAcceptedOnADriverWithNoEmulationConcept(): void
    {
        // SQLite returns false from setAttribute(ATTR_EMULATE_PREPARES) and throws when the
        // attribute is read back. That combination means "no such concept", not "refused", and
        // must not be treated as a failure to pin.
        $this->expectNotToPerformAssertions();

        new DatabaseConnection(new PDO('sqlite::memory:'));
    }

    /**
     * The security branch no reachable real driver exercises.
     *
     * SQLite cannot emulate prepares and MySQL honours the attribute, so without a stand-in the
     * refusal path in `requireRealPrepares()` would never run — the most security-relevant
     * behaviour in FR-06 would be present, plausible, and completely unexecuted by the suite.
     */
    public function testAConnectionStillEmulatingPreparesIsRefused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/still emulating prepared statements/');

        new DatabaseConnection(new StubbornlyEmulatingPdo());
    }

    /**
     * Item 4.1 shipped this line unexecuted by any test, and said so: `SET NAMES utf8mb4` is
     * MySQL-only and there is no MySQL server in CI. {@see PretendDriverPdo} closes the half that
     * is closeable — that the statement is issued, and issued *only* for MySQL. It does not prove
     * a real MySQL server accepts it; that belongs to T-02's driver matrix (item 4.4).
     */
    public function testUtf8mb4IsSetOnMysqlOnly(): void
    {
        $mysql = new PretendDriverPdo('mysql');
        new DatabaseConnection($mysql);
        self::assertContains('SET NAMES utf8mb4', $mysql->executed);

        $pgsql = new PretendDriverPdo('pgsql');
        new DatabaseConnection($pgsql);
        self::assertSame([], $pgsql->executed, 'SET NAMES is MySQL-specific and must not be issued elsewhere');
    }

    public function testDriverNameIsReported(): void
    {
        self::assertSame('sqlite', $this->connect()->driver());
    }

    public function testPdoAccessorReturnsTheSameConnection(): void
    {
        $pdo = new PDO('sqlite::memory:');

        self::assertSame($pdo, (new DatabaseConnection($pdo))->pdo());
    }

    public function testSelectOneReturnsNullRatherThanFalseWhenThereIsNoRow(): void
    {
        self::assertNull($this->connect()->selectOne(new SqlStatement('SELECT * FROM users WHERE id = ?', [404])));
    }

    public function testSelectOneReturnsTheFirstRow(): void
    {
        $connection = $this->connect();
        $connection->execute(new SqlStatement('INSERT INTO users (name, age) VALUES (?, ?)', ['Ada', 36]));
        $connection->execute(new SqlStatement('INSERT INTO users (name, age) VALUES (?, ?)', ['Alan', 41]));

        self::assertSame(['id' => 1, 'name' => 'Ada', 'age' => 36], $connection->selectOne(new SqlStatement('SELECT * FROM users ORDER BY id')));
    }

    public function testExecuteReportsAffectedRows(): void
    {
        $connection = $this->connect();
        $connection->execute(new SqlStatement('INSERT INTO users (name, age) VALUES (?, ?)', ['Ada', 36]));
        $connection->execute(new SqlStatement('INSERT INTO users (name, age) VALUES (?, ?)', ['Alan', 41]));

        self::assertSame(2, $connection->execute(new SqlStatement('UPDATE users SET age = age + 1')));
    }

    public function testNamedParametersBind(): void
    {
        $connection = $this->connect();
        $connection->execute(new SqlStatement('INSERT INTO users (name, age) VALUES (:name, :age)', ['name' => 'Ada', 'age' => 36]));

        self::assertSame([['name' => 'Ada']], $connection->select(new SqlStatement('SELECT name FROM users WHERE age = :age', ['age' => 36])));
    }

    /**
     * The point of pinning real prepares: a value that looks like SQL is data, not syntax.
     *
     * With emulation on, this string would be interpolated into the statement text client-side
     * before the driver ever saw it. Bound, it can only ever be a value — so the row is stored
     * and read back verbatim, and the table it names is still there.
     */
    public function testAValueThatLooksLikeSqlIsStoredAsData(): void
    {
        $connection = $this->connect();
        $payload = "Robert'); DROP TABLE users;--";

        $connection->execute(new SqlStatement('INSERT INTO users (name, age) VALUES (?, ?)', [$payload, 1]));

        self::assertSame($payload, $connection->selectOne(new SqlStatement('SELECT name FROM users'))['name'] ?? null);
        self::assertSame([['n' => 1]], $connection->select(new SqlStatement('SELECT COUNT(*) AS n FROM users')));
    }

    public function testAFailingStatementRaisesTheLibraryException(): void
    {
        $this->expectException(DatabaseException::class);

        $this->connect()->select(new SqlStatement('SELECT * FROM a_table_that_does_not_exist'));
    }

    public function testTheFailureCarriesThePdoExceptionAsItsCause(): void
    {
        try {
            $this->connect()->select(new SqlStatement('THIS IS NOT SQL'));
            self::fail('expected a DatabaseException');
        } catch (DatabaseException $e) {
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
        }
    }

    /**
     * A failing statement's text is the most likely place for data that should not reach a log.
     */
    public function testTheFailureMessageDoesNotEchoTheStatement(): void
    {
        try {
            $this->connect()->select(new SqlStatement('SELECT * FROM missing_table WHERE secret = 1'));
            self::fail('expected a DatabaseException');
        } catch (DatabaseException $e) {
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }
}
