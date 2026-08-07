<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Persistence;

use D4np\Utils\Database\DatabaseConnection;
use D4np\Utils\Database\SqlStatement;
use D4np\Utils\Persistence\RowNormalizer;
use D4np\Utils\Support\DatabaseException;
use D4np\Utils\Support\HydrationException;
use D4np\Utils\Tests\Persistence\Fixture\UserRepository;
use D4np\Utils\Tests\Persistence\Fixture\UserRow;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * `Repository` — spec r3 FR-34 (RFC-0002), ADR-0043.
 *
 * Against real SQLite rather than a doubled connection, for the reason every `Database` suite
 * here gives: the assertions are about what actually came back and what actually survived a
 * rollback, and a mock would return whatever it was told to.
 *
 * The group of assertions worth reading is the last one. FR-34's *"every failure throws — no
 * silent `[]`/`false`/`-1`"* is satisfied by the **absence** of `try`/`catch` in the class, and
 * an absence is exactly what a suite loses without anyone noticing: adding one catch back would
 * keep every happy-path test green. So each failure path is asserted to propagate, and the
 * class is additionally asserted to contain no catch at all.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class RepositoryTest extends TestCase
{
    private DatabaseConnection $connection;

    protected function setUp(): void
    {
        $this->connection = new DatabaseConnection(new PDO('sqlite::memory:'));
        $this->connection->execute(SqlStatement::literal(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, age INTEGER, secret TEXT)',
        ));
    }

    private function repository(?RowNormalizer $normalizer = null): UserRepository
    {
        return new UserRepository($this->connection, $normalizer);
    }

    private function seed(int $id, string $name, ?int $age, string $secret = 'x'): void
    {
        $this->connection->execute(SqlStatement::literal(
            'INSERT INTO users (id, name, age, secret) VALUES (?, ?, ?, ?)',
            [$id, $name, $age, $secret],
        ));
    }

    // ---- fetching ------------------------------------------------------------------------------

    public function testFetchAllHydratesEveryRow(): void
    {
        $this->seed(1, 'Ada', 36);
        $this->seed(2, 'Grace', 45);

        $rows = $this->repository()->all();

        self::assertCount(2, $rows);
        self::assertContainsOnlyInstancesOf(UserRow::class, $rows);
        self::assertSame([1, 2], \array_map(static fn (UserRow $r): int => $r->id, $rows));
        self::assertSame('Ada', $rows[0]->name);
        self::assertSame(45, $rows[1]->age);
    }

    public function testFetchAllReturnsAnEmptyListRatherThanNull(): void
    {
        // No rows is not a failure, and the empty list here is not a swallowed error — the
        // distinction the estate's `return []` could not express.
        self::assertSame([], $this->repository()->all());
    }

    public function testFetchOneReturnsTheHydratedRow(): void
    {
        $this->seed(7, 'Alan', 41);

        $row = $this->repository()->byId(7);

        self::assertInstanceOf(UserRow::class, $row);
        self::assertSame('Alan', $row->name);
    }

    public function testFetchOneReturnsNullWhenThereIsNoRow(): void
    {
        self::assertNull($this->repository()->byId(404));
    }

    public function testANullableColumnHydratesToNull(): void
    {
        $this->seed(3, 'Unknown', null);

        self::assertNull($this->repository()->byId(3)?->age);
    }

    // ---- execute -------------------------------------------------------------------------------

    public function testExecuteReportsTheAffectedRowCount(): void
    {
        $this->seed(1, 'Ada', 36);
        $this->seed(2, 'Grace', 45);

        self::assertSame(1, $this->repository()->rename(1, 'Ada L.'));
    }

    /**
     * Zero affected rows is a count, not a failure — only the caller knows whether an update
     * that matched nothing is expected. Collapsing it to a boolean is what left the estate's
     * callers unable to tell "updated nothing" from "did not run".
     */
    public function testExecuteReturnsZeroRatherThanFailingWhenNothingMatched(): void
    {
        self::assertSame(0, $this->repository()->rename(404, 'Nobody'));
    }

    // ---- normalization -------------------------------------------------------------------------

    public function testWithoutANormalizerRowsArriveExactlyAsTheDriverReturnedThem(): void
    {
        $this->seed(1, '  Ada  ', 36);

        self::assertSame('  Ada  ', $this->repository()->byId(1)?->name);
    }

    public function testWithANormalizerTheConfiguredPolicyIsApplied(): void
    {
        $this->seed(1, '  Ada  ', 36);

        self::assertSame('Ada', $this->repository(new RowNormalizer())->byId(1)?->name);
    }

    // ---- transactions --------------------------------------------------------------------------

    public function testWithTransactionCommitsOnReturnAndPassesTheRepository(): void
    {
        $count = $this->repository()->inTransaction(static function (UserRepository $repo): int {
            $repo->insert(1, 'Ada', 36);

            return $repo->insert(2, 'Grace', 45);
        });

        self::assertSame(1, $count);
        self::assertCount(2, $this->repository()->all());
    }

    public function testWithTransactionRollsBackAndRethrowsOnFailure(): void
    {
        $thrown = new RuntimeException('boom');
        $caught = null;

        try {
            $this->repository()->inTransaction(static function (UserRepository $repo) use ($thrown): void {
                $repo->insert(1, 'Ada', 36);

                throw $thrown;
            });
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        // The very object, not a wrapper: ADR-0016's contract, inherited rather than reimplemented.
        self::assertSame($thrown, $caught);
        self::assertSame([], $this->repository()->all(), 'the insert must have been rolled back');
    }

    // ---- FR-34: every failure throws -----------------------------------------------------------

    public function testAFailingStatementPropagatesAsADatabaseException(): void
    {
        $this->expectException(DatabaseException::class);

        $this->repository()->fromABrokenStatement();
    }

    /**
     * Strict hydration, kept rather than worked around: a projected column the DTO does not
     * declare is refused. This is what makes `SELECT *` into a typed DTO fail by design.
     */
    public function testAnUndeclaredColumnPropagatesAsAHydrationException(): void
    {
        $this->seed(1, 'Ada', 36);

        $this->expectException(HydrationException::class);

        $this->repository()->allWithAnUndeclaredColumn();
    }

    /**
     * A normalization failure is a `DatabaseException` naming the column (ADR-0042), and it must
     * reach the caller rather than being absorbed into an empty result.
     */
    #[RequiresPhpExtension('iconv')]
    public function testANormalizationFailurePropagatesAndNamesItsColumn(): void
    {
        $this->seed(1, '10 €', 36);

        try {
            // '€' has no representation in ISO-8859-1, and strict mode refuses it.
            $this->repository(new RowNormalizer(fromEncoding: 'UTF-8', toEncoding: 'ISO-8859-1'))->all();

            self::fail('expected a DatabaseException');
        } catch (DatabaseException $e) {
            self::assertStringContainsString('name', $e->getMessage(), 'the failing column is named');
        }
    }

    /**
     * The mechanism behind the three assertions above, asserted directly: there is no `catch` in
     * this class. Every one of them would still pass if a `catch` were added that rethrew — and
     * would silently stop protecting anything the day one was added that did not.
     */
    public function testTheClassContainsNoCatchAtAll(): void
    {
        $file = (new ReflectionClass(\D4np\Utils\Persistence\Repository::class))->getFileName();
        self::assertIsString($file);

        $source = \file_get_contents($file);
        self::assertIsString($source);

        // Strip the docblocks, which discuss catching at length, before looking for one.
        $code = \preg_replace('#/\*.*?\*/#s', '', $source) ?? '';

        self::assertDoesNotMatchRegularExpression(
            '/\bcatch\s*\(/',
            $code,
            'FR-34 is satisfied by the ABSENCE of a catch here: DatabaseConnection, the '
            . 'hydrator and RowNormalizer each raise a typed failure, and this class must let '
            . 'every one of them through. The estate had 74 catches that returned sentinels.',
        );
    }

    /**
     * `hydrate()` is the documented seam for a subclass that wants lenient hydration instead.
     * Pinned so the escape route from strictness stays available without a flag.
     */
    public function testHydrationIsOverridableBySubclasses(): void
    {
        $hydrate = new ReflectionMethod(\D4np\Utils\Persistence\Repository::class, 'hydrate');

        self::assertTrue($hydrate->isProtected(), 'hydrate() must stay protected: it is the lenient-hydration seam');
        self::assertFalse($hydrate->isFinal());
    }
}
