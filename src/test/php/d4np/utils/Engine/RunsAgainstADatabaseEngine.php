<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Engine;

use D4np\Utils\Database\DatabaseConnection;
use D4np\Utils\Support\DatabaseException;
use PDO;

/**
 * What a behavioural database suite mixes in to become engine-parameterised (issue #110, ADR-0071).
 *
 * A trait rather than a base class because these suites already extend `TestCase` and, more to
 * the point, because what they share is *plumbing* and not a contract — there is no `assert…()`
 * here that a subclass could be expected to honour.
 *
 * The three things it provides are the three things the conversion needed:
 *
 * 1. **A connection, on the right terms.** {@see self::engineConnection()} replaces
 *    `new DatabaseConnection(new PDO('sqlite::memory:'))` and the `#[RequiresPhpExtension]`
 *    attribute that stood above it, keeping the skip for a bare checkout and refusing to skip for
 *    a configured leg.
 * 2. **A fixture table that exists on three engines.** {@see self::createFixtureTable()}.
 * 3. **A place to say "this engine may refuse this value".** {@see self::attempt()}, whose whole
 *    design is in its docblock, because a `try`/`catch` in a security suite has to justify itself.
 */
trait RunsAgainstADatabaseEngine
{
    /**
     * The engine in force, for a test that asserts something dialect-specific.
     */
    protected function engine(): Engine
    {
        return TestDatabase::engine();
    }

    /**
     * The shared PDO, skipped-or-failed per issue #110's third criterion.
     */
    protected function enginePdo(): PDO
    {
        if (TestDatabase::isDefaultEngine() && !\extension_loaded(Engine::Sqlite->extension())) {
            // The pre-existing behaviour of the attribute this replaces. A bare checkout without
            // pdo_sqlite skipped these suites before and still does; nothing about issue #110's
            // fail-not-skip rule was ever a claim about the *unconfigured* run.
            self::markTestSkipped('pdo_sqlite is not loaded and no ' . TestDatabase::DSN . ' is configured.');
        }

        return TestDatabase::pdo();
    }

    /**
     * The shared PDO wrapped in the library's connection, with its defaults pinned.
     */
    protected function engineConnection(): DatabaseConnection
    {
        return new DatabaseConnection($this->enginePdo());
    }

    /**
     * @param array<string, 'key'|'text'|'int'> $columns
     */
    protected function createFixtureTable(PDO $pdo, string $table, array $columns): void
    {
        TestDatabase::createTable($pdo, $table, $columns);
    }

    /**
     * Run an operation that a **strict engine is allowed to refuse**, and let the assertions that
     * follow it stand either way.
     *
     * The injection suites feed a corpus of hostile *values* through every entry point and then
     * assert, at the PDO boundary, that each one travelled as a bound parameter. Three corpus
     * members are ones a real server rejects after that binding has already happened:
     *
     * - `admin\0' OR 1=1` — a NUL byte, which PostgreSQL refuses to store in a text column;
     * - `\xbf\x27 OR 1=1 --` — the GBK quote, which is not valid UTF-8 and which both MySQL's
     *   `utf8mb4` and PostgreSQL's `UTF8` refuse;
     * - any payload reaching an **integer** column, which PostgreSQL rejects outright rather than
     *   coercing the way SQLite and MySQL do.
     *
     * In all three the driver was handed a placeholder-only statement and a bound parameter — the
     * property under test — and then said no. Asserting the refusal away would be asserting the
     * wrong thing; skipping those cases would quietly shrink the corpus on exactly the engines the
     * leg was added for.
     *
     * **What keeps this from being a swallow:** on the default engine it rethrows. SQLite accepts
     * every one of these today, so any `DatabaseException` there is a regression and stays red.
     * The caller's boundary assertion runs afterwards regardless, and it is not vacuous — it fails
     * if the log is empty or if the payload is not among the bound values.
     */
    protected function attempt(callable $operation): bool
    {
        try {
            $operation();

            return true;
        } catch (DatabaseException $e) {
            if (TestDatabase::isDefaultEngine()) {
                throw $e;
            }

            return false;
        }
    }
}
