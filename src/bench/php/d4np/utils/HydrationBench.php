<?php

declare(strict_types=1);

namespace D4np\Utils\Bench;

use D4np\Utils\Bench\Fixture\TenScalarPropsDto;
use PhpBench\Attributes as Bench;

/**
 * NFR-01: DTO hydration (10 scalar props) ≤ 5 µs/DTO warm (cached reflection) and ≤ 3×
 * manual constructor assignment.
 *
 * **Split into two halves, deliberately, because they need different tools.**
 *
 * The **absolute** half (≤ 5 µs) is tied to a specific reference machine (spec NFR-06: a Ryzen
 * 7 5800X) and a specific methodology (OPcache + JIT off). Asserting a hard microsecond ceiling
 * here would fail on any CI runner slower than that machine for reasons having nothing to do
 * with a regression — that is deliberately **not** what this benchmark enforces; the nightly,
 * baseline-tracked regression check (`--assert` against a stored `--ref`) is roadmap item
 * **7.1**'s job, not this one's. What this file gives that later item is a benchmark that
 * actually runs and produces a real number, which it did not have before.
 *
 * The **relative** half (≤ 3× manual construction) *is* safely assertable anywhere: both
 * subjects run in the same process, on the same hardware, in the same CI job, so absolute
 * clock-speed noise cancels out of the ratio. PHPBench's `@Assert` cannot express it — its
 * `baseline` refers to a previous **tagged run**, not another subject in the same run (checked
 * against the expression-language docs before writing this). The ratio is instead computed by
 * `tools/bench_ratio_gate.py` from `phpbench run --dump-file`'s XML, the same
 * stdlib-Python-gate shape as `coverage_gate.py` and `action_pin_lint.py`.
 *
 * Both subjects run over the identical payload from {@see TenScalarPropsDto::payload()}, so a
 * change to the fixture's shape cannot make them measure different things.
 */
#[Bench\BeforeMethods('warmReflectionCache')]
#[Bench\Iterations(10)]
#[Bench\Revs(100)]
#[Bench\RetryThreshold(5)]
final class HydrationBench
{
    /**
     * NFR-01 says "warm (cached reflection)" — the cost this benchmark measures is hydration
     * with the reflection already paid for, not the one-time cost of the first lookup. Run once
     * before the timed revs, outside every iteration.
     */
    public function warmReflectionCache(): void
    {
        TenScalarPropsDto::fromArray(TenScalarPropsDto::payload());
    }

    public function benchHydrateWarm(): void
    {
        TenScalarPropsDto::fromArray(TenScalarPropsDto::payload());
    }

    /**
     * The comparison point NFR-01 names directly: constructing the same shape by hand, with no
     * reflection, no key checking, no type coercion — the floor hydration is measured against.
     */
    public function benchManualConstruction(): void
    {
        $p = TenScalarPropsDto::payload();
        new TenScalarPropsDto(
            $p['a'],
            $p['b'],
            $p['c'],
            $p['d'],
            $p['e'],
            $p['f'],
            $p['g'],
            $p['h'],
            $p['i'],
            $p['j'],
        );
    }
}
