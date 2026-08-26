<?php

declare(strict_types=1);

namespace D4np\Utils\Database;

use D4np\Utils\Support\DatabaseException;
use PDO;

/**
 * The persistence boundary, as a seam a consumer can substitute (issue #113, ADR-0072).
 *
 * Every other I/O boundary in this library already had one — {@see \D4np\Utils\Http\Transport}
 * for the network, {@see \D4np\Utils\Http\SessionApi} for PHP's session functions,
 * {@see \D4np\Utils\Mail\MailApi} for `mail()`, {@see \D4np\Utils\Security\RateLimitStore} for
 * shared limiter state. The database did not, so a consumer writing a unit test for their own
 * `Repository` subclass had two options and both are bad: run a real SQLite database inside a
 * unit test, or reach into the object with reflection.
 *
 * **This is the deferred half of ADR-0006's own reasoning.** That ADR declined to give the
 * reflection cache an interface, and its argument was not "interfaces are bad" — it was that
 * *"extracting an interface from a concrete collaborator is a non-breaking, additive refactor …
 * there is no one-way door, so there is nothing to buy insurance against, and no consumer exists
 * yet to tell us what the interface should say."* A consumer has now said what it should say.
 * This is that answer, arriving through the additive door ADR-0006 predicted.
 *
 * ## What a fake has to provide
 *
 * The whole current public surface of {@see DatabaseConnection}, `pdo()` included — see ADR-0072
 * for why the escape hatch is part of the contract rather than excluded from it. In practice a
 * read-oriented fake never reaches `pdo()`: only {@see Transaction} calls it, and
 * `Transaction::__construct()` merely stores the connection, so constructing a
 * {@see \D4np\Utils\Persistence\Repository} with a fake touches nothing PDO-shaped. A fake that
 * serves reads may therefore implement `pdo()` by throwing, and `createMock(Connection::class)`
 * needs no help at all.
 *
 * ## What implementing this does **not** buy
 *
 * The guarantees {@see DatabaseConnection} makes — real prepares, `ERRMODE_EXCEPTION`, UTF-8mb4
 * on MySQL (ADR-0014) — are properties of *that class pinning them on a PDO*, not of this
 * interface. An implementation is free to honour none of them, which is exactly why the
 * injection suites (spec §7 T-02 and T-13) run against a real engine and always will: a proof
 * about binding cannot be written against a double that returns whatever it was told to.
 */
interface Connection
{
    /**
     * The underlying PDO.
     *
     * An escape hatch, and deliberately one: this library does not intend to wrap all of PDO, and
     * a consumer needing `lastInsertId()` or a driver-specific attribute should reach the real
     * object rather than wait for a pass-through method.
     *
     * It is on this interface because {@see Transaction} is built on it — `beginTransaction()`,
     * `commit()`, and the `SAVEPOINT` statements ADR-0016 nests with — and a `Connection` that
     * a `Repository` cannot open a transaction on would be a seam that does not fit the class it
     * was extracted from. An implementation with no real PDO behind it may throw here; nothing on
     * the read or write path calls it.
     */
    public function pdo(): PDO;

    /**
     * The PDO driver name — `mysql`, `sqlite`, `pgsql`, …
     *
     * Load-bearing rather than informational: {@see Identifier::forDriver()} switches on it to
     * decide how an identifier is quoted, so an implementation that returns a name it does not
     * actually speak will produce SQL for the wrong dialect.
     */
    public function driver(): string;

    /**
     * Every row of a query, as associative arrays.
     *
     * @return list<array<string, mixed>>
     *
     * @throws DatabaseException
     */
    public function select(SqlStatement $statement): array;

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
    public function selectOne(SqlStatement $statement): ?array;

    /**
     * Run a statement that changes rows, and return how many it affected.
     *
     * Zero is a count, not a failure — the distinction {@see \D4np\Utils\Persistence\Repository}
     * depends on, and the one the surveyed estate's boolean returns could not express.
     *
     * @throws DatabaseException
     */
    public function execute(SqlStatement $statement): int;
}
