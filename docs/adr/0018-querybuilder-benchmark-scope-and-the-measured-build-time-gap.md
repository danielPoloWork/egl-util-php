# ADR-0018: QueryBuilder's benchmark measures NFR-03 now; the ~23µs build-time gap is deliberately deferred

- **Status:** Accepted, **partially corrected by [ADR-0020](0020-correct-the-nfr03-workload-and-resolve-the-driver-once.md)**
  — its central measurement (~23 µs) was taken on a workload heavier than NFR-03 specifies.
  The reasoning about *how to handle* a measured gap stands; the number does not.
- **Date:** 2026-08-04
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 4.5 (opens the follow-up, item 4.6) · spec NFR-03, NFR-06 ·
  [ADR-0011](0011-benchmark-scope-and-the-measured-hydration-ratio.md) (the precedent this follows)
  · [ADR-0015](0015-identifier-allowlist-closed-keywords-and-the-anchor-hole.md) (the allowlist and
  immutability whose cost this measures) · **Benchmark record:**
  [`docs/benchmarks/2026/08/nfr03-querybuilder-build-time.md`](../benchmarks/2026/08/nfr03-querybuilder-build-time.md)

## Context

Spec NFR-03: *"QueryBuilder: 5-condition SELECT builds in ≤ 10 µs; 0 queries executed at build
time."* Two independent claims in one sentence, needing two different kinds of evidence: a
benchmark for the first, and a direct assertion — not a timing — for the second, since a fast
benchmark cannot prove an absence any more than a slow one could.

Measured before deciding anything: `QueryBuilderBench::benchBuildFiveConditionSelect` (5 `select()`
columns, 5 `WHERE`-family conditions, `orderBy`, `limit`/`offset`, then `toSql()` + `bindings()`)
came in at **~23–24 µs across two independent runs** — roughly 2.3× over the ≤ 10 µs budget.

A standalone probe attributed the cost rather than guessing at it:

| operation | µs |
|---|---|
| `DatabaseConnection::driver()` alone | 0.20 |
| constructor (1 identifier) | 0.95 |
| constructor + `select()` of 5 columns | 5.43 |
| full benchmarked build (12 identifiers total) | 17.6 |

The cost scales at roughly **1 µs per identifier quoted** (a driver lookup, the allowlist regex,
quote-and-double per ADR-0015), plus a `clone $this` per fluent call from the immutability
ADR-0015 also committed to. A 12-identifier, 8-call query has more of both than a 10 µs budget
leaves room for. Neither cost is a defect — both are the direct, intended consequence of decisions
already made and recorded — which is what makes this a genuine trade-off rather than a bug to fix
reflexively.

This is structurally the same situation item 3.5 found for NFR-01 (measured ~15.4× against a ≤3×
budget). ADR-0011 set the precedent for handling it: measure honestly, ship non-blocking, file a
scoped follow-up, and do not touch the budget or the code under a benchmarking item's own route.

## Decision

**Ship the benchmark and the zero-queries assertion; report the ~23 µs measurement honestly; do
not attempt to close the gap under this item; file the follow-up as roadmap item 4.6.**

- `QueryBuilderBench` measures the timing half. `get()`/`first()` are never called, since either
  would execute a real statement and conflate build cost with I/O — the exact distinction NFR-03's
  own wording draws.
- The zero-queries half is a **direct assertion**, not a benchmark: `QueryBuilderTest::
  testBuildingNeverRunsAQuery()` installs the `QueryLog`/`LoggedStatement` fixture item 4.4 built
  for T-02 and asserts it stays empty across the identical call sequence the benchmark times.
  Verified non-vacuous by planting an accidental query call inside `toSql()` and confirming the
  test catches it.
- The ~23 µs figure is **not** wired to fail CI. Like NFR-01's absolute half, it is tied to spec
  NFR-06's reference machine (a Ryzen 7 5800X) far more tightly than a measurement on this
  developer machine reflects, and establishing a real baseline plus regression gating against it
  is roadmap item **7.1**'s job — unchanged by this ADR, and not duplicated here.
- **Item 4.6 is filed** to investigate closing the gap: most plausibly by caching the `driver()`
  value the constructor already resolves once (removing the repeated `PDO::getAttribute()` call
  currently paid per identifier quoted) rather than anything that would touch the allowlist or the
  immutability guarantee. That investigation needs its own measure-first pass, exactly as item 3.7
  did for NFR-01, and is out of scope for a benchmark item routed `fast / medium`.

This was put to the maintainer as a three-way choice — ship non-blocking and file follow-up work;
attempt a fix inline under this item's route; or revisit NFR-03's own budget — mirroring the
ADR-0011 precedent exactly. The maintainer chose the same option chosen there.

## Alternatives Considered

- **Fix it inline under item 4.5** — rejected: this item's route (`fast / medium`) reflects a
  benchmark-authoring task, not a design change to a security-relevant class (ADR-0015). A fix
  attempted here would be reviewed under the wrong item's scrutiny, and — as with NFR-01 — the
  right fix likely needs its own measure-first discipline rather than a same-session patch.
- **Lower NFR-03's budget to match today's number** — rejected on the same reasoning ADR-0011
  gave: silently loosening a bar to make a number pass it is exactly the failure mode this
  project's discipline exists to prevent.
- **Skip the benchmark until the gap is closed** — rejected: item 4.5's job is to produce the
  first real measurement. Without it the gap would still exist and simply be unmeasured, which is
  worse than measured-and-open.
- **Prove "0 queries" by code inspection instead of a fixture assertion** — rejected: item 4.4
  already established, for the binding half of T-02, that inspection-based confidence
  (round-trip tests) misses what a direct instrumented assertion catches. The same reasoning
  applies here — a future change that accidentally called `get()` during a builder method would
  not be caught by reading the source, but is caught by the log staying empty.

## Consequences

- `QueryBuilderBench` runs in CI's `benchmark` job and produces a real, reproducible number every
  run.
- `testBuildingNeverRunsAQuery` is a real, enforced gate — the "0 queries" half of NFR-03 is
  closed, not merely measured, mirroring how MemoryBench's NFR-04 assertion was handled in item 3.5.
- The ~23 µs timing gap is visible in the benchmark record and this ADR, with its cause attributed
  (per-identifier quoting, per-call cloning) rather than left as an unexplained number.
- **Roadmap item 4.6** exists to close it, scoped separately with its own measurement discipline —
  the same relationship NFR-01's item 3.7 had to item 3.5.
- Item 4.5's local benchmark run needed a Windows-specific workaround
  (`--php-disable-ini` + `--php-config` loading only `pdo_sqlite`) distinct from item 3.5's, because
  this benchmark — unlike the DTO ones — genuinely needs an extension loaded. Not carried into CI,
  which runs the plain command on a clean Linux environment.

## References

- Spec NFR-03 (the budget, both clauses), NFR-06 (reference machine and methodology)
- ADR-0011 — the precedent this ADR follows exactly: measure, ship non-blocking, file a scoped
  follow-up, defer absolute enforcement to item 7.1
- ADR-0015 — the allowlist and immutability whose combined cost this benchmark measures
- Benchmark record: `docs/benchmarks/2026/08/nfr03-querybuilder-build-time.md`
