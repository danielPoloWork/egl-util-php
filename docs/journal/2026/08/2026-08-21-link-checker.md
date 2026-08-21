# 2026-08-21 — The issue said five; there were nineteen

ROADMAP item **13.4**, issues **#116** (the checker) and **#117** (the dead links). Route
`standard / medium`; session model Opus 5, above the route because it followed #109 in the same
session.

Item 7.5's load-bearing defect was `SECURITY.md` deferring a definition to `maintenance.md`, to a
**section that did not exist** — invisible for the whole pre-1.0 line, because the clause above the
pointer still applied. Nothing in this repository resolved a link. Now `consistency_lint.py` does.

## ★ Five was a description of one root cause, not a count

The review board had spotted five dead links in `docs/changelog/v1/v1.0.0.md`. The check's first run
found **nineteen**, and they were not nineteen of the same thing — they were **three separate
mechanisms**, which is why the estimate was low rather than merely imprecise:

**Renamed ADRs — 4.** `ADR-0040` was cited under **three different wrong names** across two files.
`ADR-0012` and `ADR-0045` once each. Renaming an ADR file is a routine tidy-up nobody thinks of as a
change, and it silently breaks every inbound reference. Item 13.4's own text records two of these
being repaired at item 7.5 — the same mechanism, still running.

**Wrong relative depth — 7.** Six journal entries at `docs/journal/YYYY/MM/` and one benchmark
record wrote `../../adr/…` where three levels are needed. A template copied one directory shallower,
propagated by every subsequent entry that copied the previous one.

**The unrebased changelog roll — 6.** `CHANGELOG.md` sits at the repository root, so its entries
write `docs/patterns/…`; the per-version file sits two directories down. The roll moved the text and
rebased nothing, which **manufactures the defect mechanically** — issue #116 named this one, and it
is the only one of the three that had a documented cause.

All nineteen repaired here, so #117 closes too. **Its count was also wrong the other way**: six in
that file, not five. Recorded because an issue's own estimate quietly becoming the definition of done
is exactly how the other thirteen would have survived this PR.

## Why the lint and not lychee

Item 13.4 left the choice open — lychee, markdown-link-check, or a new check here. One consideration
decides it: **neither external tool resolves a `§ "Section"` reference against the target's
headings**, and that prose form is the shape the *originating* defect took. A second toolchain in CI
that misses the defect it was installed for is a worse arrangement than sixty lines of standard
library.

The rest is ordinary: no npm or cargo in a PHP repository, no new pinned action under ADR-0003, and
the check belongs beside the eight cross-artifact invariants it completes.

## ★ The refusal is the part I'd defend hardest

**546 of this repository's 602 `§` references are numeric** — `§2`, `§ 7`. I measured that before
deciding, not after. They routinely refer to the *enclosing* document rather than to an adjacent link
("…which is ADR-0027's premise (§5)"), so resolving them needs sentence parsing to work out which
document a `§` belongs to.

A heuristic lands in one of two places, and both are worse than an honest gap: cry wolf across 546
references until somebody disables the check, or match none and **report green**. That second one is
the failure this session has hit three times — BUG-0001's registry green on an empty set for ten
items, and item 14.7's two tests green without reaching the branch they named.

So the numeric form is out of scope and the check **prints that on every run**, beside the count of
what it did resolve. That is `coverage_gate.py`'s pattern verbatim — and it is worth naming what that
pattern produced: ADR-0007 stated its own limitation in output, and eighteen ADRs later that sentence
became issue #109 and got closed two hours ago. **A stated gap is a filed issue waiting to happen; a
silent one is not.**

## The proof, and which half is stronger

`tools/tests/verify_link_check.py` — fourteen cases over throwaway git repositories, the second
executable check for a `tools/*.py` here after ADR-0068's. It includes a direct reproduction of item
7.5's defect: `SECURITY.md` pointing at a `maintenance.md` section that was never written.

It needed one small addition, `consistency_lint.py --only <check>`. A proof that has to interpret
nine checks' combined output is proving less than one asserting what a single check reported — and the
flag is useful on its own while iterating.

But the synthetic half is the weaker half. **The check found nineteen real broken links on `master`
the first time it ran.** That is the demonstration, and it is single-use: repairing them destroys it,
which is precisely why the repeatable version has to exist too.

## Two small decisions worth stating

**`.specs/` is not excluded.** Two of the nineteen live in the imported EADOS input spec, where a
sibling was addressed as though it sat in an `adr/` subdirectory. Excluding imported files would be a
blind spot, both were mechanical path errors, and item 7.5 already set the precedent of repairing
this class as rot. If the maintainer wants that input frozen, an exclusion is one list away — but it
should be a decision, not a default.

**External URLs stay out of scope.** They fail for reasons that are not this repository's defects —
rate limits, transient outages, sites that block CI ranges — and a gate that reddens on somebody
else's downtime gets ignored, which costs more than it catches.

## Where the roll step landed

`docs/workflow/release.md` §2 now says to rebase paths when moving `[Unreleased]` entries, with the
arithmetic spelled out (`docs/…` becomes `../../…`). The more important half is the sentence after
it: **the lint is what catches a miss.** A procedure that relies on remembering is a procedure that
fails, and step 5 already runs the lint — so the checker, not the instruction, is the mechanism.
