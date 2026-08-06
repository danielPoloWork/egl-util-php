<?php

declare(strict_types=1);

namespace D4np\Utils\Bench;

use D4np\Utils\Bench\Fixture\GatewayRow;
use D4np\Utils\Database\DatabaseConnection;
use D4np\Utils\Database\SqlStatement;
use D4np\Utils\Persistence\RowNormalizer;
use D4np\Utils\Persistence\TableGateway;
use D4np\Utils\Support\ReflectionCache;
use PDO;
use PhpBench\Attributes as Bench;

/**
 * NFR-09: `Repository`/`TableGateway` fetch + normalize + hydrate 100 rows ≤ 1.5× a hand-written
 * PDO loop doing the same work.
 *
 * **Same shape as NFR-01's ratio half** (roadmap 3.5), for the same reason: PHPBench's own
 * `@Assert` compares a subject against a previous *tagged* run, never against a sibling subject
 * in the same run, so the relative budget is computed by `tools/bench_ratio_gate.py` from the
 * `--dump-file` XML rather than asserted in-process. Both subjects run on identical hardware in
 * the same invocation, so clock-speed and virtualization noise apply to numerator and
 * denominator alike and mostly cancel out of the ratio.
 *
 * **"The same work" is checked, not assumed.** The gateway subject does what `TableGateway::all()`
 * actually does: select the projected columns, run every string value through a configured
 * {@see RowNormalizer} (its default policy — trim only, ADR-0042), then hydrate through the
 * shared, warmed {@see ReflectionCache} (ADR-0006). The hand-written subject reads the identical
 * SQL against the identical connection, trims every string value itself — the same condition
 * {@see RowNormalizer::normalize()} tests, `is_string($value)` — and constructs
 * {@see GatewayRow} directly, with no reflection at all. A version of this benchmark that trimmed
 * only the manual loop, or hydrated without a normalizer configured on the gateway side, would be
 * measuring two different things and reporting the difference as gateway overhead.
 *
 * Both subjects share one `PDO` connection (`sqlite::memory:` cannot be reopened — a second `PDO`
 * would see an empty database) and one 100-row table, seeded once outside every timed iteration,
 * matching {@see HydrationBench}'s warm-cache convention: NFR-09 budgets steady-state cost, not
 * the one-time price of the first reflection lookup or the first connection.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(10)]
#[Bench\Revs(100)]
#[Bench\RetryThreshold(5)]
final class GatewayBench
{
    private const ROW_COUNT = 100;

    private PDO $pdo;

    private DatabaseConnection $connection;

    /** @var TableGateway<GatewayRow> */
    private TableGateway $gateway;

    public function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->connection = new DatabaseConnection($this->pdo);
        $this->connection->execute(SqlStatement::literal(
            'CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT, age INTEGER, status TEXT)',
        ));

        for ($i = 1; $i <= self::ROW_COUNT; $i++) {
            // Padded with whitespace deliberately: NFR-09 budgets normalize() alongside fetch and
            // hydrate, and a value with nothing to trim would let a broken normalizer measure as
            // free. The value returned still has to match — assertible, not merely assumed.
            $this->connection->execute(SqlStatement::literal(
                'INSERT INTO items (id, name, age, status) VALUES (?, ?, ?, ?)',
                [$i, "  Row {$i}  ", 20 + ($i % 50), $i % 7 === 0 ? null : ' active '],
            ));
        }

        $cache = new ReflectionCache();
        $cache->for(GatewayRow::class); // warmed once, outside every timed iteration

        $this->gateway = new TableGateway(
            $this->connection,
            'items',
            GatewayRow::class,
            'id',
            new RowNormalizer(),
            $cache,
        );

        // One warm call, discarded, so the gateway's own lazily-resolved projection (cached on
        // the instance after its first use — Persistence\TableGateway) is paid for here too.
        $this->gateway->all();
    }

    /**
     * The subject NFR-09 names: fetch, normalize, hydrate — 100 rows, through the library.
     *
     * @return list<GatewayRow>
     */
    public function benchGatewayFetchNormalizeHydrate(): array
    {
        return $this->gateway->all();
    }

    /**
     * The comparison point NFR-09 names directly: the same read and the same trimming, written
     * by hand against the same connection, with no reflection and no allowlist.
     *
     * @return list<GatewayRow>
     */
    public function benchHandWrittenPdoLoop(): array
    {
        $statement = $this->pdo->query('SELECT id, name, age, status FROM items');
        $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);

        $hydrated = [];

        foreach ($rows as $row) {
            foreach ($row as $column => $value) {
                // The exact condition RowNormalizer::normalize() applies: only string values are
                // touched, so an int/null column passes through by identity.
                if (is_string($value)) {
                    $row[$column] = trim($value);
                }
            }

            $hydrated[] = new GatewayRow(
                (int) $row['id'],
                (string) $row['name'],
                $row['age'] === null ? null : (int) $row['age'],
                $row['status'] === null ? null : (string) $row['status'],
            );
        }

        return $hydrated;
    }
}
