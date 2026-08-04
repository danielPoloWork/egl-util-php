# ADR-0013: Compile a hydration closure for the scalar shape, keep the interpreter for everything else

- **Status:** Accepted
- **Date:** 2026-08-04
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 3.7 (filed by item 3.5) · spec NFR-01, NFR-06 ·
  [RFC-0001](../rfc/0001-egl-utils-library.md) ·
  [ADR-0011](0011-benchmark-scope-and-the-measured-hydration-ratio.md) (which measured the gap and
  deliberately deferred closing it) · [ADR-0008](0008-dto-hydration-strictness-and-shared-hydrator.md) ·
  [ADR-0006](0006-shared-reflection-metadata-cache.md) ·
  **Benchmark record:** [`docs/benchmarks/2026/08/nfr01-hydration-compiled-closure.md`](../benchmarks/2026/08/nfr01-hydration-compiled-closure.md)

## Context

Spec NFR-01 budgets DTO hydration (10 scalar props, warm) at **≤ 5 µs** and **≤ 3× manual
constructor assignment**. Item 3.5 measured it for the first time and found **15.40×** — five times
over budget. ADR-0011 recorded that honestly, shipped the benchmark non-blocking, and filed item
3.7 to close the gap rather than lowering the budget or blocking CI on a number nobody had yet
tried to fix.

Item 3.7 opened by measuring four approaches before writing production code, because the useful
question was not "is the hydrator slow" but "**what is actually reachable**":

| approach | µs/op | ratio | meets ≤3×? |
|---|---|---|---|
| interpreted (the hydrator as it was) | 13.86 | 16.6× | ✗ |
| interpreted, every avoidable waste removed | 3.97 | 4.80× | ✗ |
| one pre-built closure per parameter (no `eval`) | 3.31 | 4.00× | ✗ |
| **generated closure** | **1.93** | **2.28×** | ✓ |

The second row matters most. It is the ceiling of "just make the existing loop tighter": memoise
`parameterNames()` (rebuilt on *every* call today), replace the `in_array` unknown-key scan with a
hash lookup, drop named-argument spread for positional, build the error path lazily. All of that
together reaches 4.80× — and that prototype was **optimistic**, carrying no path building, no
exception construction and none of the nested-DTO branches production needs. **NFR-01's budget is
not reachable by tuning an interpreted reflection-driven loop in PHP.** That is a measured
conclusion, and it is what justifies a mechanism as heavy as code generation.

Profiling also produced two results that contradicted plausible intuitions, both worth recording
because acting on either would have made things worse:

- Iterating `ParameterMetadata` **objects** is *faster* than iterating flattened plain arrays
  (0.63 µs vs 1.59 µs per pass). "Flatten the metadata into arrays" is a pessimisation.
- Named-argument spread (`new $c(...$named)`) costs roughly **twice** a positional call
  (1.49 µs vs 0.80 µs).

## Decision

**Generate a per-class hydration closure for the narrow shape NFR-01 names, and keep the existing
interpreter as the implementation for everything else.**

- **Eligibility is deliberately small**: every constructor parameter a non-variadic **builtin
  scalar** (`int`, `float`, `string`, `bool`) with **no declared default**. Nested DTOs,
  `Collection`, enums, unions, `mixed`, `array`, variadics and defaults are **not compiled** and
  behave exactly as before.
- **Nullable parameters are eligible.** RFC-0001 R-4 passes `null` explicitly for a
  nullable-without-default parameter, so its argument is always present and no argument position
  shifts.
- **Defaulted parameters are not eligible**, because the generated call passes arguments
  positionally — which is where the win comes from — and a positional call cannot skip an
  argument. Generating branching to reconstruct named-argument semantics would buy back the cost
  it saves *and* give the fast path a second way to disagree with the interpreter.
- **`HydrationCompiler` takes an `enabled` flag.** Disabled, it declines every class and the
  library runs entirely on the interpreter. This is what `HydrationParityTest` uses to run both
  paths, and it is also the supported escape hatch for anyone who would rather their dependencies
  did not evaluate generated source in their process.
- **The two paths are held together by `HydrationParityTest`**, which runs every case down *both*
  and compares the outcomes with each other — constructed state, exception class, message and
  path — rather than against hand-written expectations that would only prove the compiled path
  matches what this ADR's author believed.

**Result: 15.40× → 2.74×** (2.511 µs), meeting both halves of NFR-01, with the full suite green.

### The cost, quantified rather than waved at

`eval()`'d code is not cached by OPcache, so the closure is built once per class **per process**,
not once per deployment:

| | |
|---|---|
| compile cost | 66.9 µs per class per process |
| saving | 12.3 µs per hydration |
| **break-even** | **≈5.5 hydrations of one class per process** |

Any bulk mapping (an API page, a result set) clears that immediately; a process that hydrates one
or two objects of a class and exits is marginally *slower* than before. That trade was measured
and accepted, not assumed away.

## Alternatives Considered

- **Tighten the interpreted loop and accept ~4.8×** — rejected as the *primary* answer because it
  does not meet the spec's budget, which was the whole point of item 3.7. Offered explicitly to the
  maintainer alongside the compiled option; not chosen.
- **Revise NFR-01's budget to a number an interpreted hydrator can meet (~5×)** — also offered and
  not chosen. It remains the honest fallback if the compiled path is ever removed, and the
  measurements in the benchmark record are what such a revision would be argued from.
- **Closure composition (one pre-built closure per parameter), avoiding `eval` entirely** —
  measured at 4.00×. Rejected on the number: it keeps a call per parameter, which is most of what
  makes the interpreted loop slow.
- **Write generated code to a cache file and `include` it** (OPcache-friendly, no `eval`) —
  rejected: it makes a general-purpose utility library require a writable cache directory and a
  cache-invalidation story, which is a substantially larger operational imposition on every
  consumer than an in-process `eval` whose cost is bounded and measured.
- **Compiling the full semantics** (nested DTOs, collections, enums, unions) — rejected for now.
  The value of a second implementation is inversely proportional to its size: a small compiled path
  can be checked for equivalence case by case, and a complete one effectively cannot.
- **Making the fast path implicit and undocumented** — rejected. A DTO with a nested property is
  still ~14 µs, and a reader who sees "hydration is 2.7× manual" without that qualification has
  been misled. It is stated in the class docblock, in the benchmark record, and here.

## Consequences

- **NFR-01 is met** for the shape it specifies: 2.74× (budget ≤ 3×) and 2.511 µs (budget ≤ 5 µs),
  stable across independent runs (2.74× / 2.75×), recorded with full environment in the benchmark
  report. `tools/bench_ratio_gate.py` exits 0 against `--max-ratio 3` for the first time.
- **NFR-04 improved as a side effect**: 10 000 hydrations went 149.7 ms → 37.8 ms, its 16 MiB peak
  assertion still passing, without that budget being touched.
- **The library now contains a code generator, and `eval()`.** That is a real increase in what a
  reader must understand and what a security review will ask about. The mitigations are the narrow
  eligibility, the parity suite, and the documented off switch — not a claim that the cost is zero.
- **Two implementations of one behavior now exist and could drift.** `HydrationParityTest` is the
  control, and it was verified non-vacuous by planting three divergences (dropping `float`'s
  int-widening; moving the unknown-key scan after the parameter checks so a payload that is both
  missing a key and carrying an unknown one reports the wrong one; and making nothing eligible at
  all) and confirming each was caught — the last of these is what proves the suite is not silently
  comparing the interpreter with itself.
- **Only the eligible shape is fast.** Nested/collection/enum DTOs remain on the interpreter at
  roughly their previous cost. Extending compilation to them is possible and is deliberately not
  attempted here.
- **A hardened environment that forbids `eval` can turn it off** via `new HydrationCompiler(false)`
  and lose only speed.

## References

- Spec NFR-01 (the budget), NFR-06 (benchmark methodology and reference machine)
- Benchmark record: `docs/benchmarks/2026/08/nfr01-hydration-compiled-closure.md` — before/after
  with environment, the four measured alternatives, and the break-even analysis
- ADR-0011 — measured the gap, deferred closing it, filed item 3.7
- `HydrationParityTest` — the equivalence control, and the three planted divergences that show it works
