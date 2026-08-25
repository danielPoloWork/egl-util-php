<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Engine;

use PDO;
use PDOException;
use RuntimeException;

/**
 * The one place a behavioural database suite gets its connection from (issue #110, ADR-0071).
 *
 * Until this existed, every suite that needed a real database wrote `new PDO('sqlite::memory:')`
 * and declared `#[RequiresPhpExtension('pdo_sqlite')]`. That is sixteen independent decisions to
 * run on SQLite, so pointing the suite at MySQL or PostgreSQL was sixteen edits — which is the
 * practical reason the engine coverage the review board asked for had never been added.
 *
 * **Configuration is one environment variable.** {@see self::DSN} names a PDO DSN; unset, the
 * harness is `sqlite::memory:` and every developer's `vendor/bin/phpunit` behaves exactly as it
 * did before. Set, the same suites run against that engine.
 *
 * ```bash
 * EGL_TEST_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=egl_utils_test;charset=utf8mb4' \
 * EGL_TEST_DB_USER=root EGL_TEST_DB_PASSWORD=secret vendor/bin/phpunit --group database-engine
 * ```
 *
 * **Fail, never skip.** Issue #110's third acceptance criterion, and the one that decides whether
 * the leg is worth having: a CI job that goes green because it could not reach the database is
 * strictly worse than no job, since it reports coverage nobody has. So the two cases are kept
 * apart. With no DSN configured the suites may still *skip* when `pdo_sqlite` is missing — that
 * is the pre-existing behaviour of a bare checkout and nothing here claims otherwise. With a DSN
 * configured, a missing driver or an unreachable server raises, and the leg is red.
 *
 * **One connection per process, not per test.** The suites this serves run several thousand tests
 * between them; a fresh TCP connection and handshake each time turns a two-minute leg into a long
 * one for no assertion gained. Isolation comes from {@see self::createTable()} dropping and
 * recreating the fixture tables in every `setUp()`, and from {@see self::reset()} undoing the two
 * pieces of connection state a test can leave behind — an open transaction and a custom statement
 * class.
 */
final class TestDatabase
{
    /** The PDO DSN to run the behavioural suites against. Unset means `sqlite::memory:`. */
    public const DSN = 'EGL_TEST_DB_DSN';

    /** The username for {@see self::DSN}. Ignored by SQLite. */
    public const USER = 'EGL_TEST_DB_USER';

    /** The password for {@see self::DSN}. Ignored by SQLite. */
    public const PASSWORD = 'EGL_TEST_DB_PASSWORD';

    private const DEFAULT_DSN = 'sqlite::memory:';

    private static ?PDO $pdo = null;

    private function __construct()
    {
    }

    /**
     * Whether an engine was configured, as opposed to the in-memory SQLite default.
     *
     * This is the switch between "skip is acceptable" and "skip is a lie", so it reads the
     * variable rather than inferring from the resolved engine: `EGL_TEST_DB_DSN=sqlite:/tmp/x.db`
     * is a configured leg that happens to be SQLite, and an unreachable file there must fail.
     */
    public static function isConfigured(): bool
    {
        return self::dsn() !== null;
    }

    /**
     * The engine in force — the configured one, or SQLite.
     */
    public static function engine(): Engine
    {
        $dsn = self::dsn();

        return $dsn === null ? Engine::Sqlite : Engine::fromDsn($dsn);
    }

    /**
     * Whether this is the unconfigured in-memory SQLite run.
     *
     * Suites use it for the one thing an engine-parameterised assertion legitimately needs to
     * know: whether a refusal is allowed. A payload MySQL rejects for its encoding is a fact
     * about MySQL; the same payload raising on SQLite would be a regression, and this is what
     * keeps the original proof exactly as strong as it was.
     */
    public static function isDefaultEngine(): bool
    {
        return !self::isConfigured();
    }

    /**
     * The shared connection, with the two pieces of per-test state reset.
     *
     * @throws RuntimeException when a configured engine has no driver or cannot be reached
     */
    public static function pdo(): PDO
    {
        $pdo = self::$pdo ?? self::open();
        self::$pdo = $pdo;

        self::reset($pdo);

        return $pdo;
    }

    /**
     * Undo what a test can leave on a shared connection.
     *
     * Two things, and both have bitten a shared-connection harness before. An exception thrown
     * inside a transaction leaves it open, and on MySQL the next `CREATE TABLE` commits it
     * implicitly — so the *following* test inherits half of this one's writes. And a suite that
     * installs a logging `PDOStatement` subclass leaves every later suite logging into an object
     * it has no reference to.
     */
    public static function reset(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // Class only, no constructor-argument array: PDO raises "User-supplied statement does not
        // accept constructor arguments" if one is supplied for the base PDOStatement.
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
    }

    /**
     * Drop and recreate a fixture table, in the dialect of the engine in force.
     *
     * `DROP … IF EXISTS` rather than a transaction rollback because MySQL commits DDL implicitly:
     * there is no engine-portable way to *undo* a fixture table, so it is replaced instead.
     *
     * @param array<string, 'key'|'text'|'int'> $columns column name => shape
     */
    public static function createTable(PDO $pdo, string $table, array $columns): void
    {
        $engine = self::engine();

        $definitions = [];
        foreach ($columns as $name => $shape) {
            $definitions[] = $name . ' ' . $engine->column($shape);
        }

        $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        $pdo->exec('CREATE TABLE ' . $table . ' (' . \implode(', ', $definitions) . ')');
    }

    /**
     * Forget the shared connection — for the one test that needs to observe opening.
     */
    public static function forget(): void
    {
        self::$pdo = null;
    }

    /**
     * @throws RuntimeException when a configured engine has no driver or cannot be reached
     */
    private static function open(): PDO
    {
        $dsn = self::dsn() ?? self::DEFAULT_DSN;
        $engine = self::engine();

        if (!\extension_loaded($engine->extension())) {
            throw new RuntimeException(\sprintf(
                'The database leg is configured for %s (%s=%s) but the %s extension is not '
                . 'loaded. This is a failure rather than a skip on purpose: a leg that goes '
                . 'green without reaching an engine reports coverage nobody has (issue #110).',
                $engine->label(),
                self::DSN,
                $dsn,
                $engine->extension(),
            ));
        }

        try {
            $pdo = new PDO($dsn, self::env(self::USER), self::env(self::PASSWORD));
            // Before DatabaseConnection gets a chance to pin it, because createTable() runs its
            // DDL through raw PDO and a failed CREATE that returns `false` would surface much
            // later as an inexplicably absent table.
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return $pdo;
        } catch (PDOException $e) {
            throw new RuntimeException(\sprintf(
                'Cannot reach the %s server this leg is configured for (%s=%s): %s. Failing '
                . 'rather than skipping — see issue #110.',
                $engine->label(),
                self::DSN,
                $dsn,
                $e->getMessage(),
            ), 0, $e);
        }
    }

    private static function dsn(): ?string
    {
        return self::env(self::DSN);
    }

    /**
     * An environment variable, with the empty string treated as absent.
     *
     * A workflow that writes `EGL_TEST_DB_DSN: ${{ matrix.dsn }}` and forgets a matrix key sets
     * the variable to `''`, and "configured to nothing" is the one reading that must not survive
     * into a green run.
     */
    private static function env(string $name): ?string
    {
        $value = \getenv($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
