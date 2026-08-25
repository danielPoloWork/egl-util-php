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
use D4np\Utils\Tests\Database\Fixture\InjectionPayloads;
use D4np\Utils\Tests\Database\Fixture\LoggedStatement;
use D4np\Utils\Tests\Database\Fixture\QueryLog;
use D4np\Utils\Tests\Engine\RunsAgainstADatabaseEngine;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
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
 *
 * **This suite runs against whichever engine the harness is pointed at** (issue #110, ADR-0071).
 * The binding guarantee is the same claim on all three, and it is the claim most worth re-running
 * somewhere other than SQLite: a driver that interpolated, or an engine whose prepares were
 * emulated after all, would fail here and nowhere else. What differs per engine is only whether a
 * bound payload is *storable* once it arrives — see {@see RunsAgainstADatabaseEngine::attempt()}.
 */
#[Group('T-02')]
#[Group('database-engine')]
final class InjectionTest extends TestCase
{
    use RunsAgainstADatabaseEngine;

    private QueryLog $log;

    private DatabaseConnection $connection;

    protected function setUp(): void
    {
        $this->log = new QueryLog();

        $pdo = $this->enginePdo();
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [LoggedStatement::class, [$this->log]]);

        $this->connection = new DatabaseConnection($pdo);
        $this->createFixtureTable($pdo, 'users', ['id' => 'key', 'name' => 'text']);
        $this->createFixtureTable($pdo, 'secrets', ['token' => 'text']);
        $this->connection->execute(SqlStatement::literal("INSERT INTO secrets (token) VALUES ('do-not-leak')"));

        // Only the payload traffic matters; drop the fixture's own setup from the log.
        $this->log->entries = [];
    }

    /**
     * The value corpus, from the one place it lives (item 10.5).
     *
     * @return iterable<string, array{string}>
     */
    public static function payloads(): iterable
    {
        yield from InjectionPayloads::values();
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
        $this->attempt(fn () => $this->connection->execute(
            SqlStatement::literal('INSERT INTO users (name) VALUES (?)', [$payload]),
        ));

        $this->assertNeverInStatementText($payload);
    }

    #[DataProvider('payloads')]
    public function testConnectionSelectBindsRatherThanInterpolates(string $payload): void
    {
        $this->attempt(fn () => $this->connection->select(
            SqlStatement::literal('SELECT * FROM users WHERE name = ?', [$payload]),
        ));

        $this->assertNeverInStatementText($payload);
    }

    #[DataProvider('payloads')]
    public function testConnectionSelectOneBindsRatherThanInterpolates(string $payload): void
    {
        $this->attempt(fn () => $this->connection->selectOne(
            SqlStatement::literal('SELECT * FROM users WHERE name = :name', ['name' => $payload]),
        ));

        $this->assertNeverInStatementText($payload);
    }

    #[DataProvider('payloads')]
    public function testQueryBuilderWhereBindsRatherThanInterpolates(string $payload): void
    {
        $this->attempt(fn () => (new QueryBuilder($this->connection, 'users'))
            ->where('name', Operator::Equals, $payload)
            ->orderBy('id', Sort::Desc)
            ->limit(10)
            ->get());

        $this->assertNeverInStatementText($payload);
    }

    #[DataProvider('payloads')]
    public function testQueryBuilderWhereInBindsRatherThanInterpolates(string $payload): void
    {
        $this->attempt(fn () => (new QueryBuilder($this->connection, 'users'))
            ->whereIn('name', [$payload, 'harmless'])
            ->get());

        $this->assertNeverInStatementText($payload);
    }

    #[DataProvider('payloads')]
    public function testBindingHoldsInsideATransaction(string $payload): void
    {
        $this->attempt(fn () => (new Transaction($this->connection))->run(function () use ($payload): void {
            $this->connection->execute(SqlStatement::literal('INSERT INTO users (name) VALUES (?)', [$payload]));
        }));

        $this->assertNeverInStatementText($payload);
    }

    /**
     * Binding is not only about syntax: the value must survive intact, and the schema must be
     * untouched. A payload that were escaped rather than bound could still round-trip — which is
     * why this runs *alongside* the query-log assertion rather than instead of it.
     *
     * **Refused is also an answer.** A payload MySQL or PostgreSQL will not store — a NUL byte, a
     * byte sequence that is not valid UTF-8 — cannot round-trip there, and pretending otherwise
     * would mean either skipping those corpus members or asserting something untrue. What is
     * asserted instead is the half that still has content: the write was refused *cleanly*, no row
     * appeared, and the other table is untouched. Both branches end at the same place — the
     * payload did not become SQL.
     */
    #[DataProvider('payloads')]
    public function testThePayloadRoundTripsAndTheSchemaSurvives(string $payload): void
    {
        $stored = $this->attempt(fn () => $this->connection->execute(
            SqlStatement::literal('INSERT INTO users (name) VALUES (?)', [$payload]),
        ));

        if ($stored) {
            $row = $this->connection->selectOne(
                SqlStatement::literal('SELECT name FROM users WHERE name = ?', [$payload]),
            );

            // `storedForm()` is the identity for every payload and every engine but one: PDO_PGSQL
            // truncates a bound parameter at its first NUL byte, silently and without raising.
            // Found by this leg's first run; pinned in Engine::storedForm() and asserted on its
            // own in DialectTest.
            self::assertSame($this->engine()->storedForm($payload), $row['name'] ?? null);
        }

        self::assertSame(
            $stored ? 1 : 0,
            $this->rowCount(SqlStatement::literal('SELECT COUNT(*) AS n FROM users')),
            $stored ? 'the payload was accepted but did not land as one row' : 'a refused write left a row behind',
        );
        // Exfiltration and destruction both leave traces here.
        self::assertSame(1, $this->rowCount(SqlStatement::literal('SELECT COUNT(*) AS n FROM secrets')));
    }

    /**
     * `COUNT(*)` as an `int`, whatever the driver called it.
     *
     * The cast is the engine-portable part: the three drivers do not agree on the PHP type of a
     * `COUNT(*)`, which is a divergence with a consequence — `Repository::countRowsOf()` carries
     * the same cast for the same reason — and it is pinned as its own assertion in
     * {@see \D4np\Utils\Tests\Engine\DialectTest} rather than re-litigated in every suite that
     * counts rows.
     */
    private function rowCount(SqlStatement $count): int
    {
        $value = $this->connection->selectOne($count)['n'] ?? null;

        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && \is_numeric($value)) {
            return (int) $value;
        }

        self::fail(\sprintf(
            'COUNT(*) came back as %s, which is neither an int nor a numeric string.',
            \get_debug_type($value),
        ));
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
