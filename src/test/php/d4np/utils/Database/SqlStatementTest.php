<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Database;

use D4np\Utils\Database\DatabaseConnection;
use D4np\Utils\Database\Operator;
use D4np\Utils\Database\QueryBuilder;
use D4np\Utils\Database\SqlStatement;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * `SqlStatement` — spec r3 FR-33 (RFC-0002), ADR-0039 as amended by ADR-0041.
 *
 * The value semantics are trivial and asserted first. What is *not* trivial, and what item 10.7
 * exists for, is the `literal-string` constraint on {@see SqlStatement::literal()}: a
 * static-analysis property that no runtime test can exercise, because by the time PHP is running
 * the string is just a string.
 *
 * So it is asserted the way ADR-0027 asserts constant-time comparison — **as a mechanism, read
 * from the source**. The proof that PHPStan actually rejects an interpolated argument is in
 * ADR-0041, produced by planting the two mistakes and running the analyser; what the assertions
 * below defend is that the annotation which makes that proof hold is still there, and that no
 * fourth unconstrained way into the class has appeared beside it.
 */
#[Group('T-02')]
final class SqlStatementTest extends TestCase
{
    // ---- value semantics -----------------------------------------------------------------------

    public function testSqlAndParametersAreReadableBack(): void
    {
        $statement = SqlStatement::literal('SELECT * FROM users WHERE id = ?', [42]);

        self::assertSame('SELECT * FROM users WHERE id = ?', $statement->sql);
        self::assertSame([42], $statement->parameters);
    }

    public function testParametersDefaultToEmpty(): void
    {
        self::assertSame([], SqlStatement::literal('SELECT 1')->parameters);
    }

    public function testNamedParametersAreKeptByName(): void
    {
        $statement = SqlStatement::literal('SELECT * FROM users WHERE age = :age', ['age' => 36]);

        self::assertSame(['age' => 36], $statement->parameters);
    }

    public function testComposedCarriesTextAndParametersLikeLiteralDoes(): void
    {
        // The shape the escape hatch exists for: a placeholder count that depends on the data,
        // so the text cannot be a literal however safe its inputs are.
        $values = ['a', 'b', 'c'];
        $sql = 'SELECT * FROM users WHERE name IN (' . implode(', ', array_fill(0, count($values), '?')) . ')';

        $statement = SqlStatement::composed($sql, $values);

        self::assertSame('SELECT * FROM users WHERE name IN (?, ?, ?)', $statement->sql);
        self::assertSame($values, $statement->parameters);
    }

    // ---- the mechanism (ADR-0041) --------------------------------------------------------------

    /**
     * The annotation is the whole guarantee. Removing it would leave every test in this suite
     * green — the strings are literals either way — and silently reopen the class to
     * interpolated text, which is exactly the failure ADR-0027 names for behavioural tests of
     * a non-behavioural property.
     */
    public function testTheLiteralConstructorConstrainsItsSqlToALiteralString(): void
    {
        $doc = (new ReflectionMethod(SqlStatement::class, 'literal'))->getDocComment();

        self::assertIsString($doc, 'literal() must carry a docblock: it is where the constraint lives');
        self::assertMatchesRegularExpression(
            '/@param\s+literal-string\s+\$sql/',
            $doc,
            'literal() must annotate $sql as literal-string, or PHPStan stops refusing interpolated SQL',
        );
    }

    /**
     * A public constructor would be a fourth way in, unconstrained, and would make the
     * annotation above decorative — `new SqlStatement($anything)` would type-check.
     */
    public function testThereIsNoPublicConstructor(): void
    {
        $constructor = (new ReflectionClass(SqlStatement::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue(
            $constructor->isPrivate(),
            'the constructor must stay private: it is the only parameter in this class not '
            . 'constrained to a literal-string, and a public one would bypass literal()',
        );
    }

    /**
     * The named constructors are the complete public surface, and the list is pinned so that
     * adding a fourth one is a deliberate act with a test to update rather than a quiet
     * widening. `composed()` is the only one taking unconstrained text, which is what makes
     * `grep composed(` the review list ADR-0041 relies on.
     */
    public function testTheOnlyWaysInAreTheThreeNamedConstructors(): void
    {
        $entryPoints = array_values(array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            array_filter(
                (new ReflectionClass(SqlStatement::class))->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $m): bool => $m->isStatic(),
            ),
        ));

        sort($entryPoints);

        self::assertSame(['composed', 'fromQueryBuilder', 'literal'], $entryPoints);
    }

    // ---- fromQueryBuilder ----------------------------------------------------------------------

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testFromQueryBuilderCarriesTheBuildersOwnSqlAndBindings(): void
    {
        $connection = new DatabaseConnection(new PDO('sqlite::memory:'));
        $builder = (new QueryBuilder($connection, 'users'))
            ->where('name', Operator::Equals, 'Ada')
            ->where('age', Operator::GreaterThan, 30);

        $statement = SqlStatement::fromQueryBuilder($builder);

        self::assertSame($builder->toSql(), $statement->sql);
        self::assertSame($builder->bindings(), $statement->parameters);
    }
}
