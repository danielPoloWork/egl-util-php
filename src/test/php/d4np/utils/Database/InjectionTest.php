<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Database;

use D4np\Utils\Database\DatabaseConnection;
use D4np\Utils\Database\Operator;
use D4np\Utils\Database\QueryBuilder;
use D4np\Utils\Database\Sort;
use D4np\Utils\Database\SqlStatement;
use D4np\Utils\Database\Transaction;
use D4np\Utils\Security\Sanitizer;
use D4np\Utils\Tests\Database\Fixture\LoggedStatement;
use D4np\Utils\Tests\Database\Fixture\QueryLog;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Spec §7's **T-02 injection suite**, first leg: *"fuzzed value payloads reach the driver only as
 * bound parameters via query-log assertion"*.
 *
 * Items 4.1–4.3 asserted this indirectly — a hostile value round-tripped intact and the table
 * survived, which is *consistent with* binding but does not prove it. A value can round-trip
 * intact through correct escaping too. This suite proves the stronger property directly, by
 * watching the boundary: for every payload, the statement text handed to the driver contains
 * **only placeholders**, and the payload appears **only** in the bound parameter array.
 *
 * Every path that accepts a value is covered, because the guarantee is worth exactly as much as
 * its leakiest entry point: `DatabaseConnection`'s three query methods, `QueryBuilder`'s `where`
 * and `whereIn`, and the same through a `Transaction`.
 *
 * The other two legs of T-02: identifier injection is covered by `QueryBuilderTest` (17 hostile
 * identifiers × 4 surfaces, same group), and LIKE-wildcard escaping — open when this file was
 * written at item 4.4 — is closed by roadmap item 5.2, with
 * {@see self::testLikeWildcardsAreNeutralisedWhileTheValueStillBinds()} here and the driver-level
 * cases in `SanitizerTest`. **T-02 is now complete.**
 */
#[Group('T-02')]
#[RequiresPhpExtension('pdo_sqlite')]
final class InjectionTest extends TestCase
{
    private QueryLog $log;

    private DatabaseConnection $connection;

    protected function setUp(): void
    {
        $this->log = new QueryLog();

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [LoggedStatement::class, [$this->log]]);

        $this->connection = new DatabaseConnection($pdo);
        $this->connection->execute(SqlStatement::literal('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)'));
        $this->connection->execute(SqlStatement::literal('CREATE TABLE secrets (token TEXT)'));
        $this->connection->execute(SqlStatement::literal("INSERT INTO secrets (token) VALUES ('do-not-leak')"));

        // Only the payload traffic matters; drop the fixture's own setup from the log.
        $this->log->entries = [];
    }

    /**
     * A corpus aimed at the ways SQL injection actually works, rather than a list of scary
     * strings: quote break-out, comment truncation, stacked statements, UNION exfiltration,
     * backslash and multibyte escaping tricks, and the encoding edge cases that defeat naive
     * escapers.
     *
     * @return iterable<string, array{string}>
     */
    public static function payloads(): iterable
    {
        yield 'classic tautology' => ["' OR '1'='1"];
        yield 'tautology, double quotes' => ['" OR ""="'];
        yield 'stacked drop' => ["Robert'); DROP TABLE users;--"];
        yield 'stacked delete' => ['1; DELETE FROM users'];
        yield 'comment truncation (dash)' => ["admin'--"];
        yield 'comment truncation (hash)' => ['admin\'#'];
        yield 'block comment' => ["admin'/*"];
        yield 'union exfiltration' => ["' UNION SELECT token FROM secrets --"];
        yield 'union with null padding' => ["' UNION SELECT NULL, token FROM secrets --"];
        yield 'backslash escape' => ["\\' OR 1=1 --"];
        yield 'double backslash' => ["\\\\' OR 1=1 --"];
        // The classic charset attack: 0xBF 0x27 is a valid GBK character whose second byte is a
        // quote. It defeats a client-side escaper that does not know the connection charset --
        // which is exactly the scenario ADR-0014's real-prepares pin removes.
        yield 'GBK multibyte quote' => ["\xbf\x27 OR 1=1 --"];
        yield 'null byte' => ["admin\0' OR 1=1"];
        yield 'newline injection' => ["admin'\nOR 1=1"];
        yield 'CRLF injection' => ["admin'\r\nOR 1=1"];
        yield 'tab injection' => ["admin'\tOR 1=1"];
        yield 'nested quotes' => ["''''''"];
        yield 'percent wildcard' => ['100%'];
        yield 'underscore wildcard' => ['a_b'];
        yield 'sqlite pragma' => ["'; PRAGMA writable_schema = 1;--"];
        yield 'sqlite attach' => ["'; ATTACH DATABASE '/tmp/x' AS x;--"];
        yield 'unicode quote lookalike' => ["admin\u{2019} OR 1=1"];
        yield 'emoji' => ['🙂 OR 1=1'];
        yield 'very long' => [str_repeat("' OR 1=1 --", 500)];
        yield 'only whitespace' => ['   '];
        yield 'empty string' => [''];
        yield 'json-ish' => ['{"$ne": null}'];
        yield 'template expression' => ['${1+1}'];
        yield 'sprintf token' => ['%s %d %%'];
    }

    /**
     * The core assertion, and the one the whole suite exists for.
     *
     */
    private function assertNeverInStatementText(string $payload): void
    {
        $statements = $this->log->statements();

        self::assertNotSame([], $statements, 'nothing was logged — the assertion would be vacuous');

        // The empty string is a degenerate case for a substring check: every string contains it,
        // so "the statement does not contain the payload" can never hold. It stays in the corpus
        // because the *binding* half below is still meaningful for it — an empty value must still
        // travel as a parameter — but the containment half is skipped rather than fudged.
        if ($payload !== '') {
            foreach ($statements as $sql) {
                self::assertStringNotContainsString(
                    $payload,
                    $sql,
                    "the payload reached the driver inside the statement text:\n" . $sql,
                );
            }
        }

        self::assertContains($payload, $this->log->boundValues(), 'the payload was not bound as a parameter');
    }

    #[DataProvider('payloads')]
    public function testConnectionExecuteBindsRatherThanInterpolates(string $payload): void
    {
        $this->connection->execute(SqlStatement::literal('INSERT INTO users (name) VALUES (?)', [$payload]));

        $this->assertNeverInStatementText($payload);
    }

    #[DataProvider('payloads')]
    public function testConnectionSelectBindsRatherThanInterpolates(string $payload): void
    {
        $this->connection->select(SqlStatement::literal('SELECT * FROM users WHERE name = ?', [$payload]));

        $this->assertNeverInStatementText($payload);
    }

    #[DataProvider('payloads')]
    public function testConnectionSelectOneBindsRatherThanInterpolates(string $payload): void
    {
        $this->connection->selectOne(SqlStatement::literal('SELECT * FROM users WHERE name = :name', ['name' => $payload]));

        $this->assertNeverInStatementText($payload);
    }

    #[DataProvider('payloads')]
    public function testQueryBuilderWhereBindsRatherThanInterpolates(string $payload): void
    {
        (new QueryBuilder($this->connection, 'users'))
            ->where('name', Operator::Equals, $payload)
            ->orderBy('id', Sort::Desc)
            ->limit(10)
            ->get();

        $this->assertNeverInStatementText($payload);
    }

    #[DataProvider('payloads')]
    public function testQueryBuilderWhereInBindsRatherThanInterpolates(string $payload): void
    {
        (new QueryBuilder($this->connection, 'users'))
            ->whereIn('name', [$payload, 'harmless'])
            ->get();

        $this->assertNeverInStatementText($payload);
    }

    #[DataProvider('payloads')]
    public function testBindingHoldsInsideATransaction(string $payload): void
    {
        (new Transaction($this->connection))->run(function () use ($payload): void {
            $this->connection->execute(SqlStatement::literal('INSERT INTO users (name) VALUES (?)', [$payload]));
        });

        $this->assertNeverInStatementText($payload);
    }

    /**
     * Binding is not only about syntax: the value must survive intact, and the schema must be
     * untouched. A payload that were escaped rather than bound could still round-trip — which is
     * why this runs *alongside* the query-log assertion rather than instead of it.
     */
    #[DataProvider('payloads')]
    public function testThePayloadRoundTripsAndTheSchemaSurvives(string $payload): void
    {
        $this->connection->execute(SqlStatement::literal('INSERT INTO users (name) VALUES (?)', [$payload]));

        $row = $this->connection->selectOne(SqlStatement::literal('SELECT name FROM users WHERE name = ?', [$payload]));

        self::assertSame($payload, $row['name'] ?? null);
        self::assertSame([['n' => 1]], $this->connection->select(SqlStatement::literal('SELECT COUNT(*) AS n FROM users')));
        // Exfiltration and destruction both leave traces here.
        self::assertSame([['n' => 1]], $this->connection->select(SqlStatement::literal('SELECT COUNT(*) AS n FROM secrets')));
    }

    /**
     * A tautology payload must match nothing, because it is a *value*.
     *
     * This is the practical statement of the whole mechanism: `' OR '1'='1` is famous for
     * returning every row, and bound it returns none.
     */
    public function testATautologyMatchesNothingBecauseItIsAValue(): void
    {
        $this->connection->execute(SqlStatement::literal('INSERT INTO users (name) VALUES (?)', ['Ada']));
        $this->connection->execute(SqlStatement::literal('INSERT INTO users (name) VALUES (?)', ['Grace']));

        $rows = (new QueryBuilder($this->connection, 'users'))
            ->where('name', Operator::Equals, "' OR '1'='1")
            ->get();

        self::assertSame([], $rows);
    }

    /**
     * **T-02's third leg — *"LIKE-wildcard escapes"* — closed by roadmap item 5.2.**
     *
     * Item 4.4 shipped this test asserting the *gap*: a `LIKE` value bound safely but its
     * wildcards were live, so a user-supplied `%` turned an intended lookup into a scan. It named
     * FR-10's `Sanitizer::sqlLikePattern()` as the owner and said which assertion should change
     * when it landed. This is that change.
     *
     * Both halves are asserted, because the pairing is what makes it work: the value still binds
     * (no injection), *and* the wildcard is now inert. `whereLike()` supplies the `ESCAPE` clause
     * without which the escaped pattern would silently match nothing on SQLite.
     */
    public function testLikeWildcardsAreNeutralisedWhileTheValueStillBinds(): void
    {
        $this->connection->execute(SqlStatement::literal('INSERT INTO users (name) VALUES (?)', ['secret-document']));
        $this->connection->execute(SqlStatement::literal('INSERT INTO users (name) VALUES (?)', ['public-note']));

        // Unescaped, a lone '%' still matches everything — it is legitimate pattern syntax, and
        // neutralising it unconditionally would break every prefix search in the library.
        $unescaped = (new QueryBuilder($this->connection, 'users'))->whereLike('name', '%')->get();
        self::assertCount(2, $unescaped);

        // Escaped, the same input is a literal and matches nothing.
        $escaped = (new QueryBuilder($this->connection, 'users'))
            ->whereLike('name', Sanitizer::sqlLikePattern('%'))
            ->get();
        self::assertSame([], $escaped, 'the wildcard should now be a literal percent sign');

        // And binding held throughout — the payload never entered the statement text.
        $this->assertNeverInStatementText('%');
    }
}
