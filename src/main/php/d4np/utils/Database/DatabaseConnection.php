<?php

declare(strict_types=1);

namespace D4np\Utils\Database;

use D4np\Utils\Support\DatabaseException;
use PDO;
use PDOException;
use PDOStatement;

/**
 * A PDO connection with the safe defaults spec FR-06 pins (ADR-0014).
 *
 * RFC-0001 is explicit that the `Database` group wraps **consumer-owned** PDO connections — the
 * library owns no persistent state and opens nothing. So this takes a `PDO` the caller already
 * built and *pins* four settings on it:
 *
 * | setting | value | why |
 * |---|---|---|
 * | `ATTR_ERRMODE` | `ERRMODE_EXCEPTION` | a failed statement raises rather than returning `false` |
 * | `ATTR_EMULATE_PREPARES` | `false` | the driver prepares; no client-side interpolation |
 * | `ATTR_DEFAULT_FETCH_MODE` | `FETCH_ASSOC` | rows are maps, not duplicated numeric+named pairs |
 * | `SET NAMES utf8mb4` | MySQL only | the full Unicode range, not MySQL's 3-byte `utf8` |
 *
 * **The order these are applied in is load-bearing, not cosmetic.**
 *
 * `ERRMODE_EXCEPTION` goes first so that everything after it fails loudly rather than silently
 * returning `false`. Emulation is turned off **before** any SQL is sent, and that is what makes
 * the `SET NAMES utf8mb4` step safe: issuing `SET NAMES` is the long-standing wrong answer to
 * charset configuration *when the client is also doing its own escaping*, because the client
 * library's idea of the connection charset does not change with it, and a multibyte charset can
 * then be used to smuggle a quote past client-side escaping. With emulation off there is no
 * client-side escaping left to fool — values are sent to the server as bound parameters, out of
 * band from the SQL text. The two pinned defaults are not independent hardening measures; the
 * second is what licenses the fourth.
 *
 * **A driver that does not honour a pinned default is an error, not a shrug.** `PDO::setAttribute()`
 * returns `false` rather than throwing when a driver rejects an attribute (verified: SQLite
 * returns `false` for `ATTR_EMULATE_PREPARES`), so ignoring the return value would let a
 * security-relevant default fail silently — exactly the failure FR-06 exists to prevent. What
 * this class does instead is distinguish the two cases: a driver with no notion of emulation at
 * all (SQLite prepares natively; reading the attribute back throws
 * `SQLSTATE[IM001] Driver does not support this function`) is fine, while a driver that *has*
 * the concept and is still emulating after being told not to is a {@see DatabaseException}.
 *
 * Every query method binds its values as parameters. There is no method on this class that
 * accepts an interpolated value, because the one that did would be the one that got used.
 * Identifiers are a different problem — they cannot be bound — and belong to `QueryBuilder`
 * (roadmap 4.2), which allowlists them.
 *
 * **Every query method takes a {@see SqlStatement}, not a bare `(string, array)` pair**
 * (roadmap 10.1, ADR-0039). SQL text and its parameters are one value here, not two
 * variables a caller could assemble incorrectly — the estate's 199 interpolation sites were
 * exactly that assembly going wrong, 199 times, because nothing forced the two to travel
 * together.
 */
final class DatabaseConnection
{
    /**
     * @throws DatabaseException when a pinned default cannot be applied
     */
    public function __construct(
        private readonly PDO $pdo,
    ) {
        $this->pin();
    }

    /**
     * The underlying PDO.
     *
     * An escape hatch, and deliberately one: this library does not intend to wrap all of PDO, and
     * a consumer needing `lastInsertId()`, a driver-specific attribute, or `Transaction`'s
     * savepoint handling (roadmap 4.3) should reach the real object rather than wait for a
     * pass-through method. The pinned defaults are already applied to it.
     */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * The PDO driver name — `mysql`, `sqlite`, `pgsql`, …
     */
    public function driver(): string
    {
        /** @var string */
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Every row of a query, as associative arrays.
     *
     * @return list<array<string, mixed>>
     *
     * @throws DatabaseException
     */
    public function select(SqlStatement $statement): array
    {
        /** @var list<array<string, mixed>> */
        return $this->run($statement)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The first row of a query, or `null` when it returns none.
     *
     * `null` rather than PDO's `false`, so the empty case is expressible in the type system and a
     * caller cannot confuse "no row" with "a row whose first column was falsy".
     *
     * @return array<string, mixed>|null
     *
     * @throws DatabaseException
     */
    public function selectOne(SqlStatement $statement): ?array
    {
        $row = $this->run($statement)->fetch(PDO::FETCH_ASSOC);

        /** @var array<string, mixed>|false $row */
        return $row === false ? null : $row;
    }

    /**
     * Run a statement that changes rows, and return how many it affected.
     *
     * @throws DatabaseException
     */
    public function execute(SqlStatement $statement): int
    {
        return $this->run($statement)->rowCount();
    }

    /**
     * Prepare and execute, converting PDO's failure into this library's own (ADR-0004).
     *
     * @throws DatabaseException
     */
    private function run(SqlStatement $statement): PDOStatement
    {
        try {
            $prepared = $this->pdo->prepare($statement->sql);
            $prepared->execute($statement->parameters === [] ? null : $statement->parameters);

            return $prepared;
        } catch (PDOException $e) {
            // The SQL is deliberately not in the message. It is frequently logged, and a failing
            // statement's text is the most likely place for data a consumer would rather not see
            // in a log line. The driver's own message and SQLSTATE are preserved via getPrevious().
            throw new DatabaseException(
                \sprintf('Database statement failed: %s', $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * Apply the pinned defaults, in the order the class docblock explains.
     *
     * @throws DatabaseException
     */
    private function pin(): void
    {
        // First, so that everything below fails loudly rather than returning false.
        $this->pinAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION, 'ATTR_ERRMODE');

        $this->requireRealPrepares();

        $this->pinAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC, 'ATTR_DEFAULT_FETCH_MODE');

        // Only MySQL has the 3-byte-`utf8` problem this fixes, and only MySQL understands the
        // statement. Safe here specifically because real prepares are already on — see the class
        // docblock.
        if ($this->driver() === 'mysql') {
            try {
                $this->pdo->exec('SET NAMES utf8mb4');
            } catch (PDOException $e) {
                throw new DatabaseException(
                    'Could not set the connection charset to utf8mb4: ' . $e->getMessage(),
                    0,
                    $e,
                );
            }
        }
    }

    /**
     * @throws DatabaseException
     */
    private function pinAttribute(int $attribute, mixed $value, string $label): void
    {
        if (!$this->pdo->setAttribute($attribute, $value)) {
            throw new DatabaseException(\sprintf(
                'The PDO driver "%s" refused the pinned default %s. This connection cannot offer '
                . 'the guarantees DatabaseConnection exists to make, so it is refused rather than '
                . 'used with a weaker setting.',
                $this->driver(),
                $label,
            ));
        }
    }

    /**
     * Turn off emulated prepares, tolerating drivers that have no such concept.
     *
     * `setAttribute()` returning `false` is ambiguous on its own: it means both "this driver does
     * not know this attribute" (SQLite, which always prepares natively — nothing is wrong) and
     * "this driver knows it and declined" (which would leave client-side interpolation on, and is
     * a security failure). Reading the attribute back separates them.
     *
     * @throws DatabaseException
     */
    private function requireRealPrepares(): void
    {
        if ($this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false)) {
            return;
        }

        try {
            $stillEmulating = (bool) $this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        } catch (PDOException) {
            // The driver does not expose the attribute at all, which means it has no emulation
            // mode to turn off. SQLite is the case in point.
            return;
        }

        if ($stillEmulating) {
            throw new DatabaseException(\sprintf(
                'The PDO driver "%s" is still emulating prepared statements after being told not '
                . 'to. Emulated prepares interpolate values into the SQL text client-side, which '
                . 'is the behaviour FR-06 pins this default to prevent, so this connection is '
                . 'refused rather than used unsafely.',
                $this->driver(),
            ));
        }
    }
}
