# ADR-0077: One randomized-order CI cell, with a "coupling, not flake" rule attached

- **Status:** Accepted
- **Date:** 2026-08-26
- **Deciders:** project architect (agent), maintainer
- **Related:** issue [#100](https://github.com/danielPoloWork/egl-util-php/issues/100) ·
  spec **NFR-07**, revision **r25** ·
  [`docs/development/local-build.md`](../development/local-build.md)

## Context

Issue #100 (SDET Lead seat, 2026-08-09 review board): the suite — 2,831 tests when filed, 3,199
today — always runs in PHPUnit's declaration order. Hidden inter-test coupling (shared static
state, a filesystem leftover one test leaves for the next, an assumption about what already ran)
is invisible until it bites, and cheaper to find while the suite is this size than after it
triples again.

Two acceptance criteria: exactly one CI matrix cell adds `--order-by=random` with the seed logged
for reproducibility, and a failure confined to that cell is documented as "coupling, not flake" so
nobody re-runs it into silence.

## Decision

**Add a fourth cell to `ci.yml`'s existing `build` matrix — `php-8.3 / random-order` — rather than
a new job, and document the failure-response rule in `docs/development/local-build.md`.**

The three existing cells (`php-8.1`/`8.2`/`8.3`, all `preset: default`) already run the full suite
in declaration order on every supported toolchain; nothing about them changes. The fourth cell
reuses the same steps — checkout, setup-php, install, build — and branches only the `Test` step:
`--order-by=random` when `matrix.preset == 'random-order'`, the existing bare `vendor/bin/phpunit`
otherwise. PHPUnit 10 prints `Random Seed: <N>` in its own output header whenever `--order-by=random`
is used, with no extra flag — the job's own log satisfies the reproducibility criterion for free,
and a failure reproduces exactly with `vendor/bin/phpunit --order-by=random --random-order-seed=<N>`.

**PHP 8.3, not a fourth PHP version.** The random-order cell is a coupling probe, not a fourth axis
of version coverage — pinning it to the newest supported toolchain keeps that distinction visible
in the matrix itself rather than implying the random-order axis needs its own version matrix too.

**The documentation lives beside the commands it modifies, not in a new file.**
`docs/development/local-build.md` already tells a contributor what CI runs and how to reproduce it
locally; a new "CI conventions" file would split one fact (what this job does, and what a red run
of it means) across two places for no benefit. The added section states the rule plainly: a
failure in `random-order` alone — the three default-order cells still green — means order changed
the outcome, and the fix is to find the coupling, not to re-run.

## Alternatives Considered

- **Add `--order-by=random` to all three existing cells (drop the sequential cells entirely).**
  Rejected: it would spend the *entire* matrix on the coupling question and answer the "does the
  suite pass on 8.1/8.2/8.3" question with a seed-dependent result — a build cell should be
  reproducible byte-for-byte across re-runs of an unchanged commit, which random order is not.
- **A dedicated `random-order` job, separate from `build`.** Rejected as needless duplication:
  the four cells share every step except one branch in `Test`, and a matrix already expresses "the
  same steps, varied inputs" — a second job would re-declare checkout/setup/install for a single
  differing line.
- **A fixed, hardcoded seed committed to the workflow.** Rejected: a fixed seed finds the same
  ordering forever, which finds a coupling bug once and then stops looking — the entire value of
  "random" is a different ordering each run, with the seed logged only so *that specific* failure
  reproduces after the fact.
- **A new `docs/ci.md` for this and future CI-behavior notes.** Rejected in the Decision above —
  `local-build.md` already owns "what CI runs and how to reproduce it locally."

## Consequences

- **No production code changes.** One CI matrix cell, one documentation section, this ADR, and
  the spec revision.
- **The `build` job gains one more cell** — full suite, one more time, ~1 minute locally (3,199
  tests, 58.6s) — negligible next to `infection mutation score`'s several minutes in the same
  workflow.
- **First run found nothing**, which is the correct null result for a probe added today rather
  than evidence it was unnecessary: local verification (`vendor/bin/phpunit --order-by=random`,
  seed 1787749415) passed all 3,199 tests, 9 skipped, 0 failed, before this PR opened.
- **A red `random-order` cell now has a documented first move** — reproduce with the logged seed,
  find the shared state — rather than the default instinct of re-running a CI job that "looked
  flaky."

## References

- Issue [#100](https://github.com/danielPoloWork/egl-util-php/issues/100) — 2026-08-09 Release
  Review Board, SDET Lead seat.
- Spec **r25** — the recorded NFR-07 amendment.
- [`docs/development/local-build.md`](../development/local-build.md) — the coupling-not-flake rule.
