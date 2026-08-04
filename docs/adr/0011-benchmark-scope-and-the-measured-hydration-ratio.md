# ADR-0011: Benchmarks measure NFR-01/NFR-04 now; absolute regression tracking and the
measured ~15× ratio gap are deliberately deferred

- **Status:** Accepted
- **Date:** 2026-08-04
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 3.5 · item 3.7 (filed by this ADR) · item 7.1 · spec NFR-01, NFR-04,
  NFR-06 · [RFC-0001](../rfc/0001-egl-utils-library.md)

## Context

Spec NFR-01 asks for DTO hydration (10 scalar props, warm/cached reflection) to be **≤ 5 µs**
and **≤ 3× the cost of manual constructor assignment**. NFR-04 asks that hydrating 10,000 such
DTOs cost **≤ 16 MB peak**. Item 3.5's job was to give both of these a real, measured benchmark
for the first time — none existed before this item.

Two tooling facts, checked against phpbench 1.4.3's own source and docs rather than assumed,
shaped what a benchmark here can and cannot assert:

1. **`@Assert`'s `baseline` is a previous *tagged* run (`--ref=<tag>`), not a sibling subject in
   the same run.** Confirmed against `docs/guides/assertions.rst`, `docs/expression.rst`,
   `docs/guides/regression-testing.rst`, and the shipped
   `examples/Assertion/ExampleAssertionsBench.php`. NFR-01's ≤3× ratio compares two subjects
   (`benchHydrateWarm` vs. `benchManualConstruction`) run *together* — `@Assert` has no
   expression for that.
2. **`mem.peak` is whole-process peak** (`memory_get_peak_usage()` read at the end of one
   iteration, inside phpbench's own executor subprocess — read directly from
   `lib/Executor/Benchmark/template/memory.template`), not a delta scoped to the subject's own
   allocations. An empty benchmark method measures ≈1.8 MB of pure bootstrap/autoloader
   overhead on top of whatever the subject itself allocates.
3. **phpbench's memory-unit docs list the wrong spelling.** `docs/expression.rst` documents the
   unit as `"mibibyte"`; that string does not parse (`SyntaxError: Unexpected "name"`). Reading
   `PhpBench\Util\MemoryUnit` directly shows the real constant is `mebibytes` (plural) — found
   only after the documented spelling failed at benchmark-run time, not by design.

NFR-01's *absolute* half (≤5µs) is additionally tied to a specific reference machine (NFR-06: a
Ryzen 7 5800X) and methodology (OPcache/JIT off). Roadmap item **7.1** — *"phpbench nightly CI
harness (NFR-06 methodology) with >10% regression failure"* — is explicitly the item that owns
absolute-µs enforcement against a stored baseline. Confirmed by reading its exact roadmap text
before treating it as already-covered ground.

Having built the benchmarks, the *measured* numbers were:

- `benchHydrateWarm` mode ≈ 14.07µs, `benchManualConstruction` mode ≈ 0.91µs → ratio ≈ **15.4×**
  (stable across repeated runs at different iteration/rev counts; not measurement noise).
- `benchHydrateTenThousand` mem.peak ≈ well under the 16 MiB budget (comfortable headroom, see
  Consequences).

The ratio result is a genuine gap against NFR-01's ≤3× budget — the current reflection-based
hydrator, per-call, is materially more expensive than a hypothetical compiled/cached-closure
hydrator would be. This is an architectural finding, not a defect in the benchmark or the
existing hydration tests, and closing it is a substantial redesign, not something this item's
scope ("build working benchmarks") should absorb silently.

## Decision

**Item 3.5 ships two working benchmarks that produce real numbers, plus a non-blocking ratio
tool — and does not lower any budget or wire a new gate to fail CI on the strength of a single
day's measurement.**

- `HydrationBench` (NFR-01) and `MemoryBench` (NFR-04) are added under
  `src/bench/php/d4np/utils/`, configured via `phpbench.json.dist`, sharing one fixture
  (`TenScalarPropsDto`) so both measure the same shape.
- `MemoryBench` keeps a real, enforced `@Assert('mode(variant.mem.peak) < 16 mebibytes +/- 10%')`
  — NFR-04's budget genuinely fits inside a same-run process-peak measurement, so it is asserted
  directly and will fail CI if it regresses.
- NFR-01's ratio is **not** expressed as an `@Assert` (it cannot be, per the finding above).
  Instead, `tools/bench_ratio_gate.py` reads a `phpbench run --dump-file` XML report and prints
  `numerator/denominator`, exiting non-zero if the ratio exceeds a `--max-ratio` argument. It is
  invoked standalone for now — **it is not called from CI**, so it cannot block a merge on the
  strength of this item's finding.
- The measured ~15.4× ratio is recorded here, in the roadmap note for item 3.5, and in the
  dated journal entry, honestly and without euphemism.
- **ROADMAP item 3.7 is filed** (see Consequences) to track closing the ratio gap as its own,
  separately-scoped item — likely a compiled/cached-closure hydration strategy, evaluated on its
  own merits rather than rushed to fit inside a benchmarking item.
- NFR-01's *absolute* µs ceiling and nightly regression tracking against a stored baseline
  remain item 7.1's job, unchanged by this ADR.

This was a genuine three-way trade-off with no obviously-correct default — ship non-blocking and
file follow-up work; wire a blocking gate immediately at the current measured ratio; or revise
the NFR-01 budget itself — and was put to the maintainer via `AskUserQuestion` rather than
decided unilaterally. The maintainer chose the first option.

## Alternatives Considered

- **Wire `bench_ratio_gate.py` into CI now, failing at the measured ~15.4×.** Rejected: this
  item's job was to measure, not to fix the hydrator, and merging a benchmark that immediately
  fails its own gate blocks all of Milestone 3/4/5's future PRs on work item 3.5 never scoped to
  do.
- **Loosen the ratio budget in NFR-01 to match the measured number (e.g. "≤20×").** Rejected:
  silently lowering a quality bar to make a number pass it is exactly the failure mode this
  project's discipline exists to prevent. If NFR-01's budget itself is wrong, that is a decision
  for the maintainer to make explicitly against the spec, not a side effect of a benchmarking PR.
- **Defer building the benchmarks entirely until a faster hydrator exists.** Rejected: item 3.5's
  stated job is exactly to produce the first real measurement; without it, the ~15× gap would
  still exist and simply be unknown, which is worse than known-and-non-blocking.
- **Express the ratio via two absolute `@Assert`s (`benchHydrateWarm < N µs` and
  `benchManualConstruction > M µs`) instead of a true ratio.** Rejected: this reintroduces the
  hardware-dependence item 7.1 is explicitly meant to own, and two independent absolute bounds do
  not actually constrain their ratio (both could shift together and still pass, or one could
  regress while the other improves for unrelated reasons and mask a real ratio regression).

## Consequences

- `HydrationBench` and `MemoryBench` exist, run in CI's `benchmark` job (now active — its
  step-level guard from item 1.9 checks for `phpbench.json.dist`, which this item adds), and
  produce a real number every run.
- `MemoryBench`'s `@Assert` is a genuine, enforced gate — NFR-04 is closed, not merely measured.
- NFR-01's ratio is visible (`tools/bench_ratio_gate.py`, run manually or as a future CI step)
  but does not fail a build. Anyone reading the tool's own docblock or this ADR sees the current
  ~15.4× finding and that it is a known, filed gap, not an oversight.
- **ROADMAP item 3.7** is added to Milestone 3 to close the gap — most likely via a
  compiled/cached-closure hydration strategy that trades the current per-call
  reflection/type-checking walk for a generated closure built once per class and cached
  alongside `ClassMetadata`. That item will need its own measurement-before-redesign discipline
  and, likely, its own ADR.
- The documented phpbench unit-name typo (`mibibyte` vs. the real `mebibytes`) is recorded here
  so a future contributor changing this benchmark does not lose the same time rediscovering it.

## References

- Spec NFR-01 (hydration ≤5µs / ≤3× manual), NFR-04 (≤16 MB / 10k hydrations), NFR-06 (reference
  machine and methodology for absolute measurement)
- ROADMAP item 7.1 — nightly CI harness owning absolute-µs regression tracking
- `vendor/phpbench/phpbench/docs/guides/assertions.rst`, `docs/expression.rst`,
  `docs/guides/regression-testing.rst`, `examples/Assertion/ExampleAssertionsBench.php`
- `vendor/phpbench/phpbench/lib/Executor/Benchmark/template/memory.template` (mem.peak = process
  peak, not delta)
- `vendor/phpbench/phpbench/lib/Util/MemoryUnit.php` (real unit constant: `mebibytes`)
