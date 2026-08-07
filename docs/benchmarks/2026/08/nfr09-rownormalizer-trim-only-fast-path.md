# Benchmark Report: `RowNormalizer`'s per-value dispatch — the trim-only fast path

- **Date:** 2026-08-07
- **Version / commit:** v0.0.0 @ `e1931f7` (base) → this PR's branch (item **10.11**)
- **Environment:** 12th Gen Intel Core i7-12700, Windows 11, PHP **8.3.1** CLI.
  **OPcache inactive in CLI** (`opcache.enable_cli=0`, `opcache_get_status()` reports inactive —
  checked, not assumed), which is the configuration spec **NFR-06** pins for benchmarking and
  `tools/assert_bench_env.php` asserts in CI. Not the NFR-06 reference machine (Ryzen 7 5800X), so
  absolute microseconds here are **not** comparable to CI's; the *differences and ratios* are what
  this report claims.
- **Command:** a standalone profiler, **one variant per process** — `php normprofile.php <variant>`
  — not `vendor/bin/phpbench`. Two reasons, both recorded rather than glossed: phpbench's own
  environment-capture step fails on this machine before any subject runs (a known local quirk,
  ADR-0030 already names this box unreliable), and item 10.10's fourth benchmark-scope error on
  this project taught that **a decomposition is itself a benchmark** — measuring several variants
  in one process let ordering and GC move a stage by 2× (416 µs → 884 µs when measured last).
  The permanent, CI-run subjects are `RowNormalizerBench` (added by this item).

## Scenario

NFR-09 budgets the whole gateway path (fetch + normalize + hydrate 100 rows of a 4-column DTO
≤ 2.5× a hand-written PDO loop, spec r13). Item 10.10 decomposed that overhead per stage and
attributed **27%** of it — everything that is not hydration — to `RowNormalizer`: **+55.8 µs per
100 rows** against an inline trim loop, paid on the **default policy**, where the only active step
is `trim` (ADR-0042).

The workload is `GatewayBench`'s row shape, which NFR-09 names: 4 columns, values padded so
trimming has real work (`'  Row 7  '`, `' active '`), `id`/`age` arriving as `int` from SQLite and
`status` `null` on every seventh row. That is **186 string values per 100-row batch** out of 400 —
the count matters, because the cost being measured is per *value*, not per row.

Method: 400 warm-up batches, then 41 samples of 20 batches each; the figure is the median sample
divided by 20, and every run below is the median of three separate processes.

## Results

**Before → after, the real class:**

| Subject | µs / 100 rows | Overhead over the inline floor | Spread (min–max) |
|---|---|---|---|
| inline trim loop (the floor) | **42.8** | — | 40.7–48.3 |
| `RowNormalizer` **before** (`e1931f7`) | **95.2** | **+52.3** (281 ns per string value) | 90.2–99.8 |
| `RowNormalizer` **after** (this PR) | **65.2** | **+22.3** | 61.9–70.5 |

**58% of the overhead removed** (52.3 → 22.3 µs), semantics unchanged — T-15 green, and the full
suite green at 2424 tests.

**The four designs, measured before choosing** (equivalent implementations, global namespace):

| Design | µs / 100 rows | Overhead |
|---|---|---|
| precomputed `Closure` per policy, one call per value | 70.4 | +27.6 |
| general pipeline inlined behind locals, no method call | 74.5 | +31.7 |
| one loop, hoisted flag consulted per value via a ternary | 59.0 | +16.2 |
| **hoisted flag, fast path outside the loop** (chosen) | **51.5** | **+8.7** |

**Where the residual 22.3 µs goes** — the chosen design measures 51.5 µs in the global namespace
and 65.2 µs as `D4np\Utils\Persistence\RowNormalizer`. Isolated with a two-class probe (identical
bodies, same namespace, one calling `is_string()`/`trim()` and the other `\is_string()`/`\trim()`):

| Probe | µs / 100 rows |
|---|---|
| namespaced, unqualified internal calls | **65.0** |
| namespaced, `\`-prefixed internal calls | **51.4** |

| Residual component | µs / 100 rows |
|---|---|
| PHP's namespace fallback lookup on 372 unqualified internal calls (~36 ns each) | **13.6** |
| the per-*row* `normalize()` call itself (100 calls, ~87 ns each) | **8.7** |
| **total residual** | **22.3** |

## CI's numbers — and the correction they force

The PR's benchmark job ran on the reference-class Linux runner (NFR-06's environment, OPcache and
JIT asserted off by `tools/assert_bench_env.php`). It measured:

| Subject (CI, `RowNormalizerBench`) | µs / 100 rows | Spread |
|---|---|---|
| `benchNormalizeHundredRows` (the class, after this change) | **22.423** | ±1.69% |
| `benchInlineTrimHundredRows` (the floor) | **19.663** | ±0.72% |
| **remaining overhead** | **+2.760** | — |

| NFR-09 on CI | Value |
|---|---|
| `benchGatewayFetchNormalizeHydrate` | 141.754 µs |
| `benchHandWrittenPdoLoop` | 82.098 µs |
| **ratio** | **1.73× (budget 2.5×) — unchanged from `master`** |

**Two claims made earlier in this report are wrong, and this section is the correction.**

1. **"NFR-09 should improve from 1.73× toward ~1.6×" did not happen.** The ratio is 1.73×, the same
   figure `master` measured. It is not a rounding artifact: ADR-0046 recorded this ratio five times
   at **1.71–1.85×** on CI, so a ~2.8 µs change in a 141.75 µs subject sits **inside the gate's own
   noise band** and cannot be resolved by it.
2. **The component was never 27% of NFR-09's overhead on this hardware.** That proportion came from
   item 10.10's decomposition, which — checked now — was measured on the same Windows development
   box as this report's before/after. On CI the normalizer's *remaining* overhead is 2.760 µs of the
   gateway path's 59.656 µs of overhead: **4.6%**. Even doubling it to guess at the pre-change
   figure leaves the component well under 10% of the overhead, not 27%.

**What is therefore known, and what is not.** Known: the class does strictly less work per string
value, the local saving is 30.0 µs per 100 rows, and the post-change overhead on CI is +2.760 µs.
**Not known: the saving on CI hardware.** `RowNormalizerBench` is new, so the same-runner harness
had nothing to compare against — it printed `new` for both subjects, correctly — and NFR-09's
ratio, the one instrument that would have shown the saving indirectly, cannot resolve a change this
small. Obtaining that number would take a throwaway PR carrying the benchmark against the old
implementation; it was not done, and the gap is named here rather than filled with an estimate.

The local figures below are kept exactly as measured, because they are what the decision was made
on. They are Windows numbers: the class costs **2.22× the inline loop before / 1.52× after** on this
box, against **1.14×** on CI. Function-call and property-read overhead is simply a larger share of
this workload here — which is the same effect that made the local `RowNormalizer` look like 27% of a
budget it is responsible for 4.6% of.

## Interpretation

The cost item 10.10 attributed was **dispatch, not work**: 281 ns per string value for a policy
that cannot change between the first value and the last. Hoisting the decision into the constructor
and giving the default policy a direct `trim()` loop removes 58% of it, and the two rejected
rewrite-shaped candidates were both *slower* than the simple one — the closure recovers only half
the dispatch because a closure call is still a call, and inlining the general pipeline pays four
branches per value.

The ternary formulation (59.0 µs) is the more readable design and lost by 7.4 µs — 45% of the win
for a cosmetic gain. That margin is small enough to record explicitly: a future reader who values
the single loop more than 7 µs per 100 rows has the number to make that trade knowingly.

**The namespace-fallback finding is the more transferable result.** Inside a namespace, PHP resolves
an unqualified `trim()` by trying `D4np\Utils\Persistence\trim` first and the global `trim` second,
and NFR-06 pins the benchmark interpreter with OPcache **off** — precisely the configuration where
that second lookup is not optimized away. It is worth **13.6 µs per 100 rows in this one method**,
and this repository calls internal functions unqualified in every file. Two caveats stated plainly:
consumers who run OPcache (most production deployments) will see less of it, and *this* measurement
is one method's hot loop, not a project-wide extrapolation. It is filed as roadmap item **10.12**
rather than fixed here, because the honest fix is the repo-wide `native_function_invocation`
CS-Fixer rule — a risky-rule decision touching every file, and the maintainer's call.

**Caveats.** Absolute numbers in this section are this machine's, not the reference CPU's. The
extrapolation this report originally made from them — NFR-09 improving toward ~1.6× — was measured
and **refuted**; *CI's numbers* above supersede it. Spread ran 7–18% between
the fastest and slowest sample within a process, so the 7.4 µs gap between the top two candidates
is above noise on medians-of-three but not by a wide margin.

## Reproduce

```bash
composer install --optimize-autoloader
vendor/bin/phpbench run --report=aggregate --filter=RowNormalizerBench
```

The two permanent subjects are `benchNormalizeHundredRows` (the class, default policy) and
`benchInlineTrimHundredRows` (the floor); the overhead is their difference, comparable on any one
runner. Neither carries an absolute budget in `bench_budget_gate.py` — the spec owns its numbers
(ADR-0040) and NFR-09 budgets the gateway path, not this component — and neither is I/O-bound or
memory-hard, so both stay inside the relative regression gate (ADR-0030, exclusions per ADR-0045).

To reproduce the candidate comparison or the namespace probe, the profiler used here is not
committed (it measures designs that were rejected); the table above records what it produced, and
`RowNormalizerBench` is what a future run should regress against.
