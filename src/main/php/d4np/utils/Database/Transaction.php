<?php

declare(strict_types=1);

namespace D4np\Utils\Database;

use D4np\Utils\Support\DatabaseException;
use PDOException;
use Throwable;

/**
 * Closure-scoped transactions: commit on return, roll back and rethrow on anything else
 * (spec FR-08, ADR-0016).
 *
 * The shape exists because the manual one is easy to get wrong in a way that is invisible until
 * it matters. `beginTransaction()` … `commit()` written by hand leaks an open transaction down
 * every path that returns or throws in between, and the leak surfaces later, somewhere else, as a
 * lock nobody can explain. A closure has exactly one exit, so there is one place to put the
 * `finally`.
 *
 * ```php
 * $total = (new Transaction($connection))->run(function (Connection $db): int {
 *     $db->execute(SqlStatement::literal('UPDATE accounts SET balance = balance - ? WHERE id = ?', [100, 1]));
 *     $db->execute(SqlStatement::literal('UPDATE accounts SET balance = balance + ? WHERE id = ?', [100, 2]));
 *
 *     return 2;
 * });
 * ```
 *
 * **Nesting uses savepoints because PDO gives no choice.** A second `beginTransaction()` on a
 * connection that already has one open does not nest and does not no-op — it throws
 * `PDOException: There is already an active transaction` (verified). So a nested `run()` opens a
 * `SAVEPOINT` instead, and "rollback" for that scope means `ROLLBACK TO SAVEPOINT`, which undoes
 * the inner work while leaving the outer transaction intact and still open.
 *
 * **What counts as a failure is `Throwable`, not `Exception`.** A `TypeError` or an assertion
 * inside the closure leaves the same half-written state as a `RuntimeException`, and committing it
 * because it descends from `Error` rather than `Exception` would be indefensible.
 *
 * **The original failure is what propagates.** If the rollback *itself* also fails, the exception
 * that comes out of `run()` is still the one from the closure — see {@see self::rollBackQuietly()}
 * for why replacing it would hide the thing the caller actually needs to see.
 *
 * **A caveat this class cannot fix:** on MySQL, DDL (`CREATE TABLE`, `ALTER`, `DROP`, …) causes an
 * *implicit commit*, ending the transaction underneath you. Work issued after that point is no
 * longer covered, and a later rollback will not undo what the DDL already committed. That is a
 * MySQL behaviour, not a PDO or library one, and no wrapper can intercept it — named here because
 * the failure is silent and the natural assumption is that the closure is atomic throughout.
 *
 * **Generic over the connection it was handed** (issue #113, ADR-0072), which is a docblock-only
 * detail with a real consequence. The constructor's native type widened from `DatabaseConnection`
 * to {@see Connection} so a repository built on a fake can still hold one. Left there, `run()`
 * would advertise `callable(Connection): T`, and every existing consumer whose closure declares
 * `function (DatabaseConnection $db)` would start failing *their* static analysis -- contravariance,
 * on a change whose whole premise is that nothing broke. The template carries the caller's own type
 * through instead: `new Transaction($databaseConnection)` still hands the closure a
 * `DatabaseConnection`.
 *
 * @template TConnection of Connection
 */
final class Transaction
{
    /**
     * Monotonic, process-wide, and never derived from caller input.
     *
     * A savepoint name is an identifier spliced into SQL, so it gets the same treatment as any
     * other identifier in this group: it is generated here rather than accepted from anywhere.
     * `QueryBuilder`'s allowlist exists for names that come from outside; the honest way to make
     * this one safe is for there to be no path by which a caller can influence it at all.
     *
     * Monotonic rather than depth-keyed so that two savepoints can never share a name even if
     * nesting unwinds in an unexpected order.
     */
    private static int $savepointSequence = 0;

    /**
     * @param TConnection $connection
     */
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Run `$work` inside a transaction, or inside a savepoint when one is already open.
     *
     * @template T
     *
     * @param callable(TConnection): T $work
     *
     * @return T whatever the closure returned
     *
     * @throws Throwable whatever the closure threw, after the work has been rolled back
     * @throws DatabaseException if the transaction could not be started, committed or released
     */
    public function run(callable $work): mixed
    {
        return $this->connection->pdo()->inTransaction()
            ? $this->runInSavepoint($work)
            : $this->runInTransaction($work);
    }

    /**
     * @template T
     *
     * @param callable(TConnection): T $work
     *
     * @return T
     *
     * @throws Throwable
     */
    private function runInTransaction(callable $work): mixed
    {
        $pdo = $this->connection->pdo();

        try {
            $pdo->beginTransaction();
        } catch (PDOException $e) {
            throw new DatabaseException('Could not begin a transaction: ' . $e->getMessage(), 0, $e);
        }

        try {
            $result = $work($this->connection);
        } catch (Throwable $e) {
            $this->rollBackQuietly(static fn (): bool => $pdo->rollBack());

            throw $e;
        }

        try {
            $pdo->commit();
        } catch (PDOException $e) {
            throw new DatabaseException('Could not commit the transaction: ' . $e->getMessage(), 0, $e);
        }

        return $result;
    }

    /**
     * @template T
     *
     * @param callable(TConnection): T $work
     *
     * @return T
     *
     * @throws Throwable
     */
    private function runInSavepoint(callable $work): mixed
    {
        $name = 'egl_sp_' . ++self::$savepointSequence;

        try {
            $this->connection->pdo()->exec('SAVEPOINT ' . $name);
        } catch (PDOException $e) {
            throw new DatabaseException(
                \sprintf('Could not open savepoint %s for a nested transaction: %s', $name, $e->getMessage()),
                0,
                $e,
            );
        }

        try {
            $result = $work($this->connection);
        } catch (Throwable $e) {
            $this->rollBackQuietly(function () use ($name): void {
                $pdo = $this->connection->pdo();
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $name);
                // ROLLBACK TO does not discard the savepoint — verified: it is still defined
                // afterwards and can be released. Releasing keeps the enclosing transaction's
                // savepoint stack from growing once per failed nested call.
                $pdo->exec('RELEASE SAVEPOINT ' . $name);
            });

            throw $e;
        }

        try {
            $this->connection->pdo()->exec('RELEASE SAVEPOINT ' . $name);
        } catch (PDOException $e) {
            throw new DatabaseException(
                \sprintf('Could not release savepoint %s: %s', $name, $e->getMessage()),
                0,
                $e,
            );
        }

        return $result;
    }

    /**
     * Undo the scope's work, and never let doing so replace the reason we are undoing it.
     *
     * A rollback can fail — a dropped connection is the usual way — and if that failure were
     * allowed to propagate it would *replace* the closure's exception with a message about
     * cleanup. The caller would then be handed a `PDOException` about a lost connection in place
     * of the `TypeError` that actually caused the problem, which is the wrong end of the causal
     * chain and the one they cannot act on.
     *
     * So the rollback's own failure is swallowed here and the original is rethrown by the caller.
     * The cost is real and worth naming: a failed rollback leaves the connection in a state this
     * class did not intend and does not report. PHP has no equivalent of Java's suppressed
     * exceptions, so the alternatives are to lose the original cause or to lose the cleanup
     * failure; losing the cleanup failure keeps the actionable one.
     *
     * @param callable(): mixed $rollBack
     */
    private function rollBackQuietly(callable $rollBack): void
    {
        try {
            $rollBack();
        } catch (Throwable) {
            // Deliberately empty. See the docblock: the closure's exception is the one that
            // matters, and this method exists so that it survives.
        }
    }
}
