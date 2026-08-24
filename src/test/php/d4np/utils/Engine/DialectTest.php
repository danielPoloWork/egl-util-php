<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Engine;

use D4np\Utils\Database\DatabaseConnection;
use D4np\Utils\Database\Identifier;
use D4np\Utils\Database\Operator;
use D4np\Utils\Database\QueryBuilder;
use D4np\Utils\Database\SqlStatement;
use D4np\Utils\Database\Transaction;
use D4np\Utils\Security\Sanitizer;
use D4np\Utils\Support\DatabaseException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Where the three engines **disagree**, pinned (issue #110's second acceptance criterion, ADR-0071).
 *
 * The converted suites carry the library's own claims onto MySQL and PostgreSQL. This one carries
 * nothing: every test here exists because the engines behave *differently*, and the difference is
 * either something the library already reasons about in a docblock or something a consumer would
 * be bitten by. A divergence discovered by the leg and left unpinned is a divergence that will be
 * rediscovered.
 *
 * Each test is written so that **both arms execute** — the SQLite arm on every developer's laptop
 * and in the ordinary CI matrix, the other two in the database leg. A `match` whose non-default
 * arms nobody ever runs is the state this repository was already in; five of the sentences these
 * tests replace were written *about* MySQL and PostgreSQL and had never been run against either.
 */
#[Group('database-engine')]
#[Group('dialect')]
final class DialectTest extends TestCase
{
    use RunsAgainstADatabaseEngine;

    private DatabaseConnection $connection;

    protected function setUp(): void
    {
        $pdo = $this->enginePdo();
        $this->connection = new DatabaseConnection($pdo);
        $this->createFixtureTable($pdo, 'dialect_rows', [
            'id' => 'key',
            'name' => 'text',
            'quantity' => 'int',
        ]);
    }

    private function seed(int $id, string $name, ?int $quantity = 1): void
    {
        $this->connection->execute(SqlStatement::literal(
            'INSERT INTO dialect_rows (id, name, quantity) VALUES (?, ?, ?)',
            [$id, $name, $quantity],
        ));
    }

    // ---- identifiers ---------------------------------------------------------------------------

    /**
     * `Identifier::forDriver()`'s `match` is a claim about four families of engine, and until the
     * database leg existed exactly one arm of it had ever reached a server.
     *
     * Two assertions, because either alone is satisfiable by a wrong implementation: the quote
     * characters are the ones this driver documents, **and** the engine parses what they produce.
     */
    public function testTheDriversQuotingIsBothTheDocumentedFormAndOneTheEngineAccepts(): void
    {
        $quoted = Identifier::forDriver($this->connection->driver())->quote('name');

        self::assertSame(
            match ($this->engine()) {
                Engine::MySql => '`name`',
                Engine::Sqlite, Engine::PostgreSql => '"name"',
            },
            $quoted,
        );

        $this->seed(1, 'Ada');

        self::assertSame(
            [['name' => 'Ada']],
            $this->connection->select(SqlStatement::literal(
                'SELECT ' . $quoted . ' FROM dialect_rows WHERE id = ?',
                [1],
            )),
        );
    }

    /**
     * Quoting exists so a reserved word can be a column name; three engines, three reserved-word
     * lists, one round trip that has to work on all of them.
     *
     * `order` is reserved on every engine here, which is what makes it the right name to use: an
     * identifier that only *some* of them would have refused unquoted would leave the test green
     * on the engines that never had the problem.
     */
    public function testAReservedWordSurvivesAsAColumnNameBecauseItIsQuoted(): void
    {
        $identifiers = Identifier::forDriver($this->connection->driver());
        $pdo = $this->connection->pdo();

        $pdo->exec('DROP TABLE IF EXISTS dialect_reserved');
        $pdo->exec(
            'CREATE TABLE dialect_reserved ('
            . $identifiers->quote('id') . ' ' . $this->engine()->integer() . ', '
            . $identifiers->quote('order') . ' ' . $this->engine()->integer()
            . ')',
        );
        // composed() rather than literal(): the column names are assembled from Identifier at
        // runtime, which is the whole subject here, and PHPStan's literal-string check cannot see
        // that both of them are constants this file wrote. Every value still binds.
        $this->connection->execute(SqlStatement::composed(
            'INSERT INTO dialect_reserved (' . $identifiers->quote('id') . ', '
            . $identifiers->quote('order') . ') VALUES (?, ?)',
            [1, 7],
        ));

        $rows = (new QueryBuilder($this->connection, 'dialect_reserved'))
            ->select('order')
            ->where('id', Operator::Equals, 1)
            ->get();

        self::assertSame([['order' => 7]], $rows);
    }

    /**
     * **The unknown-column divergence ADR-0044 records, measured on all three engines.**
     *
     * `TableGateway` cannot tell, without a schema round trip, that a DTO declares a column the
     * table lacks. ADR-0044 accepts that and rests on the claim that the mistake is loud anyway —
     * at the *driver* on MySQL and PostgreSQL, and one layer up in strict hydration on SQLite,
     * whose double-quoted-string misfeature turns `"nickname"` into the literal `'nickname'` when
     * no such column resolves. That claim had never been executed against MySQL or PostgreSQL.
     *
     * Note that MySQL would *also* have accepted a double-quoted string literal — its default
     * `sql_mode` reads `"…"` exactly the way SQLite does. It is refused here because the library
     * quotes MySQL identifiers with backticks, which is the whole reason `Identifier` has a
     * per-driver `match` at all. So the difference this test pins is a property of the library and
     * the engine together, not of the engine alone.
     */
    public function testAnUnknownColumnIsRefusedAtPrepareTimeExceptOnSqlite(): void
    {
        $unknown = Identifier::forDriver($this->connection->driver())->quote('no_such_column');
        // composed() for the same reason as above: the identifier is the library's own quoting
        // of a constant, assembled here so that the engine sees exactly what a gateway would send.
        $statement = SqlStatement::composed('SELECT ' . $unknown . ' AS c FROM dialect_rows');

        $this->seed(1, 'Ada');

        if ($this->engine() === Engine::Sqlite) {
            self::assertSame(
                [['c' => 'no_such_column']],
                $this->connection->select($statement),
                'SQLite is expected to read the double-quoted name as a string literal',
            );

            return;
        }

        $this->expectException(DatabaseException::class);

        $this->connection->select($statement);
    }

    // ---- LIKE and its escape -------------------------------------------------------------------

    /**
     * **The claim `Sanitizer::LIKE_ESCAPE`'s docblock is built on**, which is why `!` and not `\`.
     *
     * MySQL and PostgreSQL treat a backslash as an escape character inside a `LIKE` pattern with
     * no `ESCAPE` clause present; SQLite does not, and reads it as an ordinary character. So the
     * *same* pattern, bound identically, matches on two engines and not on the third — the exact
     * failure mode a library-wide escape character has to avoid.
     *
     * Written with raw SQL and a bound pattern rather than through `QueryBuilder`, because the
     * subject here is the engine and not the builder.
     */
    public function testABackslashIsAnImplicitLikeEscapeOnMySqlAndPostgresButNotSqlite(): void
    {
        $this->seed(1, '100%');
        $this->seed(2, '100 percent');

        $matched = $this->connection->select(SqlStatement::literal(
            'SELECT id FROM dialect_rows WHERE name LIKE ?',
            ['100\\%'],
        ));

        self::assertSame(
            match ($this->engine()) {
                // The backslash escaped the wildcard, so the pattern is the literal '100%'.
                Engine::MySql, Engine::PostgreSql => [['id' => 1]],
                // The backslash is just a character, so the pattern is "100, backslash, anything"
                // and nothing in the table starts that way.
                Engine::Sqlite => [],
            },
            $matched,
        );
    }

    /**
     * …and the resolution of that divergence, which is what the library actually ships: an
     * explicit `ESCAPE '!'` clause behaves the same on all three.
     *
     * The pairing is the point. The test above shows the default is not portable; this one shows
     * `QueryBuilder::whereLike()` does not depend on the default.
     */
    public function testAnExplicitEscapeClauseNeutralisesAWildcardIdenticallyOnEveryEngine(): void
    {
        $this->seed(1, '100%');
        $this->seed(2, '100 percent');

        $literal = (new QueryBuilder($this->connection, 'dialect_rows'))
            ->select('id')
            ->whereLike('name', Sanitizer::sqlLikePattern('100%'))
            ->get();

        self::assertSame([['id' => 1]], $literal, 'the escaped wildcard must match only the literal');

        $wildcard = (new QueryBuilder($this->connection, 'dialect_rows'))
            ->select('id')
            ->whereLike('name', '100%')
            ->orderBy('id')
            ->get();

        self::assertSame([['id' => 1], ['id' => 2]], $wildcard, 'an unescaped wildcard is still a wildcard');
    }

    // ---- transactions --------------------------------------------------------------------------

    /**
     * **Savepoint nesting**, which `Transaction` implements with three statements — `SAVEPOINT`,
     * `ROLLBACK TO SAVEPOINT`, `RELEASE SAVEPOINT` — and which had only ever been executed by
     * SQLite.
     *
     * The assertion is the property nesting exists for and the one an engine could plausibly get
     * wrong: an inner failure undoes the inner work **and nothing else**, and the outer
     * transaction still commits.
     */
    public function testAFailedInnerScopeRollsBackToItsSavepointAndTheOuterStillCommits(): void
    {
        $transaction = new Transaction($this->connection);

        $transaction->run(function () use ($transaction): void {
            $this->seed(1, 'outer');

            try {
                $transaction->run(function (): void {
                    $this->seed(2, 'inner');

                    throw new RuntimeException('the inner scope fails');
                });
            } catch (RuntimeException) {
                // Expected: the point is what the database is left holding.
            }

            $this->seed(3, 'after the inner failure');
        });

        self::assertSame(
            [['id' => 1], ['id' => 3]],
            $this->connection->select(SqlStatement::literal('SELECT id FROM dialect_rows ORDER BY id')),
        );
    }

    // ---- what the driver hands back --------------------------------------------------------------

    /**
     * **Driver type coercion, which strict hydration depends on absolutely.**
     *
     * A DTO declares `public readonly ?int $age`, the hydrator calls the constructor under
     * `strict_types=1`, and a driver that returns `'36'` instead of `36` makes every gateway read
     * on that engine a `HydrationException`. That is not a cosmetic difference; it is whether the
     * `Persistence` group works at all on the engine in question. The suites cannot assert it —
     * they would just fail obscurely — so it is asserted here, once, in the place a reader looks
     * when a gateway starts refusing rows.
     */
    public function testAnIntegerColumnComesBackAsAPhpInt(): void
    {
        $this->seed(1, 'Ada', 36);

        $row = $this->connection->selectOne(SqlStatement::literal(
            'SELECT quantity FROM dialect_rows WHERE id = ?',
            [1],
        ));

        self::assertIsInt(
            $row['quantity'] ?? null,
            'strict DTO hydration cannot survive a driver that stringifies integers; if this '
            . 'fails, ADR-0071 and ADR-0008 both need the finding written into them.',
        );
    }

    /**
     * `COUNT(*)`, separately, because it is a different question with a different answer on some
     * engines: the column above has a declared integer type, while an aggregate's type is the
     * driver's own choice — PostgreSQL's is `bigint`.
     *
     * `Repository::countRowsOf()` already casts, and its docblock says the value arrives "an `int`
     * on some, a string on others". This is the measurement behind that sentence.
     */
    public function testACountComesBackAsAPhpInt(): void
    {
        $this->seed(1, 'Ada');
        $this->seed(2, 'Grace');

        $row = $this->connection->selectOne(SqlStatement::literal('SELECT COUNT(*) AS n FROM dialect_rows'));

        self::assertIsInt(
            $row['n'] ?? null,
            'if this fails the cast in Repository::countRowsOf() is load-bearing rather than '
            . 'defensive, and ADR-0071 records which engine made it so.',
        );
    }

    /**
     * A `NULL` is a `null`, not the empty string — the divergence Oracle is famous for and which
     * these three do not have. Pinned because "these three agree" is a fact with a shelf life: it
     * is the assertion that would fail first if a fourth engine were added to the leg.
     */
    public function testANullColumnComesBackAsNullRatherThanAnEmptyString(): void
    {
        $this->seed(1, 'Ada', null);

        $row = $this->connection->selectOne(SqlStatement::literal(
            'SELECT quantity FROM dialect_rows WHERE id = ?',
            [1],
        ));

        self::assertIsArray($row);
        // `??` is the wrong operator for this assertion — it cannot tell a NULL column from an
        // absent key, which is half of what is being asserted.
        self::assertArrayHasKey('quantity', $row);
        self::assertNull($row['quantity']);
    }

    /**
     * **The one thing this leg found that nobody had written down: PDO_PGSQL silently truncates a
     * bound parameter at its first NUL byte.**
     *
     * Not an error, not a warning — the `INSERT` succeeds, one row appears, and the tail of the
     * value is gone. The server is not the culprit and would in fact refuse the byte outright:
     * `0x00` is not valid in a PostgreSQL `text` column. libpq is, because it sends a bound
     * parameter as a NUL-terminated C string, so nothing after the first NUL ever leaves the
     * client. MySQL 8.4 and SQLite store the whole value.
     *
     * This is silent data loss on one of three supported engines, and there is nothing in this
     * library to fix: the value is bound correctly and PDO shortens it below us. So it is recorded
     * — here, in {@see Engine::storedForm()}, and in ADR-0071 — because the only defence a
     * consumer has is knowing.
     *
     * Discovered by CI run 32743502415 on PostgreSQL 16.15, which is exactly the kind of thing
     * issue #110 was opened to surface.
     */
    public function testANulByteInABoundParameterIsTruncatedByThePostgresDriverAlone(): void
    {
        $payload = "before\0after";

        $this->connection->execute(SqlStatement::literal(
            'INSERT INTO dialect_rows (id, name, quantity) VALUES (?, ?, ?)',
            [1, $payload, 1],
        ));

        $row = $this->connection->selectOne(SqlStatement::literal(
            'SELECT name FROM dialect_rows WHERE id = ?',
            [1],
        ));

        self::assertSame(
            match ($this->engine()) {
                Engine::PostgreSql => 'before',
                Engine::Sqlite, Engine::MySql => $payload,
            },
            $row['name'] ?? null,
        );
    }

    // ---- collation ---------------------------------------------------------------------------------

    /**
     * **Why the fixture schema pins a collation on MySQL and nowhere else.**
     *
     * MySQL 8's server default, `utf8mb4_0900_ai_ci`, compares text case- and accent-insensitively:
     * `WHERE name = 'ada'` finds a stored `'Ada'`. SQLite's `BINARY` default and a stock
     * PostgreSQL cluster do not. Equality in a `WHERE` clause is the primitive every criterion in
     * `TableGateway` is built from, so this is not a footnote — a gateway lookup that is exact on
     * two engines is fuzzy on the third, and a consumer who has only run against one of them will
     * not know.
     *
     * The table here is built with the engine's **default** text type, deliberately unlike
     * {@see Engine::text()}, so the assertion sees what a consumer's own schema would do.
     */
    public function testTextEqualityIsCaseInsensitiveByDefaultOnMySqlAndNotOnTheOthers(): void
    {
        $pdo = $this->connection->pdo();
        $pdo->exec('DROP TABLE IF EXISTS dialect_collation');
        $pdo->exec('CREATE TABLE dialect_collation (name TEXT)');
        $this->connection->execute(SqlStatement::literal(
            'INSERT INTO dialect_collation (name) VALUES (?)',
            ['Ada'],
        ));

        $matched = $this->connection->select(SqlStatement::literal(
            'SELECT name FROM dialect_collation WHERE name = ?',
            ['ada'],
        ));

        self::assertSame(
            match ($this->engine()) {
                Engine::MySql => [['name' => 'Ada']],
                Engine::Sqlite, Engine::PostgreSql => [],
            },
            $matched,
        );
    }
}
