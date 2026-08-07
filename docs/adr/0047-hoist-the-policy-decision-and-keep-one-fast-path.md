# ADR-0047: Hoist the policy decision out of the loop, and keep exactly one fast path

- **Status:** Accepted
- **Date:** 2026-08-07
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item **10.11** (`step:optimize`) · spec §3 **NFR-09** (the containing
  budget) · [ADR-0042](0042-trim-is-the-only-default-and-the-transcode-runs-first.md) (the policy
  this preserves — **amended by annotation, not edited**) ·
  [ADR-0046](0046-nfr09s-budget-contradicted-nfr01-on-the-same-axis.md) (item 10.10, which
  attributed this cost) ·
  [ADR-0030](0030-same-runner-ab-because-a-stored-baseline-cannot-carry-a-10-percent-gate.md)
  (the harness the new subjects join) ·
  [ADR-0040](0040-install-the-mutation-tester-outside-the-graph-and-keep-nfr07s-own-number.md)
  (the spec owns its own numbers — why no budget is invented here) ·
  [ADR-0027](0027-constant-time-comparison-is-asserted-by-mechanism-not-by-timing.md) (the
  mechanism-assertion rule this run needed twice over) · benchmark record
  [`nfr09-rownormalizer-trim-only-fast-path`](../benchmarks/2026/08/nfr09-rownormalizer-trim-only-fast-path.md)

## Context

Item 10.10 decomposed NFR-09's gateway overhead per stage, in isolated processes, and found that
hydration was **72%** of it — not all of it, as item 10.6 had claimed. The remaining named cost
was `RowNormalizer`: **+55.8 µs per 100 four-column rows** against an inline trim loop, 27% of the
overhead, and the only part that is not hydration. Item 10.11 was filed to reduce it.

That cost is paid on the **default policy**, where the only active step is `trim` (ADR-0042). The
shape of the class is why: `normalize()` iterates the row, and every string value is dispatched
through a private `normalizeValue()` that re-derives, per value, four decisions which are
properties of the *policy* — immutable for the lifetime of the object. A four-column row from
`GatewayBench`'s fixture carries 1.86 string values on average, so a 100-row batch pays that
dispatch **186 times** for a policy that could not change between the first value and the last.

Re-measured for this item on the same machine, in isolated processes (41 samples of 20 batches,
warm): the inline loop **42.9 µs**, the class **95.7 µs** — an overhead of **+52.9 µs per 100
rows**, or **276 ns per string value**. The 10.10 attribution reproduces.

NFR-09 itself is **met** at 1.73× against its ≤ 2.5× budget (spec r13). This is therefore an
optimization with headroom already in hand, which raises the bar for accepting complexity: the
change has to earn its keep on measurement, and "accept the cost as the documented price of
ADR-0042's explicitness" was a legitimate outcome the item named up front.

## Decision

**The policy decision is computed once, in the constructor, as a private `trimOnly` flag, and
`normalize()` gains exactly one fast path guarded by it** — a loop that calls `trim()` directly on
each string value and returns, skipping the per-value dispatch entirely. The general pipeline is
untouched and still handles every other policy.

Measured, real class, three passes: **95.7 µs → 65.2 µs per 100 rows**, overhead **+52.9 µs →
+22.3 µs — 58% of the normalizer's overhead removed**, semantics unchanged.

Four candidate designs were measured before choosing, not after:

| design | µs / 100 rows | overhead over the inline floor |
|---|---|---|
| inline trim loop (the floor, not a candidate) | 42.9 | — |
| **the class as it stood** | **95.7** | +52.9 |
| a precomputed `Closure` per policy, one call per value | 70.4 | +27.5 |
| the general pipeline inlined behind locals, no method call | 74.5 | +31.6 |
| one loop, hoisted flag consulted per value via a ternary | 59.0 | +16.1 |
| **hoisted flag, fast path outside the loop** (chosen) | **51.6**¹ | **+8.7**¹ |

¹ measured on an equivalent implementation in the *global* namespace; the class itself lands at
65.2 µs, and the 13.6 µs difference is a second, separate finding — see Consequences.

**Two tests hold it**, and the division of labour between them is the load-bearing part:

- a **differential matrix** — the corpus × all sixteen policy combinations, compared against an
  oracle implementing ADR-0042's ordering written outside the class. The `trim()` call cannot be
  wrong; `trimOnly`'s *condition* can, and a condition that fires one combination too widely is
  invisible to any test that exercises only the default policy;
- the condition's **truth table**, asserted as a mechanism through reflection (ADR-0027's rule for
  a property no behaviour can observe).

The second is not redundant, and this was **proved rather than argued**: planting
`trimOnly = false` — the optimization silently ceasing to exist — leaves the entire differential
matrix **green**, because the two paths are output-identical by design, and fails exactly the two
truth-table rows that assert the fast path is taken. A behavioural suite cannot see a performance
mechanism disappear. Five defects were planted in total (condition too wide on `blankToNull`, too
wide on `collapseWhitespace`, always true, always false, the fast path's `is_string()` guard
dropped); all five were caught.

## Alternatives Considered

- **Accept the cost as the price of explicitness** — the item's own third option, and the correct
  answer had the measurement come out differently. Rejected because the measurement did not: 58%
  of a cost that is 27% of NFR-09's overhead, for one hoisted boolean and one guarded loop, is a
  good trade at any reading — and unlike the two rewrite-shaped candidates, it leaves the general
  pipeline exactly as ADR-0042 wrote it.
- **One loop, the flag consulted per value via a ternary** (59.0 µs) — the more readable
  formulation, with no duplicated loop body, and the one that would have been chosen on style
  alone. Rejected on measurement: it keeps a per-value branch, giving back 7.4 µs of the 8.7 µs
  win — 45% of the improvement for a cosmetic gain. Recorded because the margin is small enough
  that a future reader may reasonably want it back.
- **A precomputed `Closure` per policy** (70.4 µs) — elegant, and it generalizes to every policy
  rather than one. Rejected: a closure call is still a call, so it recovers only half the
  dispatch, and it moves the pipeline's ordering — ADR-0042's actual decision — into a
  constructor-assembled callable that is harder to read than the `if/elseif` it replaces.
- **Inlining the general pipeline behind hoisted locals** (74.5 µs) — the "no method call at all"
  variant. Rejected on both axes: slowest of the three candidates *and* it duplicates the whole
  pipeline into the loop, which is where the transcoding failure path with its column-naming
  wrapper lives.
- **A second fast path for the no-op policy** (nothing enabled at all) — a real shortcut, but a
  degenerate configuration nobody sets: the class's reason to exist is the pipeline. Not added,
  because "exactly one fast path" is the property that keeps this from becoming a collection of
  special cases.
- **Prefixing internal function calls with `\` in this class** (`\trim()`, `\is_string()`) —
  measured, and it accounts for the whole 13.6 µs footnote above. Rejected **here** and filed as
  its own item; see Consequences.

## Consequences

**Faster on the path everyone takes.** `TableGateway` and `Repository` configure the default
policy unless a consumer says otherwise, so every gateway read gets this. NFR-09's ratio should
improve from 1.73× toward ~1.6× (CI is authoritative — the number in the benchmark record is the
one CI reports, not this estimate).

**One more thing to keep true.** The class now has two paths that must agree. The cost is bounded
by construction — the fast path is four lines and calls one function — and the differential matrix
is what makes it safe to read quickly. The mechanism assertion is what makes it safe to *keep*:
without it, the optimization can be deleted by a well-meaning simplification and every test stays
green.

**ADR-0042 is amended by annotation, not edited** (ADR-0041's precedent): its policy, defaults and
ordering are unchanged and remain the contract. This ADR adds only *how* the default policy is
executed.

**No budget is invented.** `RowNormalizerBench` measures two subjects — the class and the inline
floor — so the overhead is a difference two numbers on one runner can express, the way NFR-01 and
NFR-09 do. Neither subject gets an entry in `bench_budget_gate.py`: the spec owns its own numbers
(ADR-0040), and NFR-09 budgets the gateway path, not this component. The relative regression gate
(ADR-0030) holds them, and neither is I/O-bound or memory-hard, so neither belongs in ADR-0045's
exclusion list.

**A separate finding, filed as item 10.12 rather than fixed here.** The chosen design measures
51.6 µs when its class sits in the global namespace and **65.2 µs** as `D4np\Utils\Persistence\
RowNormalizer` — same code, same loop. The difference is PHP's **namespace fallback lookup** for
unqualified internal function calls: inside a namespace, `trim()` is resolved by trying
`D4np\Utils\Persistence\trim` first and the global `trim` second, and NFR-06 pins the benchmark
interpreter with **OPcache and JIT off**, which is precisely the configuration where that lookup
is not optimized away. Isolated with a two-class probe — identical bodies in one namespace, one
calling `is_string()`/`trim()` and the other `\is_string()`/`\trim()` — the unqualified version
measures **65.0 µs** and the prefixed one **51.6 µs**: **13.6 µs per 100 rows, ~36 ns per internal
call**, over 372 calls per batch.

It is deliberately **not** applied in this PR. Two characters in this one file would take the
number, but the repository calls internal functions unqualified everywhere, `native_function_
invocation` is not in the PHP-CS-Fixer configuration, and a lone prefixed call site reads as a
style slip rather than an optimization — the next tidy-up removes it and the 13.6 µs comes back
with every test green. Holding it needs either the repo-wide CS-Fixer rule (a risky-rule decision
touching every file, and the maintainer's call under ADR-0040's spirit) or a mechanism test
pinning two characters, which is theatre. The measurement is recorded; the decision is filed.

**Known limitation.** The 8.7 µs the fast path cannot remove is the per-*row* `normalize()` call
itself, which is the method's own existence. Nothing short of moving normalization into the
hydrator's compiled closure would reach it, and that would couple two groups the layering rule
keeps apart (ADR-0043).

## References

- Benchmark record: [`nfr09-rownormalizer-trim-only-fast-path`](../benchmarks/2026/08/nfr09-rownormalizer-trim-only-fast-path.md) — full environment, all four candidates, before/after
- Spec §3 **NFR-09**, §6 **T-15**; [RFC-0002](../rfc/0002-application-layer-groups-from-legacy-intake.md) **FR-36**
- `src/main/php/d4np/utils/Persistence/RowNormalizer.php`,
  `src/test/php/d4np/utils/Persistence/RowNormalizerTest.php`,
  `src/bench/php/d4np/utils/RowNormalizerBench.php`
- PHP manual, *Using namespaces: fallback to global function/constant* — the mechanism behind the
  13.6 µs finding
