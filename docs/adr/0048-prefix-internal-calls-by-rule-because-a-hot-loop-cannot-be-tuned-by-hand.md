# ADR-0048: Prefix internal calls by rule, because a hot loop cannot be tuned by hand

- **Status:** Accepted
- **Date:** 2026-08-07
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item **10.12** (`adr`, `step:optimize`) ·
  [ADR-0047](0047-hoist-the-policy-decision-and-keep-one-fast-path.md) (item 10.11, which measured
  the cost and filed this decision) ·
  [ADR-0030](0030-same-runner-ab-because-a-stored-baseline-cannot-carry-a-10-percent-gate.md) (the
  harness that priced it) ·
  [ADR-0045](0045-exclude-io-bound-and-memory-hard-subjects-from-the-relative-gate.md) (why one
  subject reads `skipped` in the table) ·
  [ADR-0040](0040-install-the-mutation-tester-outside-the-graph-and-keep-nfr07s-own-number.md) (the
  spec owns its own numbers — why NFR-09's methodology question is not settled here) ·
  [ADR-0026](0026-session-hardening-as-a-value-and-csrf-through-a-seam.md) /
  [ADR-0027](0027-constant-time-comparison-is-asserted-by-mechanism-not-by-timing.md) (the seam
  test this change broke, and the rule about what a mechanism assertion may pin) · spec §3
  **NFR-06** (the benchmark environment), **NFR-09** · benchmark record
  [`native-function-invocation-repo-wide`](../benchmarks/2026/08/native-function-invocation-repo-wide.md)

## Context

Inside a namespace, PHP resolves an unqualified call to an internal function by trying the
namespaced name first and the global name second. Item 10.11 isolated that fallback with a
two-class probe and measured it at **13.6 µs per 100 rows** in `RowNormalizer::normalize()` — and
then deliberately did **not** fix it, because a lone `\trim()` in a repository that calls internal
functions unqualified in every file reads as a style slip: the next tidy-up deletes it, the
microseconds come back, and every test stays green. A per-file prefix cannot be held. Either a
formatter rule holds it everywhere, or the cost is accepted everywhere.

Item 10.12 was filed for that decision with one instruction attached: **measure on CI, not
locally.** Item 10.11 had just been corrected by CI — its own attribution, taken on a Windows
development box, put a component at 27% of NFR-09's overhead where the reference runner says 4.6%.
A repo-wide change to 95 files could not be justified on a number from the same machine.

## Decision

**Enable `native_function_invocation` in `.php-cs-fixer.dist.php`** with `include: ['@all']`,
`scope: 'namespaced'`, `strict: true`, and reformat the tree accordingly (95 files, 795
insertions). The formatter now holds the property that a hand edit could not.

The decision rests on the same-runner A/B in the benchmark record, base `master` versus this
change, both measured in one job on one runner:

| Subject | Δ |
|---|---|
| **`RowNormalizerBench::benchNormalizeHundredRows`** | **−24.02%**, and **−20.81%** on a second run |
| `GatewayBench::benchGatewayFetchNormalizeHydrate` (NFR-09's path) | −3.98%, then −2.17% |
| Container / QueryBuilder / Hydration subjects | −1.8% to −3.3% |
| I/O- and crypto-dominated subjects (Csv, Memory, bcrypt) | −0.6% to −0.04% |
| **controls** — benchmark-internal code the rule cannot touch | −2.57% … +2.98% |

`src/bench` sits outside the CS-Fixer finder, which turned out to be the most useful accident in
this measurement: three subjects are unprefixed on *both* sides and establish the noise band. That
band is **wider than most of the wins** — across two runs the controls moved from −2.57% to +0.45%,
so neither the 1.8–3.3% deltas nor the gateway path can be claimed individually. **One result
survives in both runs**: the normalizer, roughly an order of magnitude clear of the controls.

So the honest shape of the benefit is **concentrated, not spread**: the rule pays inside tight
loops that call internal functions once per item, and is inert in the ~93 `sprintf()` sites
formatting exception messages that dominate the diff. It is enabled anyway, because the loops
where it pays are exactly the ones nobody can be trusted to remember to hand-tune — which is the
finding item 10.11 filed.

**A second run withdrew one claim and left the decision standing.** The first run measured NFR-09
at 1.66×; the second measured **1.73×**, `master`'s own figure, and moved a control by −2.57%
where the first had moved it by −0.08%. So the ratio improvement is **withdrawn** — on two runs of
identical code it is indistinguishable from where it started — and the usable noise band is about
**±2.6%**, which also swallows the gateway path's −3.98%/−2.17%. The normalizer's −24.02%/−20.81%
is the one result clear of it in both runs, and it is what this decision rests on.

## Alternatives Considered

- **Accept the cost; change nothing.** The item's other named option, and the defensible one if
  the small deltas were all there was — two runs say they are not resolvable. Rejected on the
  normalizer alone (−24.02%, −20.81%): that is one measured hot loop today, and the next one gets
  the benefit for free under a rule where it would otherwise need another item like 10.11.
- **`include: ['@compiler_optimized']`, the fixer's own default** — the curated set where
  prefixing lets OPcache substitute opcodes. Rejected as the *primary* configuration because
  NFR-06 pins the benchmark interpreter with OPcache **off**, where the fallback lookup applies to
  every internal function and not just that set. Recorded as the conservative variant a maintainer
  may prefer if the churn is later judged too broad; it would keep most of the measured win, since
  the hot-loop functions (`is_string`, `trim`, `substr`, `preg_match`) are in it.
- **`scope: 'all'`** (prefix in non-namespaced files too) — rejected: there is no fallback lookup
  outside a namespace, so it would be churn with no mechanism behind it.
- **Hand-prefixing the hot loops only** — rejected in ADR-0047 already, and the reasoning is
  unchanged: it cannot be held, and holding it would need either a mechanism test pinning two
  characters (theatre) or this rule (which is the decision above).
- **Adding `src/bench` to the CS-Fixer finder in the same change** — rejected *here*, deliberately.
  It would prefix the hand-written comparators that NFR-01 and NFR-09 measure against, changing
  what those numbers mean, and the spec owns its own numbers (ADR-0040). See Consequences.

## Consequences

**A one-time churn of 95 files**, and the `git blame` noise that comes with it. Taken now because
now is when it is cheapest: no PR was in flight when it landed.

**One class of test turned out to be coupled to spelling, and it bit immediately.**
`NativeSessionApiTest::testEachMethodDelegatesToItsSessionFunction` — ADR-0026's seam assertion —
reads the class source and looks for the literal `return session_start();`. Prefixing makes that
`return \session_start();`: the same call to PHP, a different spelling, five red data sets, no
behaviour changed. It was fixed by normalising the leading separator before comparing, because the
mechanism that test owns is *which* function is called, not how it is written. The general rule,
worth carrying past this ADR: **a mechanism assertion must pin the mechanism and nothing else** —
if a formatter can turn it red, it is asserting more than it means to. The repository's three other
source-inspecting tests (`IdentifierTest`, `RepositoryTest`, `ConstantTimeComparisonTest`) match
patterns rather than literal call spellings and were unaffected.

**The library and its comparator are now formatted under different rules, and that outlives the
withdrawn number.** The first run's 1.73× → 1.66× did not reproduce, but the asymmetry that would
have explained it is structural: the library is prefixed; its hand-written comparator lives in
`src/bench` and is not. That is arguably the more
realistic comparison — a consumer writing that loop in their own namespaced application would not
prefix it either — but it changes what the number measures, and the change was not authorised by
the spec. **Left as an open question for the maintainer**, with the evidence in the benchmark
record: either add `src/bench` to the finder (restoring like-for-like, and moving the ratio back
up), or state in the spec that NFR-09 compares the library against *typical consumer code*. Not
decided unilaterally, for the reason ADR-0040 gives.

**A second, smaller inversion to know about.** `RowNormalizerBench`'s two subjects were the class
(22.798) against an inline floor (19.843); the class is now **faster than its own floor** (17.322
vs 19.827), because the floor is bench-internal and unprefixed. Item 10.11's record described that
overhead as +2.56–2.76 µs; under this rule the framing no longer holds. The subjects are still
useful as a pair, but the difference is now measuring the rule, not the policy object — the same
open question as above, in miniature.

**The benchmark environment is the one this was measured in, and no claim is made beyond it.**
NFR-06 pins OPcache and JIT off. Consumers running OPcache get a different distribution: a cheaper
fallback, and — for the `@compiler_optimized` subset — opcode substitution that is only available
to *prefixed* calls. Neither was measured; the net for such a deployment is unknown and stated as
unknown.

**Route.** This item was filed as `standard / medium`. That was a transcription error of mine:
`os/routing` resolves `label:adr` to **frontier-reasoning / extra**, an item whose deliverable is a
decision being decision-heavy by definition — exactly the correction item 1.10 made when it was
taken. The roadmap entry is corrected, and the session ran at `standard` (Opus 5): a recorded
mismatch, the maintainer's to accept at review.

## References

- Benchmark record: [`native-function-invocation-repo-wide`](../benchmarks/2026/08/native-function-invocation-repo-wide.md) — the full table, the controls, the environment assertion
- [ADR-0047](0047-hoist-the-policy-decision-and-keep-one-fast-path.md) — the 13.6 µs probe that filed this item
- PHP-CS-Fixer, `native_function_invocation` — `include` sets (`@all`, `@internal`,
  `@compiler_optimized`), `scope`, `strict`
- PHP manual, *Using namespaces: fallback to global function/constant* — the mechanism being priced
