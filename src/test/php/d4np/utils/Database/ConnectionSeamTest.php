<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Database;

use D4np\Utils\Database\Connection;
use D4np\Utils\Database\DatabaseConnection;
use D4np\Utils\Database\MutationBuilder;
use D4np\Utils\Database\Operator;
use D4np\Utils\Database\QueryBuilder;
use D4np\Utils\Database\SqlStatement;
use D4np\Utils\Database\Transaction;
use D4np\Utils\Persistence\TableGateway;
use D4np\Utils\Support\DatabaseException;
use D4np\Utils\Tests\Database\Fixture\PdolessConnection;
use D4np\Utils\Tests\Engine\RunsAgainstADatabaseEngine;
use D4np\Utils\Tests\Persistence\Fixture\Person;
use D4np\Utils\Tests\Persistence\Fixture\UserRepository;
use D4np\Utils\Tests\Persistence\Fixture\UserRow;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The seam issue #113 asked for, asserted by **using** it (ADR-0072).
 *
 * The claim is not "an interface exists" — that is visible in the file listing. It is that a
 * consumer can drive `Repository` and `TableGateway` with **no database behind them at all**,
 * which was impossible while both constructors named the concrete class. So every test here goes
 * through {@see PdolessConnection}, whose `pdo()` throws: if any path under test reaches for the
 * escape hatch, this suite fails at the line that did it.
 *
 * Two of these tests are the ones worth reading. {@see self::testTheBuildersRenderTheSameSqlThroughTheInterface()}
 * pins the additive half — widening a parameter type must not change a single character of
 * generated SQL — by rendering the same query through the fake and through a real connection and
 * comparing. And {@see self::testOnlyTheTransactionPathReachesForThePdo()} pins where the seam's
 * limit actually is, rather than leaving ADR-0072's claim about `pdo()` as prose.
 */
#[Group('database-engine')]
final class ConnectionSeamTest extends TestCase
{
    use RunsAgainstADatabaseEngine;

    public function testTheConcreteConnectionImplementsTheSeam(): void
    {
        // The whole of the additive promise in one line: nothing about DatabaseConnection changed
        // except that it now satisfies a type consumers can also satisfy.
        self::assertInstanceOf(Connection::class, new DatabaseConnection($this->enginePdo()));
    }

    /**
     * **Every call shape written against v1.0.0 still works, named as a test rather than left to emerge.**
     *
     * `roave/backward-compatibility-check` reports each widened parameter as *"changed … to a
     * non-contravariant `Connection`"*, and it is wrong — but wrong for an interesting reason
     * worth pinning rather than arguing (ADR-0072 § *What the BC report says*). Roave compares the
     * v1.0.0 tree against this one, and in the v1.0.0 tree `DatabaseConnection` implements
     * nothing, so it cannot see that the supertype it is being widened to is one the same release
     * gives it.
     *
     * A caller cannot tell. This constructs all five widened surfaces exactly the way code written
     * against v1.0.0 does — passing the concrete class — and that is the claim the report cannot
     * evaluate.
     */
    public function testEveryCallShapeWrittenAgainstTheConcreteClassStillWorks(): void
    {
        $connection = new DatabaseConnection($this->enginePdo());

        $repository = new UserRepository($connection);
        $gateway = new TableGateway($connection, 'people', Person::class, 'id');
        $query = new QueryBuilder($connection, 'people');
        $insert = MutationBuilder::insert($connection, 'people', ['name' => 'Ada']);
        $transaction = new Transaction($connection);

        self::assertInstanceOf(UserRepository::class, $repository);
        self::assertInstanceOf(TableGateway::class, $gateway);
        self::assertInstanceOf(QueryBuilder::class, $query);
        self::assertInstanceOf(MutationBuilder::class, $insert);
        self::assertInstanceOf(Transaction::class, $transaction);
    }

    // ---- what a consumer can now do, and could not before ----------------------------------------

    public function testARepositoryHydratesThroughAConnectionWithNoDatabaseBehindIt(): void
    {
        $connection = new PdolessConnection([
            ['id' => 1, 'name' => 'Ada', 'age' => 36],
            ['id' => 2, 'name' => 'Grace', 'age' => 45],
        ]);

        $rows = (new UserRepository($connection))->all();

        self::assertContainsOnlyInstancesOf(UserRow::class, $rows);
        self::assertSame(['Ada', 'Grace'], \array_map(static fn (UserRow $r): string => $r->name, $rows));
        self::assertSame(['SELECT id, name, age FROM users ORDER BY id'], $connection->sql());
    }

    public function testAGatewayIsWiredAndQueriedWithoutADriver(): void
    {
        $connection = new PdolessConnection([['id' => 7, 'name' => 'Ada', 'age' => 36, 'status' => 'active']]);

        /** @var TableGateway<Person> $gateway */
        $gateway = new TableGateway($connection, 'people', Person::class, 'id');
        $found = $gateway->findBy(['name' => 'Ada']);

        self::assertCount(1, $found);
        self::assertSame('Ada', $found[0]->name);
        // The gateway ran its table name through the allowlist at construction, which needs
        // driver() and nothing else — the fail-fast ADR-0044 wires in still works on a fake.
        self::assertCount(1, $connection->statements);
    }

    public function testAWriteThroughTheGatewayReportsTheAffectedCountFromTheSeam(): void
    {
        $connection = new PdolessConnection(affected: 3);

        /** @var TableGateway<Person> $gateway */
        $gateway = new TableGateway($connection, 'people', Person::class, 'id');

        self::assertSame(3, $gateway->updateBy(['status' => 'active'], ['status' => 'reviewed']));
    }

    /**
     * Constructing a `Repository` builds a `Transaction` eagerly. That is the one line that could
     * have made the whole seam unusable — a constructor that touched `pdo()` would mean no fake
     * could ever be handed to a repository — and it does not, because `Transaction::__construct()`
     * only stores what it is given.
     *
     * Asserted rather than argued, because it is a property of a collaborator's constructor and
     * nothing else in the suite would notice it changing.
     */
    public function testConstructingARepositoryNeverReachesForThePdo(): void
    {
        $connection = new PdolessConnection();

        $repository = new UserRepository($connection);
        $gateway = new TableGateway($connection, 'people', Person::class, 'id');

        // Reaching this line at all is the assertion: PdolessConnection::pdo() throws, so a
        // constructor that touched it would have failed above rather than here.
        self::assertInstanceOf(UserRepository::class, $repository);
        self::assertInstanceOf(TableGateway::class, $gateway);
        self::assertSame([], $connection->sql(), 'wiring must issue no statements either');
    }

    /**
     * …and the boundary of that claim, which is the part ADR-0072 has to be honest about.
     *
     * `Transaction` is built on PDO's own transaction verbs and ADR-0016's savepoints, so a
     * connection with no PDO cannot run one. The failure is loud and comes from the fake, which is
     * the correct place: the library did not pretend it could transact.
     */
    public function testOnlyTheTransactionPathReachesForThePdo(): void
    {
        $repository = new UserRepository(new PdolessConnection());

        $this->expectException(LogicException::class);

        $repository->inTransaction(static fn (): int => 1);
    }

    // ---- the additive half: no generated SQL moved -------------------------------------------------

    /**
     * **Widening a parameter type must not change what is generated.**
     *
     * Rendered twice — once through a real {@see DatabaseConnection}, once through a fake that
     * reports the same driver — and compared. A literal expected string would only assert what
     * this file believes the builder does; comparing the two paths asserts that the interface and
     * the class produce the same thing, which is the claim the release notes make.
     */
    public function testTheBuildersRenderTheSameSqlThroughTheInterface(): void
    {
        $real = new DatabaseConnection($this->enginePdo());
        $fake = new PdolessConnection(driver: $real->driver());

        $select = static fn (Connection $c): string => (new QueryBuilder($c, 'people'))
            ->select('id', 'name')
            ->where('status', Operator::Equals, 'active')
            ->orderBy('id')
            ->limit(10)
            ->toSql();

        self::assertSame($select($real), $select($fake));

        $insert = static fn (Connection $c): string => SqlStatement::fromMutation(
            MutationBuilder::insert($c, 'people', ['name' => 'Ada', 'age' => 36]),
        )->sql;

        self::assertSame($insert($real), $insert($fake));

        $update = static fn (Connection $c): string => SqlStatement::fromMutation(
            MutationBuilder::update($c, 'people', ['name' => 'Ada'], ['id' => 1]),
        )->sql;

        self::assertSame($update($real), $update($fake));

        $delete = static fn (Connection $c): string => SqlStatement::fromMutation(
            MutationBuilder::delete($c, 'people', ['id' => 1]),
        )->sql;

        self::assertSame($delete($real), $delete($fake));
    }

    /**
     * The allowlist is the same allowlist. A seam that quietly skipped it on the interface path
     * would be a hole opened by a refactor whose whole premise is that nothing changed.
     */
    public function testTheIdentifierAllowlistStillRefusesThroughTheInterface(): void
    {
        $connection = new PdolessConnection();

        $this->expectException(DatabaseException::class);

        new TableGateway($connection, 'people; DROP TABLE people', Person::class);
    }
}
