# 2026-08-07 — Two subjects, one honest miss

Roadmap item **11.5**, spec **NFR-11**. Route `fast / medium`; run at Sonnet 5, the routed tier.
The item that was supposed to close Milestone 11 measured two subjects and closed neither
budget question — one passed with room to spare, the other did not pass at all.

## Two scoping questions before any number existed

`Router` has no cache and no index — ADR-0050's stated non-goal — so dispatch is a linear scan
that tries each compiled pattern until one matches. That has a real best case and a real worst
case, and the first question was which one NFR-11's ≤5µs budgets: the **last** of 50 registered
routes, the worst case the scan produces, is what a regression in per-pattern cost would show
first. The first route is kept alongside it, unbudgeted, so the best case is not silently
dropped — the same discipline item 4.5's `QueryBuilderBench` established for a heavier,
unbudgeted shape kept beside its own budgeted one.

The second question was smaller but had the same wrong-by-default answer: `ApiEnvelope::ok()`
construction is the budgeted subject, never `jsonSerialize()`. NFR-11 budgets building the
object a handler hands to `Response`; serialization is that class's job, later, and unbudgeted.
Both decisions are **ADR-0053**.

## What the measurement said

On this PR's own CI run (`ubuntu-24.04`, not spec NFR-06's named reference machine):

| Subject | Measured | Budget | |
|---|---|---|---|
| `benchEnvelopeBuild` | 0.366–0.395 µs (mean 0.381) | ≤ 2 µs | met, 5×+ headroom |
| `benchEnvelopeBuildWithMessages` | 0.354–0.368 µs | unbudgeted | — |
| `benchDispatchFirstOfFiftyRoutes` | 0.901–0.929 µs | unbudgeted | — |
| `benchDispatchLastOfFiftyRoutes` | 6.874–7.145 µs (mean 6.984) | ≤ 5 µs | **not met, ~40% over** |

The envelope half is not close to its ceiling; the router half is not close to being met. The
gap between first-route and last-route dispatch — roughly 7.6× — is the 49 failed
`preg_match()` attempts a worst-case lookup pays in a router with no index, which is exactly the
mechanism ADR-0050 named when it decided a cache was not worth building. That decision assumed
"a 50-route table matches in microseconds" without a number behind it; this item supplied the
number, and it does not support the assumption at the worst case.

## What did not happen

The benchmark was not narrowed until it passed, and the CI gate was not softened to hide the
miss. `bench_budget_gate.py --budget benchDispatchLastOfFiftyRoutes=5` is wired into both
`ci.yml` and `nightly.yml` exactly as NFR-11 states it, and it fails, honestly, until the
maintainer decides something. Items 3.5→3.7 and 10.6→10.10 set this precedent — a measurement
that fails the spec's own number gets shipped as measured and filed as a decision, not quietly
adjusted by whoever is holding the benchmark at the time.

Filed as item **11.7**, not decided here: raise the budget, add the index ADR-0050 declined, or
ship the gate red until one of those happens. ADR-0040 reserves the number itself for the
maintainer.

## Where this leaves Milestone 11

Two decision items now sit open — 11.6 (a noisy subject that may not be a real regression at
all) and 11.7 (a real, bounded cost with a known cause) — and `consistency_lint`'s own
`milestones` check is what keeps README honest about it: a milestone cannot be marked done while
any of its items are unchecked, so the README row stays "planned" structurally, not by a
judgment call this session made.

## Lesson

A benchmark item's job is to produce the number, not to make the number acceptable. Two of this
item's four subjects came in comfortably under budget and two did not, and shipping all four
honestly — including the one that fails the gate — is what item 3.5 established as this
project's answer to a benchmark that disagrees with its own spec.
