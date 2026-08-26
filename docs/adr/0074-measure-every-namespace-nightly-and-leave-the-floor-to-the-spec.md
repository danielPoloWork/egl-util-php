# ADR-0074: Measure every namespace nightly, and leave the floor to the spec

- **Status:** Accepted
- **Date:** 2026-08-26
- **Deciders:** project architect (agent), maintainer
- **Related:** issue [#108](https://github.com/danielPoloWork/egl-util-php/issues/108) ·
  [ADR-0040](0040-install-the-mutation-tester-outside-the-graph-and-keep-nfr07s-own-number.md)
  (the decision this obeys rather than overrides) ·
  [ADR-0031](0031-run-the-bc-checker-outside-the-dependency-graph-and-gate-breaks-by-bump.md)
  (the throwaway-install pattern) ·
  [ADR-0030](0030-same-runner-ab-because-a-stored-baseline-cannot-carry-a-10-percent-gate.md)
  (CI as the authoritative measurement environment) ·
  [ADR-0044](0044-the-write-builder-querybuilder-never-had-and-one-allowlist-for-both.md)
  (why `Persistence` is injection-adjacent) · spec **NFR-07**, revision **r23**

## Context

`infection.json5` gates three namespaces — `Security`, `Database`, `Dto` — at NFR-07's
`>= 70%` floor. Issue #108, filed by the review board's SDET Lead and flagged as an open
observation as far back as PR #60, asks whether `Persistence` and `Http` should join them:
`Persistence` is data-mapping and injection-adjacent (it has its own gateway injection suite, T-13),
`Http` holds the CSRF comparison and the router.

**The awkward part is that the answer was not available.** Nobody knew what `Persistence` scored.
Choosing a floor for it would have meant inventing a number, and ADR-0040 had already ruled on
precisely that temptation when it declined to raise the gate to the 79% it had just measured:

> Raising the floor to the measured 79% would lock in today's number and read as stricter; it would
> also be **this document inventing a requirement the spec does not state**. The spec's number is
> the contract; a change to it is a spec amendment with its own reasoning, not a side effect of the
> run that first measured it.

Issue #108 offers two routes, and its own *Related* line repeats the constraint: *"the spec owns
NFR-07's numbers — scope change is a maintainer decision."*

## Decision

**Take the measuring route. A nightly advisory job scores the whole tree, a reporter splits that one
run per namespace, and the gated scope is left exactly as NFR-07 states it.**

### 1. One full-tree run, split arithmetically — not one run per namespace

A matrix leg per namespace would re-run the entire test suite once per leg for information the
first run already contains: Infection's JSON log records every mutant with its `originalFilePath`.
`tools/mutation_scope_report.py` groups those by namespace and applies Infection's own MSI formula
to each subset. One run, every namespace's number, at the cost of the one run.

### 2. The reporter checks its own arithmetic against Infection's, and refuses when it disagrees

This is the part that earns the tool its place. It **re-implements** Infection's MSI — `100 ×
(killed + errors + timedOut) / testedMutants`, with `killed` spanning both `killedByTests` and
`killedByStaticAnalysis` and `errors` spanning `error` and `syntaxError`. A re-implementation that
quietly diverges from the tool it is quoting would print a table of plausible, wrong per-namespace
numbers — and the entire purpose of this job is that a maintainer will set a spec floor from exactly
those numbers.

So it computes the **full-tree** MSI from the mutant arrays and compares it against the `msi`
Infection wrote into `stats`. Beyond a rounding tolerance it prints no table at all and exits
non-zero. On the first real run the two agreed exactly (81.29% against 81.29%), which is what
licenses every per-namespace row beneath it.

*This mattered in practice.* Drafting this ADR I recomputed the gated three's combined score by
hand from the rounded display table, assumed `detected == killed`, and got **76.98%** — wrong by
1.28 points, because 11 `Dto` mutants and 2 `Security` mutants are errors rather than kills. The
figure recorded below (**78.26%**) comes from the raw artifact through the same checked code path.

### 3. Advisory means the score never fails — and being unable to measure always does

`--min-msi=0` states the posture in the command rather than in a comment. But a job that cannot fail
is a job nobody reads, so the failure modes are moved to where they belong: the reporter exits
non-zero on a missing, unparseable or empty log, on a mutant with no attributable file path, and on
the arithmetic disagreement above. The *number* is advisory; *having* a number is not.

### 4. The spec records the measurement, and does not move the floor

NFR-07 gains one additive clause naming the advisory run, and spec revision **r23** carries the
figures. The gated scope stays at three namespaces. That is issue #108's second acceptance
criterion — *"the decision and its number are recorded in the spec, not just the config"* — met for
the decision that was actually available to take.

## What the first run measured

CI, PHP 8.3 + pcov, 3 031 mutants, run 32944852423:

| namespace | MSI | tested | escaped | gated today? |
|---|---:|---:|---:|---|
| `Http` | **88.89%** | 468 | 52 | no |
| `Errors` | 86.39% | 294 | 40 | no |
| `Dto` | 84.95% | 206 | 31 | **yes** |
| `Support` | 83.08% | 845 | 143 | no |
| `Security` | 78.32% | 512 | 111 | **yes** |
| `Container` | 77.01% | 87 | 20 | no |
| **`Persistence`** | **75.84%** | 149 | 36 | no |
| `Database` | 73.47% | 294 | 78 | **yes** |
| **`Mail`** | **68.18%** | 176 | 56 | no |
| **ALL** | **81.29%** | 3 031 | 567 | — |

Three readings the scope decision turns on:

1. **`Persistence` clears a 70% floor today, by 5.84 points**, and `Http` by 18.89. Adding both to
   `infection.json5` would be green on the first run; the five namespaces together score **81.09%**.
2. **A full-tree gate would fail immediately.** `Mail` is the single namespace below the floor, at
   68.18% — 1.82 points under. Issue #108's own framing ("or a nightly advisory full-tree MSI run")
   did not anticipate that the full tree is not currently gateable, and that is the most useful
   thing this run found.
3. **The gated three are stable, not drifting.** 78.26% today against the 79% ADR-0040 recorded —
   measured over 1 012 mutants where that run saw 560, so the scope has roughly doubled in size at
   essentially the same score.

## Alternatives Considered

- **Add `Persistence` (and `Http`) to `infection.json5` in this PR.** The route issue #108 offers
  first, and the numbers say it would work. Rejected anyway: ADR-0040 and the issue's own *Related*
  line both name the scope as the maintainer's decision, and taking it here — even with evidence in
  hand — would make an ADR-recorded ownership rule advisory. The evidence is what this delivers, so
  the decision costs one line of config and a spec revision whenever it is taken.
- **Raise the floor to the measured figures.** Rejected for ADR-0040's reason verbatim, which has
  not weakened: it would lock in today's number and invent a requirement the spec does not state.
- **A matrix leg per namespace.** Rejected in §1 — the same information at several times the cost.
- **Gate the full tree at a lower floor that `Mail` clears.** Rejected as the wrong instrument: it
  would loosen the guarantee on `Security`/`Database`/`Dto` in order to include everything, which is
  the opposite of what #108 asks for.
- **Fix `Mail`'s escaped mutants in this item.** Rejected as scope, exactly as ADR-0040 rejected
  chasing its 117: the deliverable is a measurement, and 56 escaped mutants in `Mail` is a finding
  for whoever takes it, now visible with a per-mutator breakdown rather than invisible.
- **`--min-covered-msi`.** Not applicable and worth stating: mutation code coverage is **100%** on
  every namespace in this run (`uncov` is 0 throughout), so the two coincide today — the same
  condition ADR-0040 noted for the gated scope.

## Consequences

- **No production code changes.** The diff is two CI/tooling configs, one Python reporter with its
  self-test, one nightly job, and documentation.
- **CI grows one nightly job.** It mutates the whole tree, so it is the most expensive job in the
  repository; nightly is where that belongs, and nothing on the PR path is slower.
- **`Mail` at 68.18% is now a known, recorded fact** rather than an unmeasured one — including that
  it is what currently blocks a full-tree gate.
- **The scope decision is now a one-line change** whenever the maintainer takes it: add the
  directories to `infection.json5`, and record the amendment in the spec.
- **Known limit:** these are one night's figures. Mutation scores move with the tests, and a single
  reading is a starting point rather than a trend. The job runs nightly precisely so the second and
  third readings exist before anyone treats the first as a floor.

## References

- Issue [#108](https://github.com/danielPoloWork/egl-util-php/issues/108) — from the 2026-08-09
  release review board (seat: SDET Lead), open since PR #60.
- Spec **r23** — the recorded measurement and the unchanged scope.
- `tools/mutation_scope_report.py` and `tools/tests/verify_mutation_scope_report.py` (21 cases).
