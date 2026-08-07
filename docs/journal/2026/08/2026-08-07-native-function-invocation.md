# 2026-08-07 — The control group is what made the number believable

Roadmap item **10.12**, the decision item 10.11 filed rather than took: whether to prefix internal
function calls repo-wide, or accept PHP's namespace fallback as a cost. **Closes Milestone 10.**

## The instruction the item carried

"Measure on CI, not locally." Item 10.11 had written that into its own follow-up after CI caught it
overstating a component by 6× — a proportion taken on this Windows box put `RowNormalizer` at 27%
of NFR-09's overhead where the reference runner says 4.6%. A change to 95 files could not be
justified on a number from the same machine.

So the branch exists to be measured, not to be right: enable the rule, push, and let ADR-0030's
same-runner A/B price it. Base `master` unqualified, head prefixed, one runner, one job.

## The accident that made it a real experiment

`src/bench` is outside the CS-Fixer finder. I noticed while reading the config and nearly "fixed"
it — and it turned out to be the most useful thing in the run: three benchmark subjects are
**unprefixed on both sides**, so they measure nothing but the runner.

They moved **−1.55%, −0.08%, +0.45%**. That is the noise band, measured rather than assumed, in the
same job as the results.

And it is **wider than most of the wins**. Container, QueryBuilder and Hydration all came in at
1.8–3.3% faster, which I would have happily reported as a result and which the controls say I
cannot claim one by one. What survives:

| | Δ |
|---|---|
| `RowNormalizer::normalize()` | **−24.02%** |
| the gateway path (NFR-09) | **−3.98%** |
| everything unprefixable (controls) | −1.55% … +0.45% |

Eleven of thirteen prefixable subjects leaned negative while the controls scattered both ways — a
rule with no effect does not produce that. But the honest headline is one hot loop, not a
repo-wide speed-up.

## What the diff actually is

`sprintf()` is the most-prefixed call by a wide margin, ~93 sites, almost all of it formatting
exception messages. The rule pays in loops that call internal functions once per item and is inert
in the large majority of the 95 files it touched. I enabled it anyway, and the reason is item
10.11's, not a new one: those loops are exactly the ones nobody remembers to hand-tune, and a
per-file prefix cannot be held against the next tidy-up.

## The test that was asserting more than it meant

`NativeSessionApiTest` — ADR-0026's seam assertion — reads the class source and looks for the
literal `return session_start();`. Prefixing makes it `\session_start()`. Five red data sets, same
call, no behaviour changed.

The fix was one `str_replace` normalising the separator, but the lesson is worth more than the fix:
**a mechanism assertion must pin the mechanism and nothing else.** If a formatter can turn it red,
it is pinning formatting too. Three other source-inspecting tests here match patterns rather than
literal spellings and never noticed.

## Two things I did not decide

NFR-09 improved 1.73× → 1.66×, and part of that is asymmetry: the library is prefixed, the
hand-written comparator it is measured against lives in `src/bench` and is not. Same story in
miniature for `RowNormalizerBench`, where the class is now **faster than its own floor**. Both are
methodology questions about what those numbers mean, the spec owns its numbers (ADR-0040), and
guessing would have been the fifth benchmark-scope error on this project's record. Handed over with
the evidence attached.

## And then run 2 took one of my two claims back

The docs commit triggered a second A/B of identical code, and I read it precisely because item
10.11 had just taught me not to publish n=1.

| | run 1 | run 2 |
|---|---|---|
| `RowNormalizer::normalize()` | −24.02% | **−20.81%** |
| gateway path | −3.98% | −2.17% |
| control (inline floor) | −0.08% | **−2.57%** |
| **NFR-09 ratio** | 1.66× | **1.73×** |

The normalizer holds. The gateway path does not — a −2.17% delta against a control that moved
−2.57% is nothing. And **NFR-09's improvement is withdrawn**: 1.73× is exactly what `master`
reports, so on two runs of the same code the ratio is where it started. The base measurement of one
subject moved 5% between runs for code that did not change at all (22.798 → 21.712).

That is the second consecutive item where a ratio claim from a single run failed to reproduce. It
is not bad luck; it is what this runner's variance does to any delta under ~3%. The rule I am
taking from it: **on this project, no benchmark claim under ~3% may be published from one run.**
The decision itself never depended on it — enabling the rule rests on the normalizer, which cleared
the noise twice.

## Lesson

**Put a control in the benchmark.** Not a baseline — a *control*: a subject the change cannot
possibly affect, measured in the same job. It cost nothing here (it already existed, by accident),
it turned an unfalsifiable list of small green numbers into two claims I can defend and five I
cannot, and it is the only reason I know the −24.02% is real rather than a good day on a shared
runner.
