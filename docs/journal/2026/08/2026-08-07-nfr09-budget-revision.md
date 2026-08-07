# 2026-08-07 — Two budgets that could not both be met

Roadmap item **10.10**, closing Milestone 10's decision backlog. Route `standard / medium (adr)`;
session model Opus 5 — **route matched for the first time in this milestone**, after five
consecutive items run at a lower tier than routed. The maintainer switched models deliberately
before giving the decision, which is model authority used the way ADR-0017 intends.

## The decision was the maintainer's; the derivation was mine

Item 10.6 measured NFR-09 at 1.85× against a 1.5× budget and refused to decide what to do about
it, filing item 10.10 with two paths: revise the budget, or re-open hydration optimization. That
refusal was right — ADR-0040 says the spec owns its own numbers, and an agent quietly widening a
budget it just failed to meet is the worst possible version of this. The maintainer chose to
revise.

That makes the derivation the thing that has to be defensible. "The maintainer said I could" is
not a reason for 2.5× rather than 2.0× or 3.0×.

## The contradiction, quantified

The spec contained two budgets on the same axis — *library overhead versus a hand-written
equivalent* — over **nested scopes**:

- **NFR-01**: hydration ≤ **3×** manual construction.
- **NFR-09**: fetch + normalize + **hydrate** ≤ **1.5×** a hand-written loop.

NFR-09's scope strictly contains NFR-01's. Solving the decomposition for what 1.5× actually
demanded of the inner step: **hydration ≤ 1.91× manual construction** — stricter than the 3× the
spec grants it in its own right, and stricter than the **2.40×** that item 3.7 spent a
`standard/high` effort achieving and nothing has beaten since.

The two requirements could not both be satisfied. NFR-09 was the one that had to move.

## What the number is, and why not a rounder one

Five CI runs: **1.71 / 1.81 / 1.81 / 1.82 / 1.85×**. Worth noting on its own — the absolute times
across those runs spread ~36% while the ratio spread **8.2%**, which is ADR-0011's whole argument
for using a ratio, observed rather than assumed.

Structural landmarks from the decomposition:

```
1.27×  floor if hydration were FREE — this is RowNormalizer's overhead alone
1.63×  with hydration at the achieved 2.40×
1.78×  with hydration at NFR-01's permitted 3×      <- the honest structural ceiling
1.85×  observed maximum
2.50×  chosen
3.00×  NFR-01's own figure — unreachable here unless fetch cost nothing
```

2.5× sits above the structural ceiling, 35% above the observed maximum (about four times the
ratio's own spread), and below 3× because a containing scope with shared cost must be permitted
*less* overhead than the step it contains. **2.0× was rejected specifically**: it would sit 8%
above the observed max — exactly the measured spread — and item 9.6 already flagged a 9% margin
as the thinnest gated NFR here, with ADR-0045 then spending a whole item cleaning up after that
failure mode two days later. Choosing it again would have been choosing a gate I already knew
would flake.

## Two things item 10.6 got wrong, found by re-measuring

**The 884 µs that was really 416 µs.** Item 10.6's profile ran every stage in one process, in
sequence. Re-run with each stage isolated in its own process, `gateway->all()` measures 416 µs;
measured *last*, after ~10 000 prior iterations had churned memory, the same call reads **884 µs**.
A GC/ordering artifact, and **the fourth benchmark-scope error on this project's record** —
ADR-0020's wrong workload, ADR-0028's autoload inside the subject, ADR-0030's `--php-disable-ini`,
now this. A benchmark decomposition is itself a benchmark and deserves the same suspicion.

**"Hydration is the entire gap" was 72% true.** Isolated, of 204 µs of overhead: hydration
**+147.9 µs (72%)**, `RowNormalizer`'s per-value object dispatch **+55.8 µs (27%)**, and the
prepared-statement asymmetry item 10.6 built its fairness argument around — **+0.4 µs**, nothing
at all. The fairness reasoning stands (a hand-written literal `SELECT` genuinely would not
`prepare()`); its cost was simply never the point. The `RowNormalizer` share is real, fixable, and
now filed as item **10.11** rather than left inside a paragraph.

Before accepting any of it as structural, I checked the obvious escape: `GatewayRow` **does**
compile to ADR-0013's fast path. The gap is not a missed optimization.

## The row width belongs in the requirement

Measured on one machine: `GatewayRow` (4 columns) hydrates at **3.17×** its own manual
construction; `TenScalarPropsDto` (10 properties) manages **2.98×**. Fewer columns means less work
to amortize the hydrator's fixed per-call dispatch over — **narrow rows are the harder case**.
NFR-01 has always named its shape ("10 scalar props"); NFR-09 named a row count but not a width,
so its subject sat at a harder point of the curve than its budget's sibling, invisibly. r13 writes
the shape in.

## Lesson

When two requirements constrain nested scopes on the same axis, check that the outer one is
satisfiable *given* the inner one's own allowance — before either is written, ideally, but
certainly before spending an item trying to meet the outer one. NFR-09 was unmeetable from the day
it was drafted, and three items (10.6, 10.10, and part of 10.9's CI ordering trouble) went into
discovering arithmetic that a single division would have shown at spec time.
