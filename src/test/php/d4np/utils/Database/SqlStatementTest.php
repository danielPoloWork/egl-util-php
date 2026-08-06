<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Database;

use D4np\Utils\Database\SqlStatement;
use PHPUnit\Framework\TestCase;

/**
 * `SqlStatement` — spec r3 FR-33 (RFC-0002), ADR-0039.
 *
 * The class has no behaviour beyond pairing two readonly values, so what is worth asserting is
 * exactly that pairing: both fields are readable back unchanged, and a statement with nothing to
 * bind does not require a caller to say so.
 */
final class SqlStatementTest extends TestCase
{
    public function testSqlAndParametersAreReadableBack(): void
    {
        $statement = new SqlStatement('SELECT * FROM users WHERE id = ?', [42]);

        self::assertSame('SELECT * FROM users WHERE id = ?', $statement->sql);
        self::assertSame([42], $statement->parameters);
    }

    public function testParametersDefaultToEmpty(): void
    {
        $statement = new SqlStatement('SELECT 1');

        self::assertSame([], $statement->parameters);
    }

    public function testNamedParametersAreKeptByName(): void
    {
        $statement = new SqlStatement('SELECT * FROM users WHERE age = :age', ['age' => 36]);

        self::assertSame(['age' => 36], $statement->parameters);
    }
}
