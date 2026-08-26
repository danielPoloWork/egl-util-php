<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Database\Fixture;

use D4np\Utils\Database\Connection;
use D4np\Utils\Database\SqlStatement;
use LogicException;
use PDO;

/**
 * A {@see Connection} with **no database behind it at all** — the thing issue #113 exists to make
 * possible.
 *
 * This fixture is the proof, not a convenience. Every claim ADR-0072 makes about the seam is a
 * claim about what a consumer can build without a driver, and the only way to assert that is to
 * build one and drive the library with it. So `pdo()` **throws**: if any read or write path ever
 * reaches for the escape hatch, the suite says so at the line that did it rather than in a
 * docblock nobody re-checks.
 *
 * It records what it was handed, because the second half of the claim is that widening the
 * parameter types changed no SQL — the builders must produce, through the interface, exactly what
 * they produced through the class.
 */
final class PdolessConnection implements Connection
{
    /** @var list<SqlStatement> every statement handed to this connection, in order */
    public array $statements = [];

    /**
     * @param list<array<string, mixed>> $rows what `select()` and `selectOne()` return
     */
    public function __construct(
        private readonly array $rows = [],
        private readonly int $affected = 1,
        private readonly string $driver = 'sqlite',
    ) {
    }

    /**
     * Never called by anything on a read or write path — which is the assertion, not an excuse.
     */
    public function pdo(): PDO
    {
        throw new LogicException(
            'PdolessConnection has no PDO. Reaching this line means something on the path under '
            . 'test needs the escape hatch, and ADR-0072 says only Transaction does.',
        );
    }

    public function driver(): string
    {
        return $this->driver;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function select(SqlStatement $statement): array
    {
        $this->statements[] = $statement;

        return $this->rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function selectOne(SqlStatement $statement): ?array
    {
        $this->statements[] = $statement;

        return $this->rows[0] ?? null;
    }

    public function execute(SqlStatement $statement): int
    {
        $this->statements[] = $statement;

        return $this->affected;
    }

    /**
     * The SQL text of everything this connection was handed.
     *
     * @return list<string>
     */
    public function sql(): array
    {
        return \array_map(static fn (SqlStatement $s): string => $s->sql, $this->statements);
    }
}
