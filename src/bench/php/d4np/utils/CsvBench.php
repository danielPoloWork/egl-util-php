<?php

declare(strict_types=1);

namespace D4np\Utils\Bench;

use D4np\Utils\Support\Csv;
use Generator;
use PhpBench\Attributes as Bench;

/**
 * NFR-12: writing 10 000 rows × 10 columns ≤ 150 ms (spec r3 FR-28, RFC-0002).
 *
 * **Only the timing half is a benchmark's business.** NFR-12's other clause — "memory
 * O(row), never a full-table buffer" — is a property of {@see Csv::write()}'s `iterable`
 * parameter and {@see \D4np\Utils\Support\File::writeStream()}'s handle-based writer: the
 * table is never materialized, so there is no peak to read a delta from, and a memory
 * benchmark here would report the *generator's* footprint, not the table's absence. Same
 * shape as {@see QueryBuilderBench}'s "0 queries executed": a claim a stopwatch cannot see
 * is proven by construction and by
 * {@see \D4np\Utils\Tests\Support\CsvRoundTripTest}, not measured.
 *
 * `Revs(1)` is deliberate, {@see MemoryBench}'s reasoning: 10 000 rows already is the unit
 * of work NFR-12 names, so one revolution measures it directly rather than averaging
 * several apart.
 *
 * The absolute ≤ 150 ms ceiling carries the same caveat every benchmark here documents
 * (roadmap 3.5, ADR-0018, ADR-0030): tied to spec NFR-06's reference machine, gated on CI
 * hardware only because ADR-0030 measured the headroom to survive cross-runner noise.
 */
#[Bench\Revs(1)]
#[Bench\Iterations(5)]
#[Bench\RetryThreshold(5)]
final class CsvBench
{
    private const ROWS = 10_000;
    private const COLUMNS = 10;

    private string $path;

    public function setUpPath(): void
    {
        $this->path = sys_get_temp_dir() . '/egl-utils-csv-bench-' . bin2hex(random_bytes(8)) . '.csv';
    }

    public function tearDownPath(): void
    {
        @unlink($this->path);
        @unlink($this->path . '.lock');
    }

    #[Bench\BeforeMethods('setUpPath')]
    #[Bench\AfterMethods('tearDownPath')]
    public function benchWriteTenThousandByTen(): void
    {
        Csv::write($this->path, self::rows());
    }

    /**
     * @return Generator<int, list<int>>
     */
    private static function rows(): Generator
    {
        for ($i = 0; $i < self::ROWS; $i++) {
            yield array_fill(0, self::COLUMNS, $i);
        }
    }
}
