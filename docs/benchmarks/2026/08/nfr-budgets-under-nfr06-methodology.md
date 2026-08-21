# Benchmark Report: every NFR budget re-measured under NFR-06's own methodology

- **Date:** 2026-08-05
- **Version / commit:** v0.0.0 @ `98befdd` (branch `feat/bench-nightly-harness`, roadmap item 7.1)
- **Environment:** GitHub Actions `ubuntu-24.04` runner, PHP **8.3.33** CLI, phpbench 1.4.3,
  OPcache `enable_cli=0`, JIT `disable`, Xdebug off — asserted by
  [`tools/assert_bench_env.php`](../../../../tools/assert_bench_env.php) rather than assumed
- **Command:** `vendor/bin/phpbench run --report=aggregate --dump-file=build/logs/head.xml`
  (the `benchmark / reproducible perf` job in `ci.yml`)

**This report supersedes the "over budget, deferred" conclusions of three earlier records.** Not
because anything was optimised — no production code changed in item 7.1 — but because they were
measured in an environment NFR-06 does not specify, and this one is measured in an environment it
almost entirely does.

## Results

| Subject | Measured | Budget | Verdict |
|---|---|---|---|
| `benchHydrateWarm` | **0.958 µs** | NFR-01 ≤ 5 µs | met, 5.2× headroom |
| `benchHydrateWarm` / `benchManualConstruction` | **2.40×** | NFR-01 ≤ 3× | met |
| `benchBuildFiveConditionSelect` | **3.776 µs** | NFR-03 ≤ 10 µs | met, 2.6× headroom |
| `benchWarmSingletonResolve` | **0.061 µs** | NFR-02 ≤ 2 µs | met, 33× headroom |
| `benchFirstAutowiredResolve` | **7.331 µs** | NFR-02 ≤ 30 µs | met, 4.1× headroom |
| `benchMakeArgon2id` | **148.326 ms** | NFR-05 50–200 ms | inside the range |
| `benchMakeBcrypt` | 57.088 ms | (fallback, same range) | inside the range |
| `benchVerifyArgon2id` | 146.435 ms | — | symmetric with `make`, as expected |
| `benchHydrateTenThousand` | 10.152 ms | NFR-04 is a *memory* budget | see below |
| `benchBuildRealisticPagedQuery` | 6.809 µs | — | context for NFR-03's literal subject |
| `benchFirstAutowiredResolveOfASingleClass` | 1.102 µs | — | the per-class floor |
| `benchWarmInstanceResolve` | 0.056 µs | — | the container's cheapest path |

## What changed, and what did not

Three budgets were previously recorded as **over**:

| Budget | Earlier record | Here | Ratio |
|---|---|---|---|
| NFR-01 warm hydration | 2.511 µs | 0.958 µs | 2.6× faster |
| NFR-03 five-condition build | 12.979 µs | 3.776 µs | 3.4× faster |
| NFR-05 `Hash::make` | 349 ms | 148.326 ms | 2.4× faster |

A 2.6–3.4× gap is far more than two CPUs of the same generation should differ by, so the honest
reading is not "the runner is fast" — it is that **the earlier environment was not NFR-06's**:

- Those runs were on **Windows**, and NFR-06 specifies neither an OS nor accounts for one.
- More importantly they used **`--php-disable-ini`**, a workaround for this developer machine's
  broken extension list. That flag does not disable OPcache alone; it discards the entire `php.ini`,
  taking every extension with it. It was the only way to get phpbench to run locally, and it made
  the measurements internally consistent but not comparable to a conforming environment.

So the earlier numbers were honest measurements of a **different thing**. That is the same class of
error as the two workload mistakes already on record — item 4.5's NFR-03 subject (ADR-0020) and item
6.4's autoload-in-the-benchmark (ADR-0028) — and it is the third time the *total* looked plausible
while the thing being measured was wrong.

## What this still does not settle

**This runner is not the reference machine either.** NFR-06 names a **Ryzen 7 5800X**; this is a
GitHub `ubuntu-24.04` runner of unstated CPU. Every "met" above is therefore *"met on this runner"*,
which is weaker than the specification's claim and is why the budget gate prints the measured value
rather than only `OK`.

What has genuinely changed is that the budgets are now **continuously checked** rather than measured
once and written up. The gap between "met on CI" and "met on the reference machine" needs either a
run on that hardware or a self-hosted runner; it cannot be closed from here, and pretending otherwise
would be worse than leaving it named.

**NFR-04 is not gated here.** It budgets *"hydrating 10 000 DTOs ≤ 16 MB peak delta"* — a memory
figure, and phpbench's `mem_peak` column reports the whole process's peak (an identical `5.367mb` for
every subject in this run), not a per-subject delta. `MemoryBench` measures the delta itself; wiring
that into a gate needs a different reader than `bench_budget_gate.py`'s `stats/mode`, and is named
here rather than quietly skipped.

**NFR-05's wall clock is deliberately not gated**, per [ADR-0024](../../../adr/0024-assert-the-work-factor-not-the-wall-clock.md).
That decision split the budget by what each half can prove: the security-relevant property is the
**work factor** (`memory_cost` ≥ 19456 KiB, `time_cost` ≥ 2 against OWASP's floor), asserted in a
unit test, because wall-clock time depends on hardware and the work factor does not. 148 ms landing
inside the range is a welcome capacity-planning data point, not a reason to start gating a duration —
and with only 1.35× of headroom to the 200 ms ceiling against a measured 40% cross-runner spread, a
wall-clock gate would be the flaky kind.

## Methodology note

NFR-06 specifies *"10 iterations × 100 revs"*, and `phpbench.json.dist` sets exactly that as the
default. Four subjects override the revolution count with a documented reason in their own docblocks:
`HashBench` and `MemoryBench` use `Revs(1)` because one hash and ten thousand hydrations *are* the
units those NFRs name, and `QueryBuilderBench`/`ContainerBench` use more revolutions because their
subjects run in hundreds of nanoseconds, where 100 revolutions sits too close to timer granularity.
The deviations are per-subject and stated; the default is NFR-06's.
