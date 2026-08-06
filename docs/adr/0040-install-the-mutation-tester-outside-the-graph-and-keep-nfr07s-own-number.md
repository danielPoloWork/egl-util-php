# ADR-0040: Install the mutation tester outside the dependency graph, and keep NFR-07's own number

- **Status:** Accepted
- **Date:** 2026-08-06
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 10.8 · spec **NFR-07** (`Infection mutation score >= 70% on
  Security/Database/Dto namespaces`) ·
  [ADR-0031](0031-run-the-bc-checker-outside-the-dependency-graph-and-gate-breaks-by-bump.md)
  (the same obstacle and the same answer, for the BC checker) ·
  [ADR-0007](0007-measure-total-line-coverage-against-a-floor.md) (item 2.7 — the first time
  a gate in this repository was green while measuring nothing) ·
  [ADR-0030](0030-same-runner-ab-because-a-stored-baseline-cannot-carry-a-10-percent-gate.md)
  (CI as the authoritative measurement environment)

## Context

NFR-07 has required *"Infection mutation score >= 70% on Security/Database/Dto namespaces"*
since the spec was frozen at item 1.6. The `mutation` CI job has existed since item 1.9,
carrying the step-level config guard that pattern uses: *"self-enables when
`infection.json5` lands"*. `infection.json5` never landed. So for ten milestones the job
resolved `present=false`, skipped every step, and reported **pass in about seven seconds**.

That is item 2.7's finding repeating itself. There, the `build` job installed pcov and ran
PHPUnit with no `--coverage` flag, so the 90% floor *"was neither produced nor compared"* —
and ADR-0007 was the answer. The self-enabling guard (lesson L-0010) is a good pattern with
one failure mode nobody had checked for: **nothing ever asks whether the thing it waits for
actually arrived.** A guard that is still waiting after ten milestones is indistinguishable,
in the checks column, from a gate that is passing.

Three further obstacles surfaced only by *running* the step rather than reading it:

1. **`vendor/bin/infection` never existed, and cannot.** Infection is not in `require-dev`,
   and adding it fails: every release from **0.29.10** onward requires PHP `^8.2` or `^8.3`
   against this library's pinned 8.1 floor, while every release old enough to accept 8.1
   conflicts with versions this project already locks — `justinrainbow/json-schema` 6.10.0
   against their `^5.2`/`^5.3`, `fidry/cpu-core-counter` 1.3.0 against their `^0.4`/`^0.5`,
   `composer/xdebug-handler` 3.0.5 against their `^1.3`/`^2.0`. Resolving it with `-W` would
   mean *downgrading* dependencies the rest of the toolchain shares, to install a mutation
   tester.
2. **`--only-covered` is not an Infection option.** The generated step passed it; checked
   against 0.34.1's own `run` usage line, it does not exist. Uncovered code is excluded by
   default in current Infection, and the inverse flag is `--with-uncovered`. The step would
   have failed on argument parsing.
3. **Infection could not find PHPUnit** when run from a foreign vendor directory. Its
   `TestFrameworkFinder` asks `composer config bin-dir`, falls back to
   `getcwd() . '/vendor/bin'`, and manipulates `PATH` to reach it — a heuristic being asked
   to bridge two vendor directories, which failed on the maintainer's machine.

## Decision

Ship the gate with Infection installed into a **throwaway project** — `composer require
--working-dir="$RUNNER_TEMP/mutation-tool" "infection/infection:^0.34"`, exactly ADR-0031's
answer for `roave/backward-compatibility-check` — invoked from the repository root so it
analyzes this tree. `composer.json`, the 8.1 matrix cell and `--prefer-lowest` stay
untouched. `infection.json5` scopes `source.directories` to precisely the three namespaces
NFR-07 names, states `phpUnit.customPath` explicitly rather than relying on the finder, and
sets a 30s per-mutant timeout because `Hash`'s Argon2id path (one of the three namespaces)
budgets 50–200 ms per call under NFR-05 and would otherwise report timeouts as findings.

**The threshold stays at NFR-07's `--min-msi=70`, although the measured score is higher.**
First real run, on CI's PHP 8.3 + pcov runner: **MSI 79%** — 443 mutants killed, 117 covered
mutants escaped, mutation code coverage 100%. Raising the floor to the measured 79% would
lock in today's number and read as stricter; it would also be **this document inventing a
requirement the spec does not state**. The spec's number is the contract; a change to it is
a spec amendment with its own reasoning, not a side effect of the run that first measured it.

## Alternatives Considered

- **Add `infection/infection` to `require-dev`** — rejected on the dependency evidence in
  Context §1: impossible above 0.29.10 against the 8.1 floor, and possible below it only by
  downgrading shared tooling dependencies.
- **Raise this library's PHP floor to 8.2 so Infection installs normally** — rejected
  emphatically: the floor is a *consumer-facing promise* (spec §1, the `--prefer-lowest` job,
  a whole matrix cell), and moving it to accommodate a development tool would be the tail
  wagging the dog. The throwaway project costs one CI step.
- **An `infection.phar` download** — rejected: it reintroduces the supply-chain question
  ADR-0003 answers for actions (what is this artifact, and is it the one it claims to be)
  with no lockfile and no `composer audit`, and ADR-0031 already found upstream PHAR
  publication unreliable for the BC checker. A version-constrained Composer install is
  auditable by the tooling already here.
- **Set the floor to the measured 79%** — rejected above; the spec owns the number.
- **`--min-covered-msi` instead of `--min-msi`** — rejected as a silent substitution: they
  differ whenever mutation code coverage is below 100% (it is exactly 100% today, so the two
  coincide and the choice is currently invisible). NFR-07 says *"mutation score"*, so the
  plain MSI is what it gets; if coverage of the three namespaces ever drops, the gate should
  become harder to pass, not quietly rebase itself onto the covered subset.
- **Chase the 117 escaped mutants in this item** — rejected as scope: the requirement is a
  threshold and the threshold is met with 9 points of headroom. The escaped set is now
  *visible* — kept as a CI artifact with a per-mutator breakdown — which is the prerequisite
  for anyone deciding to act on it. Infection's own output notes that some mutants are
  inevitably harmless, and the first one this run reported is a case in point: `0` → `-1` in
  a `DatabaseException`'s unused *code* argument.

## Consequences

- The gate runs for real: **2m16s** against the previous ~7 seconds of nothing, and it is
  now the only source in this repository for how much its security-critical tests actually
  assert.
- **Proven able to fail before being trusted** (lesson L-0008), because a threshold nobody
  has seen reject anything is a threshold nobody has tested: the floor was temporarily raised
  to 95% against the measured 79%, CI turned red, and it was reverted in the following
  commit. Both commits are in this PR's history on purpose.
- 11 mutants produced syntax errors and are excluded from the ratio. That is Infection
  generating invalid code in edge cases, not a defect here — recorded so the number in the
  log is not read as 571 mutants with 11 mysteriously missing.
- **The MSI cannot be measured on the maintainer's machine**: it has no coverage driver (the
  same limitation behind the suite's 9 skips, and behind item 9.6's benchmark caveat). CI is
  authoritative, as ADR-0030 already established for benchmarks.
- The three-namespace scope means a mutation regression *outside* Security/Database/Dto is
  invisible to this gate. That is NFR-07's scope, stated rather than widened here.
- Cost: one throwaway `composer require` per PR run. Version-constrained to `^0.34` for
  ADR-0031's reason — a tool that changes its own findings between runs makes a gate
  unreviewable.

## References

- Spec NFR-07 (the requirement, unchanged by this ADR — only implemented)
- ADR-0031 §"Installing the checker outside the dependency graph" — the identical obstacle
  and the pattern reused here
- ADR-0007 / item 2.7 — the first instance of a gate green while measuring nothing
- Infection 0.34.1 `run` usage output — the source for `--only-covered`'s non-existence
- First measured run: MSI 79%, 443 killed / 117 escaped, mutation code coverage 100%
