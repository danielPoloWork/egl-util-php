<?php

declare(strict_types=1);

namespace D4np\Utils\Bench;

use D4np\Utils\Persistence\RowNormalizer;
use PhpBench\Attributes as Bench;

/**
 * The `RowNormalizer` component of NFR-09, isolated — roadmap item 10.11, ADR-0047.
 *
 * NFR-09 budgets the whole gateway path (fetch + normalize + hydrate ≤ 2.5× a hand-written PDO
 * loop) and item 10.10's decomposition attributed **27% of the remaining overhead** to this one
 * step: +55.8 µs per 100 rows against an inline trim loop, paid on the *default* policy where the
 * only active step is `trim` (ADR-0042). This class makes that component number a measured
 * subject rather than a one-off profiling result, so a future change to the policy pipeline cannot
 * quietly give the cost back.
 *
 * **Two subjects, because the number that matters is a difference.** The normalizer's absolute
 * time says little on its own — a runner's clock speed moves it — while the *overhead* over the
 * inline loop is what item 10.11 set out to reduce, and it is comparable across machines the same
 * way NFR-01's and NFR-09's ratios are (both subjects run in one invocation on one runner, so
 * clock and virtualization noise apply to both).
 *
 * **No absolute budget is declared for either subject**, deliberately: the spec owns its own
 * numbers (ADR-0040), and NFR-09 budgets the gateway path, not this component. The subjects are
 * measured and reported; the relative regression gate (ADR-0030) is what holds them, and neither
 * is I/O-bound or memory-hard, so neither belongs in ADR-0045's exclusion list.
 *
 * The row shape is {@see GatewayBench}'s, for the same reason it is written into NFR-09: four
 * columns, two of them strings, values padded so trimming has real work to do. 186 of the 400
 * values in a 100-row batch are strings — the per-value dispatch this item removed was 276 ns
 * each.
 */
#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(10)]
#[Bench\Revs(100)]
#[Bench\RetryThreshold(5)]
final class RowNormalizerBench
{
    private const ROW_COUNT = 100;

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    private RowNormalizer $normalizer;

    public function setUp(): void
    {
        // Built in memory rather than fetched: this subject measures the normalizer, and a
        // driver in the loop would put PDO's cost in both numbers and shrink the difference the
        // benchmark exists to show. The shape and padding match what GatewayBench seeds.
        for ($i = 1; $i <= self::ROW_COUNT; $i++) {
            $this->rows[] = [
                'id' => $i,
                'name' => "  Row {$i}  ",
                'age' => 20 + ($i % 50),
                'status' => $i % 7 === 0 ? null : ' active ',
            ];
        }

        $this->normalizer = new RowNormalizer(); // the default policy: trim, and nothing else
    }

    /**
     * The library path on its default policy — the trim-only fast path since item 10.11.
     */
    public function benchNormalizeHundredRows(): void
    {
        foreach ($this->rows as $row) {
            $this->normalizer->normalize($row);
        }
    }

    /**
     * The floor the overhead is measured against: the same guard and the same `trim()`, written
     * inline with no policy object at all — the loop the surveyed estate hand-wrote seventeen
     * times, and the one {@see GatewayBench::benchHandWrittenPdoLoop()} keeps.
     */
    public function benchInlineTrimHundredRows(): void
    {
        foreach ($this->rows as $row) {
            foreach ($row as $column => $value) {
                if (is_string($value)) {
                    $row[$column] = trim($value);
                }
            }
        }
    }
}
