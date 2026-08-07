<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Persistence;

use D4np\Utils\Database\DatabaseConnection;
use D4np\Utils\Database\MutationBuilder;
use D4np\Utils\Database\SqlStatement;
use D4np\Utils\Persistence\RowNormalizer;
use D4np\Utils\Persistence\TableGateway;
use D4np\Utils\Support\DatabaseException;
use D4np\Utils\Tests\Database\Fixture\InjectionPayloads;
use D4np\Utils\Tests\Database\Fixture\LoggedStatement;
use D4np\Utils\Tests\Database\Fixture\QueryLog;
use D4np\Utils\Tests\Persistence\Fixture\Person;
use D4np\Utils\Tests\Persistence\Fixture\PersonGateway;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Spec §7's **T-13**: *"gateway/statement injection — ADR-0017's payload corpus re-run through
 * `Repository`/`TableGateway`, placeholder-only text asserted at the PDO boundary via the
 * `QueryLog` fixture"*.
 *
 * **Why this is not a copy of T-02.** T-02 proved the property for the paths that existed at item
 * 4.4: `DatabaseConnection`'s three methods, two `QueryBuilder` clauses, and a transaction. Since
 * then the composed path grew — `TableGateway` → `MutationBuilder`/`QueryBuilder` →
 * `SqlStatement` → `DatabaseConnection` → PDO — and item 10.4's own suite stops one layer short:
 * it asserts what the *builder rendered*, which is a claim about a string, not about what the
 * driver was handed. Between the two lies everything a gateway adds — a projection, an assembled
 * `WHERE`, a `SET` list, and the ordering of two parameter groups. This suite watches the
 * boundary for all of it.
 *
 * Both corpora come from {@see InjectionPayloads}, the single list item 10.5 unified, so a
 * payload added anywhere protects every caller.
 *
 * The identifier leg carries an assertion the value leg cannot: a refused column name must leave
 * the log **empty**. Refusing an identifier after preparing a statement would be a refusal that
 * arrives too late to matter, and no round-trip assertion can see the difference.
 */
#[Group('T-13')]
#[RequiresPhpExtension('pdo_sqlite')]
final class GatewayInjectionTest extends TestCase
{
    private QueryLog $log;

    private DatabaseConnection $connection;

    /** @var TableGateway<Person> */
    private TableGateway $gateway;

    protected function setUp(): void
    {
        $this->log = new QueryLog();

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [LoggedStatement::class, [$this->log]]);

        $this->connection = new DatabaseConnection($pdo);
        $this->connection->execute(SqlStatement::literal(
            'CREATE TABLE people (id INTEGER PRIMARY KEY, name TEXT, age INTEGER, status TEXT, secret TEXT)',
        ));
        $this->connection->execute(SqlStatement::literal('CREATE TABLE secrets (token TEXT)'));
        $this->connection->execute(SqlStatement::literal("INSERT INTO secrets (token) VALUES ('do-not-leak')"));

        $this->gateway = new TableGateway($this->connection, 'people', Person::class, 'id');

        // Only the payload traffic matters; drop the fixture's own setup from the log.
        $this->log->entries = [];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function payloads(): iterable
    {
        yield from InjectionPayloads::values();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileIdentifiers(): iterable
    {
        yield from InjectionPayloads::identifiers();
    }

    /**
     * The core assertion: at the last point where text and values are still separable, the
     * payload is in the parameter array and nowhere in the statement text.
     *
     * ADR-0017 records why this boundary is sufficient — with `ATTR_EMULATE_PREPARES` pinned off
     * (ADR-0014), PDO does not interpolate, so placeholder-only text here is placeholder-only
     * text on the wire.
     */
    private function assertBoundNeverInlined(string $payload): void
    {
        $statements = $this->log->statements();

        self::assertNotSame([], $statements, 'nothing was logged — the assertion would be vacuous');

        // The empty string is degenerate for a substring check: every string contains it. The
        // binding half below still applies, so it stays in the corpus and this half is skipped
        // rather than fudged (T-02's reasoning, unchanged).
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

    private function seed(int $id, string $name): void
    {
        $this->connection->execute(SqlStatement::literal(
            'INSERT INTO people (id, name, age, status, secret) VALUES (?, ?, ?, ?, ?)',
            [$id, $name, 1, 'active', 'x'],
        ));
        $this->log->entries = [];
    }

    // ---- TableGateway: every surface that takes a value ------------------------------------------

    #[DataProvider('payloads')]
    public function testGatewayInsertBindsTheValue(string $payload): void
    {
        $this->gateway->insert(['name' => $payload]);

        $this->assertBoundNeverInlined($payload);
    }

    #[DataProvider('payloads')]
    public function testGatewayFindBindsTheKey(string $payload): void
    {
        $this->gateway->find($payload);

        $this->assertBoundNeverInlined($payload);
    }

    #[DataProvider('payloads')]
    public function testGatewayFindByBindsTheCriterion(string $payload): void
    {
        $this->gateway->findBy(['name' => $payload]);

        $this->assertBoundNeverInlined($payload);
    }

    #[DataProvider('payloads')]
    public function testGatewayFindOneByBindsTheCriterion(string $payload): void
    {
        $this->gateway->findOneBy(['name' => $payload]);

        $this->assertBoundNeverInlined($payload);
    }

    #[DataProvider('payloads')]
    public function testGatewayUpdateBindsTheValueBeingWritten(string $payload): void
    {
        $this->seed(1, 'Ada');

        $this->gateway->update(1, ['name' => $payload]);

        $this->assertBoundNeverInlined($payload);
    }

    #[DataProvider('payloads')]
    public function testGatewayUpdateByBindsTheCriterion(string $payload): void
    {
        $this->gateway->updateBy(['name' => $payload], ['status' => 'reviewed']);

        $this->assertBoundNeverInlined($payload);
    }

    #[DataProvider('payloads')]
    public function testGatewayDeleteByBindsTheCriterion(string $payload): void
    {
        $this->gateway->deleteBy(['name' => $payload]);

        $this->assertBoundNeverInlined($payload);
    }

    // ---- MutationBuilder, reached directly --------------------------------------------------------

    #[DataProvider('payloads')]
    public function testMutationBuilderInsertBindsThroughTheConnection(string $payload): void
    {
        $this->connection->execute(SqlStatement::fromMutation(
            MutationBuilder::insert($this->connection, 'people', ['name' => $payload]),
        ));

        $this->assertBoundNeverInlined($payload);
    }

    #[DataProvider('payloads')]
    public function testMutationBuilderUpdateBindsBothGroups(string $payload): void
    {
        $this->connection->execute(SqlStatement::fromMutation(
            MutationBuilder::update($this->connection, 'people', ['name' => $payload], ['name' => $payload]),
        ));

        $this->assertBoundNeverInlined($payload);
    }

    #[DataProvider('payloads')]
    public function testMutationBuilderDeleteBindsTheCriterion(string $payload): void
    {
        $this->connection->execute(SqlStatement::fromMutation(
            MutationBuilder::delete($this->connection, 'people', ['name' => $payload]),
        ));

        $this->assertBoundNeverInlined($payload);
    }

    // ---- Repository, including inside a transaction ------------------------------------------------

    #[DataProvider('payloads')]
    public function testRepositoryFetchAllBindsTheValue(string $payload): void
    {
        (new PersonGateway($this->connection))->named($payload);

        $this->assertBoundNeverInlined($payload);
    }

    #[DataProvider('payloads')]
    public function testBindingHoldsInsideAGatewayTransaction(string $payload): void
    {
        (new PersonGateway($this->connection))->insertInTransaction(['name' => $payload]);

        $this->assertBoundNeverInlined($payload);
    }

    /**
     * A normalizer rewrites values *after* the driver returned them, so it cannot affect binding —
     * but it is the one collaborator that touches payload bytes on the way out, and "cannot" is
     * cheaper to assert than to argue.
     */
    #[DataProvider('payloads')]
    public function testBindingHoldsWithANormalizerConfigured(string $payload): void
    {
        $gateway = new TableGateway(
            $this->connection,
            'people',
            Person::class,
            'id',
            new RowNormalizer(),
        );

        $gateway->findBy(['name' => $payload]);

        $this->assertBoundNeverInlined($payload);
    }

    // ---- the identifier leg -------------------------------------------------------------------------

    /**
     * Every gateway surface where a **column name** can arrive from the caller refuses a hostile
     * one — and refuses it **before preparing anything**.
     *
     * The empty log is the part a round-trip test cannot see: a gateway that built the statement,
     * sent it, and then noticed the identifier would pass any assertion about the exception while
     * having already run the injection.
     *
     * @return iterable<string, array{callable(TableGateway<Person>, string): mixed}>
     */
    public static function columnSurfaces(): iterable
    {
        yield 'findBy criterion'    => [static fn (TableGateway $g, string $id): mixed => $g->findBy([$id => 'x'])];
        yield 'findOneBy criterion' => [static fn (TableGateway $g, string $id): mixed => $g->findOneBy([$id => 'x'])];
        yield 'insert column'       => [static fn (TableGateway $g, string $id): mixed => $g->insert([$id => 'x'])];
        yield 'update value'        => [static fn (TableGateway $g, string $id): mixed => $g->update(1, [$id => 'x'])];
        yield 'updateBy criterion'  => [static fn (TableGateway $g, string $id): mixed => $g->updateBy([$id => 'x'], ['name' => 'y'])];
        yield 'updateBy value'      => [static fn (TableGateway $g, string $id): mixed => $g->updateBy(['name' => 'y'], [$id => 'x'])];
        yield 'deleteBy criterion'  => [static fn (TableGateway $g, string $id): mixed => $g->deleteBy([$id => 'x'])];
    }

    /**
     * @return iterable<string, array{string, callable(TableGateway<Person>, string): mixed}>
     */
    public static function identifierSurfaceMatrix(): iterable
    {
        foreach (self::identifiers() as $payloadName => [$identifier]) {
            foreach (self::columnSurfaces() as $surfaceName => [$operation]) {
                yield $surfaceName . ' / ' . $payloadName => [$identifier, $operation];
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    private static function identifiers(): iterable
    {
        yield from InjectionPayloads::identifiers();
    }

    /**
     * @param callable(TableGateway<Person>, string): mixed $operation
     */
    #[DataProvider('identifierSurfaceMatrix')]
    public function testAHostileColumnNameIsRefusedBeforeAnythingIsPrepared(
        string $identifier,
        callable $operation,
    ): void {
        try {
            $operation($this->gateway, $identifier);
            self::fail('the hostile identifier was accepted: ' . \var_export($identifier, true));
        } catch (DatabaseException) {
            // The refusal is the expected outcome; what follows is the part worth asserting.
        }

        self::assertSame(
            [],
            $this->log->entries,
            'the identifier was refused only after a statement had already been prepared',
        );
    }

    #[DataProvider('hostileIdentifiers')]
    public function testAHostileTableNameIsRefusedAtConstructionAndPreparesNothing(string $identifier): void
    {
        try {
            new TableGateway($this->connection, $identifier, Person::class);
            self::fail('the hostile table name was accepted: ' . \var_export($identifier, true));
        } catch (DatabaseException) {
            // Expected — ADR-0044's fail-fast at wiring time.
        }

        self::assertSame([], $this->log->entries);
    }

    // ---- what the database actually did --------------------------------------------------------------

    /**
     * Binding is a claim about syntax; this is the claim about consequences. The payload survives
     * a full write-read cycle through hydration, and neither table has been touched by it.
     */
    #[DataProvider('payloads')]
    public function testThePayloadRoundTripsThroughHydrationAndTheSchemaSurvives(string $payload): void
    {
        $this->gateway->insert(['id' => 1, 'name' => $payload, 'age' => 1, 'status' => 'active']);

        $person = $this->gateway->find(1);

        self::assertInstanceOf(Person::class, $person);
        self::assertSame($payload, $person->name);
        self::assertCount(1, $this->gateway->all());
        // Exfiltration and destruction both leave traces here.
        self::assertSame(
            [['n' => 1]],
            $this->connection->select(SqlStatement::literal('SELECT COUNT(*) AS n FROM secrets')),
        );
    }

    /**
     * The practical statement of the mechanism: bound, a tautology is a name nobody has.
     */
    public function testATautologyCriterionMatchesNothing(): void
    {
        $this->seed(1, 'Ada');
        $this->seed(2, 'Grace');

        self::assertSame([], $this->gateway->findBy(['name' => "' OR '1'='1"]));
        self::assertCount(2, $this->gateway->all());
    }

    /**
     * …and the destructive version of the same point, which is the one the estate was exposed to:
     * a payload in a `DELETE` criterion deletes nothing.
     */
    public function testATautologyInADeleteCriterionRemovesNothing(): void
    {
        $this->seed(1, 'Ada');
        $this->seed(2, 'Grace');

        self::assertSame(0, $this->gateway->deleteBy(['name' => "' OR '1'='1"]));
        self::assertCount(2, $this->gateway->all());
    }

    /**
     * **Parameter order across two groups**, which is this composed path's own failure mode and
     * has no equivalent in T-02.
     *
     * `UPDATE … SET a = ? WHERE b = ?` binds two groups in sequence. Swap them and the statement
     * still runs, still affects a plausible number of rows, and writes the *criterion* into the
     * column — a silent wrong answer that every syntax-level assertion in this file would miss.
     * So the two payloads are distinct and the resulting row is read back.
     */
    public function testTheSetValuesBindBeforeTheCriteriaAndTheRightRowChanges(): void
    {
        $this->seed(1, 'target');
        $this->seed(2, 'bystander');

        $affected = $this->gateway->updateBy(['name' => 'target'], ['name' => "written'--"]);

        // Captured before the read-backs below, which bind their own key values into the same log.
        self::assertSame(["written'--", 'target'], $this->log->boundValues());

        self::assertSame(1, $affected);
        self::assertSame("written'--", $this->gateway->find(1)?->name);
        self::assertSame('bystander', $this->gateway->find(2)?->name);
    }

    /**
     * One statement per operation, and every value in it a placeholder.
     *
     * A count is a coarse assertion, but it catches the shape no substring check can: a builder
     * that inlined *some* values and bound others would still pass the containment test for the
     * bound ones.
     */
    public function testEveryValueInAGatewayStatementIsAPlaceholder(): void
    {
        $this->gateway->updateBy(['name' => 'a', 'status' => 'b'], ['name' => 'c', 'age' => 4]);

        $statements = $this->log->statements();
        self::assertCount(1, $statements);
        self::assertSame(4, \substr_count($statements[0], '?'));
        self::assertCount(4, $this->log->boundValues());
    }
}
