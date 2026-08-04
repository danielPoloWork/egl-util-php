# ADR-0007: Enforce the coverage floor as total line coverage, measured once

- **Status:** Accepted
- **Date:** 2026-08-04
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 2.7 · `AGENTS.md` §10 · spec NFR-07 ·
  [ADR-0003](0003-pin-ci-actions-by-commit-sha.md) (the same stated-but-ungated shape)

## Context

`AGENTS.md` §10 requires **"new code ≥ 90% line (finalized in an ADR)"** and spec NFR-07 requires
**"PHPUnit line coverage ≥ 90%"**. Nothing measured either. The CI `build` job set up `pcov` and
then ran `vendor/bin/phpunit` with no `--coverage` flag and no threshold — so the driver was
loaded, no report was produced, and no number was ever compared. The matrix *looked* like it was
enforcing a coverage floor while enforcing nothing, which is worse than an obviously absent
check.

This was found at item 2.1, when that item could not verify its own coverage claim: no driver
locally, no gate in CI. It is the same shape ADR-0003 dealt with — a policy stated in prose that
no mechanism could contradict.

Three things had to be settled before a gate could exist:

1. **What tool compares the number.** PHPUnit 10 ships a dozen `--fail-on-*` switches
   (`--fail-on-risky`, `--fail-on-skipped`, …) and **no coverage threshold** among them —
   verified against `--help`. The comparison has to live outside PHPUnit.
2. **What "new code ≥ 90%" means.** `AGENTS.md` explicitly defers this to an ADR, and the two
   readings differ enormously in cost: total coverage of the codebase, or coverage of the lines a
   change touches.
3. **What happens when the report is missing.** The failure mode that makes a coverage gate
   worthless is passing when nothing was measured.

## Decision

**A dedicated CI job measures line coverage once and compares it to a 90% floor, using
`tools/coverage_gate.py` over a Clover report. The figure compared is TOTAL line coverage.
A missing, unparseable, or empty report fails the gate.**

### Total coverage, not per-diff — and the limit is stated in the tool's own output

Per-diff coverage is the stronger check: it catches an untested addition that a large,
well-covered codebase would otherwise absorb without the total moving. It also needs the base
ref, a line-level diff, and a per-line comparison against the report — a different class of tool.

Total coverage is adopted because it is **verifiable today and honest about what it is**, and
`tools/coverage_gate.py` prints, on every successful run:

> `measured: total line coverage. NOT measured: per-diff coverage of changed lines.`

That sentence is the decision's enforcement. A gate that reports 94% without saying *94% of
what* invites exactly the misreading `AGENTS.md` §10's "new code" phrasing would produce.

### Absence is failure, never a pass

The gate exits non-zero when the report is missing, unparseable, has no project metrics, or
reports **zero measurable statements**. The last case is the important one: it is what a run
with no coverage driver produces, and a gate that treated it as "nothing failed" would go green
on every machine where pcov was not installed — reporting a floor nobody is standing on.

### Measured once, not per matrix cell

Line coverage of this library does not vary by PHP version; three matrix cells would produce
three identical numbers at three times the cost. The build matrix proves the tests **pass**
everywhere; the coverage job proves how much they **reach**. `coverage: none` is now set on the
build matrix, removing both the unused driver and the appearance that it was measuring something.

## Alternatives Considered

- **A Composer package** (`rregeer/phpunit-coverage-check`, `php-coveralls`) — rejected: it adds
  a dev dependency, and its failure modes on a missing report are its choice rather than this
  project's. The repository already has two stdlib-Python gates (`consistency_lint.py`,
  `action_pin_lint.py`); a third is the established pattern and keeps "absence is failure" a
  local decision.
- **Per-diff coverage** — the better check, deferred rather than rejected on principle: it needs
  base-ref plumbing and per-line comparison that this item cannot deliver honestly today. What is
  rejected is *claiming* per-diff coverage while measuring the total, which is why the tool says
  which one it did.
- **Fail the whole `build` matrix on coverage** — rejected: it conflates "the tests pass on PHP
  8.1" with "the suite reaches 90% of the code", and would triple the measurement cost for one
  number.
- **Warn instead of fail** — rejected: `AGENTS.md` §10 lists coverage among gates every PR *must*
  clear, and shortcuts ("tests next PR") are explicitly disallowed there. A warning is a shortcut
  with a nicer name.

## Consequences

- **The stated floor is now a fact rather than a claim.** A PR that drops total line coverage
  below 90% goes red, and the failure output lists the files below the floor worst-first, so it
  says where to look rather than only that a number was too small.
- **The gate's reach is narrower than `AGENTS.md` §10's wording**, and says so in its own output
  every time it runs. Closing that gap means per-diff coverage; until then the difference is
  visible rather than assumed.
- **Verified failing before being trusted.** All five failure paths were exercised against
  synthetic Clover reports — below-floor, zero statements, unparseable XML, missing file, and the
  passing case — rather than the gate being trusted because it went green once.
- **Cost:** one more CI job (~30 s) and one more tool to maintain. Offset in part by dropping
  `pcov` from the three build-matrix cells, where it was loaded and never used.

## References

- `AGENTS.md` §10 (the quality bar, and the "finalized in an ADR" deferral this ADR answers)
- Spec NFR-07 — `PHPUnit line coverage >= 90%`
- PHPUnit 10 `--help` — the absence of a coverage threshold among the `--fail-on-*` family
- ADR-0003 — the same stated-but-ungated shape, and the precedent for closing it with a
  stdlib-Python gate
