# ADR-0020: Correct NFR-03's benchmarked workload, resolve the driver once, and defer the residual to the reference machine

- **Status:** Accepted
- **Date:** 2026-08-04
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 4.6 · spec NFR-03, NFR-06 · item 7.1 (the reference-machine harness) ·
  **corrects** [ADR-0018](0018-querybuilder-benchmark-scope-and-the-measured-build-time-gap.md) ·
  [ADR-0015](0015-identifier-allowlist-closed-keywords-and-the-anchor-hole.md) (whose allowlist and
  immutability are the structural cost measured here) · **Benchmark record:**
  [`docs/benchmarks/2026/08/nfr03-querybuilder-build-time-corrected.md`](../benchmarks/2026/08/nfr03-querybuilder-build-time-corrected.md)

## Context

Item 4.6 was filed by item 4.5 on this premise: *"a 5-condition `QueryBuilder` SELECT currently
measures ~23 µs against a ≤10 µs budget"*, with a named hypothesis — that the cost was the
repeated `PDO::getAttribute()` call in `quote()`.

**Both halves of that premise turned out to be wrong**, and profiling before changing anything is
what found it.

**The hypothesis was wrong about magnitude.** `DatabaseConnection::driver()` measures 0.125–0.213 µs.
Across twelve identifiers that is ~1.5–2.5 µs of ~23 µs — real, but nowhere near the gap. The cost
is distributed across identifier validation, quoting, `sprintf`, per-call cloning and method
dispatch, not concentrated anywhere.

**The premise was wrong about the workload, and that is the important finding.** NFR-03 budgets a
*"5-condition SELECT"*. Item 4.5's subject built five `WHERE`-family conditions **plus** a
five-column `select()` list, an `orderBy`, a `limit` and an `offset` — twelve quoted identifiers
where the NFR names five conditions. Its own docblock claimed to count *"exactly five calls that
each contribute one condition"*, so the documentation and the code disagreed and nobody noticed,
including the author. Measured separately:

| workload | µs |
|---|---|
| literal NFR-03 — `SELECT *` + 5 `WHERE` conditions | **14.43** |
| item 4.5's subject (+ 5-column select, order, limit, offset) | 24.69 |

**Roughly two thirds of the reported gap was benchmark scope, not builder cost.**

## Decision

### 1. Split the subject; keep both, and keep the heavier one visible

`benchBuildFiveConditionSelect` is now `SELECT *` with exactly five `WHERE` conditions — the shape
the budget applies to. `benchBuildRealisticPagedQuery` keeps item 4.5's heavier shape, asserting
nothing.

Keeping the heavier subject is deliberate and is the safeguard against the obvious objection to
this ADR: **narrowing a benchmark until it improves is exactly how a metric gets gamed.** The
defence is that nothing was deleted — the heavier number is still measured, still published, still
worse — and that the narrowing follows the spec's wording rather than the direction of a nicer
result. A column list is not a condition.

### 2. Resolve the driver's quote characters once per builder

The driver cannot change during a builder's life, so `quote()` no longer asks per identifier.
Resolved in the constructor, carried across clones for free.

**Pinned by counting, not by timing.** The saving (~0.13 µs per identifier) is inside end-to-end
noise, but the count is exact and machine-independent: 7 → 1 lookups for the five-condition query,
13 → 1 for the realistic one. `testTheDriverIsResolvedOncePerBuilderRegardlessOfChainLength()` fails
with `13 is not identical to 1` if the lookup returns to `quote()` — where a timing assertion for a
sub-microsecond saving would be flaky and would teach people to ignore it.

### 3. Concatenation instead of `sprintf()` on the per-condition paths

Measured at roughly a third the cost (0.080 µs vs 0.259 µs), executed once per condition, per
`orderBy`, and in `toSql()`.

### 4. **NFR-03 is still not met, and the residual is deferred to item 7.1 — for a stated reason**

After both changes: **12.979 µs against ≤ 10 µs**, about 30% over (versus the 2.3× item 4.5
reported). The budget is *not* met and this ADR does not claim otherwise.

Further micro-optimisation is deferred, because **the two instruments disagree by more than the
remaining overage**:

| instrument | five-condition SELECT |
|---|---|
| phpbench (the project's declared harness) | **12.979 µs** |
| a plain in-process loop, median of 7 rounds | **9.246 µs** |

An empty phpbench subject costs 0.079 µs, so this is *not* harness overhead — checked, because
that was the first hypothesis. The ~3.8 µs disagreement exceeds the ~3.0 µs by which the budget is
missed, which means **whether NFR-03 passes today depends on which instrument is used.** That makes
the next question a methodology question before it is an optimisation one, and spec NFR-06's
methodology and reference machine (a Ryzen 7 5800X, not this i7) are implemented by roadmap item
**7.1**. Optimising further against an instrument that may not be the authoritative one risks
tuning the measurement rather than the program.

What remains is also increasingly structural: ~2.6 µs of identifier validation and quoting, and
~1.5 µs of the six `clone`s immutability requires — roughly 4 µs that is the direct cost of
ADR-0015's two recorded decisions and is not available without revisiting them.

## Alternatives Considered

- **Optimise until the phpbench number reaches ≤ 10 µs** — rejected on the instrument
  disagreement above: it would mean chasing ~3 µs against a measurement that differs from an
  in-process one by ~3.8 µs, i.e. tuning to the harness.
- **Report the in-process figure (9.25 µs) and declare NFR-03 met** — rejected, and it is the more
  tempting error. phpbench is the project's declared instrument (NFR-06, item 3.5's precedent, the
  CI job), and switching instruments because the other one gives a passing number is precisely the
  behaviour every previous ADR in this project has refused.
- **Quietly fix the subject without flagging item 4.5's error** — rejected. The wrong number is
  published in a benchmark record, an ADR, a changelog entry and a merged PR body; correcting it
  silently would leave four artefacts disagreeing with reality.
- **Delete the heavier subject now that it is out of NFR-03's scope** — rejected: it is what an
  application actually builds, and removing the number that motivated the item would make this
  change indistinguishable from gaming the benchmark.
- **Cache quoted identifiers** (the same column is often quoted twice) — not attempted; it adds
  per-builder state for a saving smaller than the instrument disagreement, and would be measured
  against the wrong baseline until item 7.1 lands.
- **Revisit NFR-03's number** — premature for the same reason: the authoritative measurement does
  not exist yet.

## Consequences

- The subject NFR-03's budget is compared against now matches NFR-03's wording, and the heavier
  shape remains measured and published alongside it.
- **14.430 → 12.979 µs** (−10.1%) on the corrected subject; **24.690 → 22.168 µs** (−10.2%) on the
  realistic one. Full suite green throughout; no behaviour changed.
- The driver-lookup property is regression-proof by an exact count rather than a flaky timing
  assertion — a pattern worth reusing wherever a saving is real but sub-noise.
- ADR-0018 is marked as partially corrected, and item 4.5's benchmark report carries a banner
  pointing here. Its *reasoning* about handling a measured gap stands; its central number does not.
- **NFR-03 remains unmet**, by ~30% on the declared instrument, with the residual explicitly
  parked on item 7.1 rather than left implicit. That is a smaller and better-understood gap than
  the one item 4.6 was filed with, but it is still open, and the roadmap says so.

## References

- Spec NFR-03 (the budget and its exact wording), NFR-06 (methodology, reference machine)
- ADR-0018 — the decision this corrects; its handling-of-a-gap reasoning is unaffected
- ADR-0015 — the allowlist and immutability that account for ~4 µs of what remains
- Benchmark record: `docs/benchmarks/2026/08/nfr03-querybuilder-build-time-corrected.md`
