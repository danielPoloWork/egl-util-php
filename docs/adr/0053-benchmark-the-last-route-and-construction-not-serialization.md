# ADR-0053: Benchmark the last route, and construction, not serialization

- **Status:** Accepted
- **Date:** 2026-08-07
- **Deciders:** tech-lead (agent-drafted), maintainer (merge)
- **Related:** ROADMAP item **11.5** (step:optimize) · spec **NFR-11** ·
  [ADR-0011](0011-benchmark-scope-and-the-measured-hydration-ratio.md),
  [ADR-0018](0018-querybuilder-benchmark-scope-and-the-measured-build-time-gap.md) (the same
  workload-scoping question, asked twice before) · [ADR-0050](0050-classify-the-miss-and-keep-the-router-a-table.md)
  (`Router`), [ADR-0051](0051-one-envelope-shape-and-a-reference-instead-of-the-exception.md)
  (`ApiEnvelope`)

## Context

NFR-11 sets two ceilings: `Router` dispatch against a 50-route table at ≤ 5 µs, and
`ApiEnvelope` construction at ≤ 2 µs. Writing `HttpBench.php` (`ContainerBench`'s and
`QueryBuilderBench`'s shape — a `phpbench` class under `src/bench/`, wired into
`bench_budget_gate.py`'s absolute-ceiling CI step) meant deciding exactly what each subject
measures, and two questions had wrong-by-default answers, the same way item 4.5's did (ADR-0018:
a query builder subject that quietly measured three times NFR-03's named workload).

**Which route, in a 50-route table?** `Router` has no cache and no index (ADR-0050's stated
non-goal) — dispatch is a linear scan over compiled patterns, trying each until one matches.
That shape has a best case and a worst case that differ by construction: the first route
registered matches on the first `preg_match()`, the last route matches only after 49 failed
attempts. A benchmark that dispatched the first route would report the cost of *one* pattern
check, not of the table NFR-11 names.

**Which envelope operation?** `ApiEnvelope::jsonSerialize()` exists and is cheap to call in the
same benchmark as `ok()`. But NFR-11 budgets *construction* — the object a handler builds before
a response is sent — and serialization is `Response`'s job, later and unbudgeted. Measuring
through `jsonSerialize()` would report both costs as one, and a change to either would look like
a change to both.

## Decision

**`benchDispatchLastOfFiftyRoutes` dispatches the last of the 50 registered routes** — the worst
case a linear scan produces, and therefore the case a regression in per-pattern cost would show
first. `benchDispatchFirstOfFiftyRoutes` is kept alongside it, unbudgeted, so the best case is
not silently dropped; the gap between the two is itself a fact about the table, not noise.

**`benchEnvelopeBuild` calls `ApiEnvelope::ok()` and nothing after it.** No `jsonSerialize()`,
no `Response` construction. `benchEnvelopeBuildWithMessages` measures the variadic-message path
(`invalid()` with three strings) alongside it, unbudgeted, for the same reason the second router
subject exists: the single-outcome figure is not the only shape a handler builds, and dropping
the other silently would be the same narrowing ADR-0018 corrected.

Both ceilings carry the caveat every benchmark in this file's neighbours documents (ADR-0011,
ADR-0018): tied to spec NFR-06's reference machine and methodology, and enforced in CI as an
absolute budget per ADR-0030's finding that the headroom these NFRs carry dwarfs cross-runner
noise — not asserted as a hard requirement independent of that measurement.

## Alternatives Considered

1. **Dispatch a route near the middle of the table.** Rejected: it answers a question nobody
   asked — "the typical case" is not what a linear scan's worst-case cost analysis needs, and a
   number that is neither the floor nor the ceiling explains nothing about either.
2. **Measure `jsonSerialize()` together with construction, as one "build a response" number.**
   Rejected for the reason ADR-0018 already established: a benchmark that reports two costs as
   one cannot tell a reader which one moved when the number changes.
3. **A single-route table**, avoiding the best/worst-case question entirely. Rejected: NFR-11
   names 50 routes specifically, and a router with no index is exactly the shape where table
   size is the variable that matters.

## Consequences

**Easier:** a regression in `Router`'s per-pattern cost is caught at the point that shows it
first; a change to `ApiEnvelope`'s allocation is never confused with a change to its
serialization.

**Harder / accepted:** four subjects instead of two — the unbudgeted best-case and
multi-message variants exist only to keep this decision honest, per ADR-0018's precedent, and
carry no ceiling of their own.

**If NFR-11's dispatch budget is not met** on the reference measurement, the response follows
item 3.5/3.7's and item 4.5/4.6's precedent exactly: report the real number, ship it
non-blocking, and file the gap as its own roadmap item rather than narrow the benchmark until it
passes.

## References

- ROADMAP item **11.5** (step:optimize)
- spec **NFR-11**
- [ADR-0011](0011-benchmark-scope-and-the-measured-hydration-ratio.md), [ADR-0018](0018-querybuilder-benchmark-scope-and-the-measured-build-time-gap.md) (the same workload-scoping question, asked twice before)
- [ADR-0050](0050-classify-the-miss-and-keep-the-router-a-table.md) (`Router`), [ADR-0051](0051-one-envelope-shape-and-a-reference-instead-of-the-exception.md) (`ApiEnvelope`)
