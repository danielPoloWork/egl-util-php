<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Database;

use D4np\Utils\Database\DatabaseConnection;
use D4np\Utils\Database\Transaction;
use D4np\Utils\Support\DatabaseException;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use TypeError;

/**
 * Spec §7's T-04 suite: *"exception → rollback → rethrow; savepoint nesting"*.
 *
 * Run against real SQLite rather than a doubled PDO, because every assertion here is about what
 * the driver actually did with the data — whether a row survived a rollback is not something a
 * mock can be asked.
 */
#[Group('T-04')]
#[RequiresPhpExtension('pdo_sqlite')]
final class TransactionTest extends TestCase
{
    private DatabaseConnection $connection;

    protected function setUp(): void
    {
        $this->connection = new DatabaseConnection(new PDO('sqlite::memory:'));
        $this->connection->execute('CREATE TABLE t (v TEXT)');
    }

    private function transaction(): Transaction
    {
        return new Transaction($this->connection);
    }

    /**
     * @return list<string>
     */
    private function rows(): array
    {
        return array_map(
            static function (array $row): string {
                self::assertIsString($row['v']);

                return $row['v'];
            },
            $this->connection->select('SELECT v FROM t ORDER BY rowid'),
        );
    }

    private function insert(string $value): void
    {
        $this->connection->execute('INSERT INTO t (v) VALUES (?)', [$value]);
    }

    public function testWorkIsCommittedWhenTheClosureReturns(): void
    {
        $this->transaction()->run(function (): void {
            $this->insert('kept');
        });

        self::assertSame(['kept'], $this->rows());
    }

    public function testTheClosureReturnValueIsReturned(): void
    {
        self::assertSame(42, $this->transaction()->run(static fn (): int => 42));
    }

    public function testTheClosureReceivesTheConnection(): void
    {
        $received = $this->transaction()->run(static fn (DatabaseConnection $db): DatabaseConnection => $db);

        self::assertSame($this->connection, $received);
    }

    public function testAnExceptionRollsBackEveryStatementInTheScope(): void
    {
        try {
            $this->transaction()->run(function (): void {
                $this->insert('a');
                $this->insert('b');

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame([], $this->rows());
    }

    public function testTheOriginalExceptionIsRethrownUnchanged(): void
    {
        $thrown = new RuntimeException('the original reason');

        // Captured rather than asserted inside the catch: an assertion that only runs when the
        // exception arrives would pass silently if `run()` ever swallowed it, which is precisely
        // the regression this test exists to catch.
        $caught = null;

        try {
            $this->transaction()->run(static function () use ($thrown): void {
                throw $thrown;
            });
        } catch (Throwable $e) {
            $caught = $e;
        }

        // Identity, not just class: the caller must get the very object the closure threw, not a
        // wrapper that has lost its type or its context.
        self::assertSame($thrown, $caught);
    }

    /**
     * A `TypeError` leaves exactly the same half-written state as a `RuntimeException`; committing
     * it because it descends from `Error` rather than `Exception` would be indefensible.
     */
    public function testAnErrorRollsBackJustLikeAnException(): void
    {
        try {
            $this->transaction()->run(function (): void {
                $this->insert('a');

                throw new TypeError('not an exception');
            });
        } catch (TypeError) {
            // expected
        }

        self::assertSame([], $this->rows());
    }

    public function testTheTransactionIsClosedAfterASuccessfulRun(): void
    {
        $this->transaction()->run(static function (): void {
        });

        self::assertFalse($this->connection->pdo()->inTransaction());
    }

    public function testTheTransactionIsClosedAfterAFailedRun(): void
    {
        try {
            $this->transaction()->run(static function (): void {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // expected
        }

        // The failure this class exists to prevent: an open transaction left behind, which
        // surfaces later and elsewhere as a lock nobody can explain.
        self::assertFalse($this->connection->pdo()->inTransaction());
    }

    // ---- nesting -----------------------------------------------------------------------------

    public function testANestedRunCommitsWithTheOuterOne(): void
    {
        $this->transaction()->run(function (): void {
            $this->insert('outer');
            $this->transaction()->run(function (): void {
                $this->insert('inner');
            });
        });

        self::assertSame(['outer', 'inner'], $this->rows());
    }

    /**
     * The heart of FR-08's savepoint clause: an inner failure that the outer scope *handles* must
     * undo only the inner work. A plain `rollBack()` would have discarded 'outer' as well.
     */
    public function testAFailedNestedRunRollsBackOnlyItsOwnWork(): void
    {
        $this->transaction()->run(function (): void {
            $this->insert('outer');

            try {
                $this->transaction()->run(function (): void {
                    $this->insert('inner');

                    throw new RuntimeException('inner failed');
                });
            } catch (RuntimeException) {
                // handled by the outer scope, which carries on
            }

            $this->insert('after');
        });

        self::assertSame(['outer', 'after'], $this->rows());
    }

    public function testAnUnhandledNestedFailureRollsBackEverything(): void
    {
        try {
            $this->transaction()->run(function (): void {
                $this->insert('outer');
                $this->transaction()->run(function (): void {
                    $this->insert('inner');

                    throw new RuntimeException('inner failed');
                });
            });
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame([], $this->rows());
    }

    public function testNestingSurvivesThreeLevels(): void
    {
        $this->transaction()->run(function (): void {
            $this->insert('L1');
            $this->transaction()->run(function (): void {
                $this->insert('L2');
                $this->transaction()->run(function (): void {
                    $this->insert('L3');
                });
            });
        });

        self::assertSame(['L1', 'L2', 'L3'], $this->rows());
    }

    public function testRepeatedFailedNestedRunsDoNotLeakSavepoints(): void
    {
        $this->transaction()->run(function (): void {
            $this->insert('outer');

            for ($i = 0; $i < 5; $i++) {
                try {
                    $this->transaction()->run(function () use ($i): void {
                        $this->insert('inner' . $i);

                        throw new RuntimeException('nope');
                    });
                } catch (RuntimeException) {
                    // each one is rolled back and released
                }
            }
        });

        self::assertSame(['outer'], $this->rows());
    }

    public function testANestedRunUsesASavepointRatherThanASecondBeginTransaction(): void
    {
        // PDO throws "There is already an active transaction" on a nested beginTransaction(), so
        // if nesting were implemented that way this would fail rather than return.
        $depth = $this->transaction()->run(fn (): bool => $this->transaction()->run(
            fn (): bool => $this->connection->pdo()->inTransaction(),
        ));

        self::assertTrue($depth);
    }

    public function testTheOuterTransactionRemainsOpenWhileANestedScopeIsRolledBack(): void
    {
        $stillOpen = $this->transaction()->run(function (): bool {
            try {
                $this->transaction()->run(static function (): void {
                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException) {
                // expected
            }

            return $this->connection->pdo()->inTransaction();
        });

        self::assertTrue($stillOpen);
    }

    // ---- failure handling --------------------------------------------------------------------

    /**
     * If the rollback itself fails, the caller must still receive the closure's exception — the
     * actionable one — rather than a message about cleanup.
     */
    public function testAFailingRollbackDoesNotReplaceTheOriginalException(): void
    {
        $pdo = new class ('sqlite::memory:') extends PDO {
            public function rollBack(): bool
            {
                throw new \PDOException('the connection went away during rollback');
            }
        };
        $connection = new DatabaseConnection($pdo);
        $connection->execute('CREATE TABLE t (v TEXT)');

        $original = new LogicException('the reason the caller needs');
        $caught = null;

        try {
            (new Transaction($connection))->run(static function () use ($original): void {
                throw $original;
            });
        } catch (Throwable $e) {
            $caught = $e;
        }

        self::assertSame($original, $caught);
    }

    public function testAFailureToBeginIsReportedAsADatabaseException(): void
    {
        $pdo = new class ('sqlite::memory:') extends PDO {
            public function beginTransaction(): bool
            {
                throw new \PDOException('cannot begin');
            }
        };

        $this->expectException(DatabaseException::class);

        (new Transaction(new DatabaseConnection($pdo)))->run(static function (): void {
        });
    }
}
