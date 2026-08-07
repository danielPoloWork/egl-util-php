# ADR-0046: NFR-09's budget contradicted NFR-01 on the same axis

- **Status:** Accepted
- **Date:** 2026-08-07
- **Deciders:** **maintainer (`@danielPoloWork`) — the choice between revising this budget and
  re-opening hydration optimization was explicitly theirs** (ADR-0040's rule: the spec owns its
  own numbers); agent acting as tech-lead for the derivation
- **Related:** ROADMAP item 10.10 (this decision), item 10.6 (which measured the miss and filed
  it) · spec **NFR-09** (revised here, r13), **NFR-01**, **NFR-06** ·
  [ADR-0011](0011-benchmark-scope-and-the-measured-hydration-ratio.md) (the ratio-gate mechanism
  this re-parameterizes) · [ADR-0013](0013-compile-a-hydration-closure-for-the-scalar-shape.md)
  (the compiled fast path both subjects here use) ·
  [ADR-0030](0030-same-runner-ab-because-a-stored-baseline-cannot-carry-a-10-percent-gate.md)
  (the regression gate that still protects these subjects) ·
  [ADR-0045](0045-exclude-io-bound-and-memory-hard-subjects-from-the-relative-gate.md) (the
  thin-margin lesson this budget deliberately does not repeat) ·
  [ADR-0042](0042-trim-is-the-only-default-and-the-transcode-runs-first.md) (`RowNormalizer`,
  whose overhead this measurement newly attributes)

## Context

Item 10.6 wired NFR-09's ratio gate and it failed: `TableGateway::all()` measured **1.85×** a
hand-written PDO loop against a **≤ 1.5×** budget. Item 10.6 declined to decide what to do,
filing item 10.10 with two paths — revise the budget (a spec-scope call ADR-0040 reserves for the
maintainer) or re-open hydration optimization beyond item 3.7. **The maintainer chose to revise
the budget.** This ADR records the derivation, because "the maintainer picked the easier option"
is only an acceptable record if the number that replaces it is defensible.

### The measurements

Five CI runs (`ubuntu-24.04`, the authoritative environment per ADR-0030):

| run | numerator (µs) | denominator (µs) | ratio |
|---|---|---|---|
| 31133875720 | 153.492 | 84.312 | 1.82× |
| 31134501611 | 160.591 | 86.767 | 1.85× |
| 31134934611 | 151.664 | 83.944 | 1.81× |
| 31153428964 | 150.703 | 83.382 | 1.81× |
| 31154404368 | 118.000 | 69.112 | 1.71× |

Median **1.81×**, max **1.85×**, spread **8.2%** — note the absolute times spread ~36% across the
same runs while the ratio spread only 8.2%, which is ADR-0011's argument for using a ratio,
confirmed rather than assumed.

### Where the overhead actually lives

Item 10.6's profile attributed the gap entirely to hydration. That was measured on a **single
process that ran every stage in sequence**, and re-measuring found it wrong: with each stage
isolated in its own process, `gateway->all()` measures 416 µs, but measured *last* after ~10 000
prior iterations it reads **884 µs** — a GC/ordering artifact. This is the fourth benchmark-scope
error on record here (ADR-0020, ADR-0028, ADR-0030's `--php-disable-ini`, now this), and the
correction changes the attribution:

| stage (isolated process, 100 rows) | µs | overhead vs the manual side |
|---|---|---|
| read — gateway (prepared) vs manual (`query()`) | 113.8 / 113.5 | **+0.4** (negligible) |
| normalize — `RowNormalizer` vs an inline trim loop | +99.9 / +44.1 | **+55.8** |
| build — hydration vs direct construction | +202.8 / +54.9 | **+147.9** |
| **total gap** | 416.6 / 212.5 | **+204.2** |

So hydration is **72%** of the gap, not all of it; `RowNormalizer`'s per-value object dispatch is
a real **27%**. ADR-0014's pinned real prepares — which item 10.6 named as the asymmetry NFR-09
was pricing — cost **0.4 µs**, essentially nothing. Item 10.6's fairness reasoning stands; its
cost attribution did not.

### Why the ratio could not have been 1.5×

Solving the same decomposition for the budget:

```
floor if hydration were FREE (cost == manual construction)   1.27×   ← RowNormalizer alone
ratio with hydration at NFR-01's permitted 3×                1.78×
ratio with hydration at the achieved 2.40× (item 7.1)        1.63×
measured                                                     1.71–1.85×
```

And inverted — **what 1.5× demanded**: hydration ≤ 104.9 µs, i.e. **≤ 1.91× manual construction**.

NFR-01 permits hydration at **3×** manual construction. Item 3.7 spent a `standard/high` effort
reaching **2.40×** (ADR-0013's compiled closure), and nothing has beaten it since. **NFR-09's
scope strictly contains NFR-01's** — hydration is a step inside fetch+normalize+hydrate — yet
NFR-09 demanded *stricter* overhead of that inner step than NFR-01 itself does. The two budgets
were in direct contradiction, and NFR-09 was the one that could not be satisfied.

Both subjects were checked for a missed optimization before accepting this: `GatewayRow` **does**
compile to ADR-0013's fast path (verified against `HydrationCompiler::isEligible()` — all four
parameters builtin, non-variadic, no defaults). The gap is structural, not a regression.

### The row-width effect, which the old wording missed

Measured on one machine, same conditions: `GatewayRow` (4 columns) hydrates at **3.17×** its own
manual construction, while `TenScalarPropsDto` (10 properties) hydrates at **2.98×**. Fewer
columns means less work to amortize the hydrator's fixed per-call dispatch over, so **a narrower
row is a harder ratio**, not an easier one. NFR-01 states its shape ("10 scalar props"); NFR-09
did not, and its subject sits at a harder point of that curve.

## Decision

**NFR-09's budget becomes ≤ 2.5×, and its row shape becomes part of the requirement**
(spec r13): *"fetch + normalize + hydrate 100 rows of a 4-column row DTO ≤ 2.5× a hand-written
PDO loop doing the same work."* `ci.yml`'s ratio step moves from `--max-ratio 1.5` to `2.5`.

**Why 2.5× and not another number**, stated so the figure is derived rather than rounded:

- **Above the structural ceiling.** With hydration at NFR-01's own permitted 3×, the pipeline
  ratio is 1.78×; a budget below that would re-create the contradiction this ADR exists to
  remove.
- **Above the observed maximum with real headroom.** Max observed 1.85×; 2.5× is **35% above**
  it, roughly four times the ratio's own 8.2% run-to-run spread.
- **Below NFR-01's 3×.** The shared fetch cost mathematically dilutes the ratio toward 1.0, so a
  pipeline containing hydration must be permitted *less* overhead than hydration alone. A budget
  at or above 3× would be unprincipled in the opposite direction.
- **Still catches what the budget is for.** A genuine doubling of gateway cost lands near 3.6×
  and fails. The budget's job is "is this design fundamentally too expensive", not "did this PR
  make it slower" — that second question belongs to the >10% regression gate (ADR-0030), which
  still covers both `GatewayBench` subjects; they are **not** in ADR-0045's exclusion list.

## Alternatives Considered

1. **2.0×** — rejected. Only 8% above the observed max, which is exactly the ratio's own measured
   spread. Item 9.6 already flagged a 9% margin as "the thinnest of any gated NFR in this
   project" and warned it would read as a regression when it fired; ADR-0045 then spent a whole
   item cleaning up after exactly that failure mode. Choosing it again knowingly would be
   choosing a known-flaky gate.
2. **3.0×, matching NFR-01** — rejected as unprincipled: it is only reachable if fetch and
   normalize cost nothing, which measurement says is 51% of the manual side's total.
3. **Keep 1.5× and exclude the subject from the gate** (ADR-0045's mechanism) — rejected. That
   mechanism is for subjects whose *measurement* is too noisy to gate; this subject's measurement
   is stable (8.2%). The number was wrong, not the instrument, and excluding it would leave
   NFR-09 asserting nothing while looking enforced.
4. **Re-open hydration optimization** (item 10.10's path b) — **not chosen by the maintainer**,
   and recorded as still available: even a perfect hydrator only reaches 1.27× on this shape, so
   optimization alone could not have rescued the 1.5× figure either. Both paths were needed to
   reach 1.5×, and only one of them exists.
5. **A row-width-dependent formula** — rejected as over-engineering: NFR-01 budgets one
   representative shape and so does this, now that the shape is stated.

## Consequences

**Easier:** NFR-09 is enforceable, so `master` stops carrying a permanently red required check —
which is worth more than the strictness given up, because a check that is always red is a check
nobody reads.

**Harder / accepted:** a real 35% regression in gateway overhead now passes the ratio gate. The
mitigation is structural rather than hopeful: the >10% same-runner regression gate covers these
subjects on every PR (ADR-0030), so drift is caught there — the ratio gate's remaining job is the
design-level claim, and that is the claim 2.5× now makes honestly.

**Newly attributed, not yet acted on:** `RowNormalizer` costs +55.8 µs per 100 rows against an
inline trim — 27% of the total gap, and a fixable one (its per-value dispatch is a policy object's
price, ADR-0042). Filed as roadmap item **10.11** rather than folded into a spec-revision item.

**Process note.** Item 10.6 reported "hydration is the entire gap" from a profile whose stages
shared one process. It was directionally right and quantitatively wrong, and only a re-measurement
in isolated processes surfaced the 884-vs-416 µs artifact. A benchmark decomposition is a
benchmark, and gets the same scepticism as one.

## References

- Spec NFR-09 (r13), NFR-01, NFR-06 · ROADMAP items 10.6, 10.10, 10.11
- ADR-0011 (ratio mechanism), ADR-0013 (compiled hydration), ADR-0030 (regression gate),
  ADR-0042 (`RowNormalizer`), ADR-0045 (thin margins), ADR-0040 (the spec owns its own numbers)
- CI runs 31133875720, 31134501611, 31134934611, 31153428964, 31154404368
