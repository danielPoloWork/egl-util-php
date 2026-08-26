# 2026-08-26 — The number nobody had, and the one namespace under the floor

Issue **#108**, both criteria. Route `standard / medium`; session model Opus 5 — matched.
**ADR-0074** added, spec amended to **r23**.

The issue offers two routes: add `Persistence` to the gated mutation floor, or run a nightly
advisory full-tree measurement. It reads like a choice of effort. It is not — **only one of them
was actually available**, and noticing why is most of the item.

Adding `Persistence` to `infection.json5` requires picking a floor for it. Nobody knew what
`Persistence` scored. And ADR-0040 had already ruled on exactly that temptation, when it declined to
raise the gate to the 79% it had just measured:

> Raising the floor to the measured 79% would lock in today's number and read as stricter; it would
> also be **this document inventing a requirement the spec does not state**.

So the choice was never "cheap route or thorough route". It was "invent a number, or go and measure
one." I measured.

## One run, not one run per namespace

The obvious shape is a matrix leg per namespace. It is also wasteful: each leg re-runs the whole
test suite for information the first run already holds. Infection's JSON log records every mutant
with its `originalFilePath`, so the per-namespace split is **arithmetic over a report that already
exists**. One run, every namespace's number.

The catch is that splitting it means re-implementing Infection's MSI formula — `100 × (killed +
errors + timedOut) / testedMutants`, where `killed` spans `killedByTests` *and*
`killedByStaticAnalysis`, and `errors` spans `error` *and* `syntaxError`. A re-implementation that
quietly disagrees with the tool it is quoting would print a table of plausible, wrong numbers, and
the entire point of this job is that someone will set a spec floor from those numbers.

So the reporter computes the **full-tree** MSI from the mutant arrays and compares it against the
`msi` Infection itself wrote. Past a rounding tolerance it prints no table and exits non-zero. On
the first real run the two agreed exactly: 81.29% against 81.29%.

**That check earned itself within the hour.** Drafting the ADR I recomputed the gated three's
combined score by hand off the rounded display table, assumed `detected == killed`, and got 76.98%.
Wrong by 1.28 points — 11 `Dto` mutants and 2 `Security` mutants are *errors*, not kills. The real
figure is 78.26%, and I only caught my own arithmetic because the tool's numbers refused to match
it. Every figure in the ADR now comes from the raw artifact through the checked path.

## What the run found

CI, PHP 8.3 + pcov, 3 031 mutants, run 32944852423 — dispatched on the branch rather than waited
for, because a number I have not seen is not a number I can write into a spec.

| namespace | MSI | | namespace | MSI |
|---|---:|---|---|---:|
| `Http` | 88.89% | | `Container` | 77.01% |
| `Errors` | 86.39% | | **`Persistence`** | **75.84%** |
| `Dto` | 84.95% | | `Database` | 73.47% |
| `Support` | 83.08% | | **`Mail`** | **68.18%** |
| `Security` | 78.32% | | **ALL** | **81.29%** |

Three readings matter:

1. **`Persistence` clears a 70% floor today by 5.84 points**, `Http` by 18.89. Adding both would be
   green on the first run — the five namespaces together score 81.09%. So #108's first route is
   *safe*, and now demonstrably so rather than hopefully so.
2. **A full-tree gate would fail immediately.** `Mail` sits at 68.18%, 1.82 points under the floor,
   and it is the only namespace below it. #108's own framing — "or a nightly advisory **full-tree**
   MSI run" — did not anticipate that the full tree is not currently gateable. That is the single
   most useful thing this run produced, and no amount of reasoning would have produced it.
3. **The gated three are stable.** 78.26% against ADR-0040's recorded 79% — but measured over 1 012
   mutants where that run saw 560. The scope has roughly doubled in size at essentially the same
   score, which is a better result than the flat number suggests.

## What I did not do, deliberately

I did not add `Persistence` and `Http` to `infection.json5`, even holding numbers that say it would
work. ADR-0040 and #108's own *Related* line both name the scope as the maintainer's decision, and
taking it here — with evidence, which is the most tempting version — would make an ADR-recorded
ownership rule advisory. The evidence *is* the deliverable. Whenever the decision is taken it costs
one line of config and a spec revision.

I also did not chase `Mail`'s 56 escaped mutants. Same reasoning ADR-0040 used for its own 117: the
deliverable is a measurement, and those 56 are now a visible finding with a per-mutator breakdown
rather than an invisible one.

## Where this leaves the project

No production code changed. Two tooling configs, one reporter with a 21-case self-test, one nightly
job, and the spec's r23 row carrying the figures — which is #108's second criterion, *"the decision
and its number are recorded in the spec, not just the config"*, met for the decision that was
actually available.

Worth stating plainly: **these are one night's figures.** Mutation scores move with the tests, and a
single reading is a starting point, not a trend. The job runs nightly so that the second and third
readings exist before anyone treats the first as a floor. The same nightly run also carried
yesterday's taint job (#103) for its first real scheduled execution — green.
