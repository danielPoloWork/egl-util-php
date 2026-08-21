# ADR-0069: Resolve links in the lint, and refuse to guess a numbered section

- **Status:** Accepted — **scope amended** (2026-08-21, same day): a link whose target
  `.gitignore` excludes is **out of scope**, for the same reason an external URL is. §3's list of
  stated limits gains that line. **The check's substance is unchanged** — every tracked relative
  link still resolves, anchors still find headings, and the numbered-`§` refusal stands.
  The amendment exists because this check **merged green and left `master` red**: its six findings
  were `.eados-core/` factory-bundle files that `.gitignore` admits only under `learning/runs/`,
  so they are present on a maintainer's machine and in no clone. The verdict depended on the host.
  Annotated rather than edited, per ADR-0041's precedent.
- **Date:** 2026-08-21
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item **13.4** · issues
  [#116](https://github.com/danielPoloWork/egl-util-php/issues/116) (the checker) and
  [#117](https://github.com/danielPoloWork/egl-util-php/issues/117) (the dead links it found) ·
  [ADR-0060](0060-support-the-latest-release-of-the-current-major-and-measure-the-window-in-releases.md)
  (item 7.5's defect — a pointer to a section that did not exist — which is the reason this exists) ·
  [ADR-0007](0007-measure-total-line-coverage-against-a-floor.md) and
  [ADR-0068](0068-intersect-the-report-with-the-diff-and-ship-the-proof-it-can-fail.md) (the
  state-your-own-limits pattern §3 follows, and where it led) · `docs/workflow/release.md` §2 (the
  roll step §4 amends)

## Context

Item 7.5's load-bearing defect was `SECURITY.md` deferring a definition to `maintenance.md`, to a
**section that did not exist**. It was invisible for the entire pre-1.0 line, because the clause
above the pointer still applied — a reader who did not follow the link learned nothing was wrong.

`consistency_lint.py` covered version lockstep, the ADR index, pattern rows, the spec coverage map,
milestone agreement, the bug ledger and the governance posture. **None of it resolved a link.** The
2026-08-09 review board filed that as issue #116, with #117 for five dead links it had spotted in
`docs/changelog/v1/v1.0.0.md`.

The first run of the check below found **nineteen**.

## Decision

### 1. The check lives in `consistency_lint.py`, not in lychee or markdown-link-check

Item 13.4 explicitly left the choice open. One consideration decides it: **neither external tool
resolves a `§ "Section"` reference against the target's headings**, and that prose form is the shape
the originating defect actually took. A second toolchain in CI that misses the defect it was
installed for is a worse arrangement than sixty lines of standard library.

The secondary reasons are ordinary: no npm or cargo in a PHP repository's CI, no new pinned action
under ADR-0003, and the check belongs beside the eight cross-artifact invariants it completes.

### 2. Three things are resolved, exactly

- **Every relative link target must exist.** 713 references across 206 tracked Markdown files.
- **Every `#anchor` must match a heading** in its target — GitHub's slug rule (lower-case, drop
  non-word characters, spaces to hyphens), with inline markup stripped first so `## The *hard* part`
  answers to `#the-hard-part`. Same-file anchors are resolved against the same file.
- **A quoted or italicised `§` reference immediately after a link must name a section of that
  link's target.** This is the item 7.5 case, mechanised.

Two exclusions that are decisions rather than omissions. **Fenced code blocks are not followed** —
several ADRs and pattern pages show markdown that deliberately points nowhere, and following an
example is how a checker earns a reputation for crying wolf. **Images are not links** — a badge URL
is not a cross-reference.

### 3. The numeric `§` form is refused, and the check says so on every run

**546 of this repository's 602 section references are numeric** (`§2`, `§ 7`) — measured, not
estimated. They routinely refer to the *enclosing* document rather than to an adjacent link
("…which is ADR-0027's premise (§5)"), so resolving them needs sentence parsing to decide which
document a `§` belongs to.

A check that guessed would land in one of two places, and both are worse than an honest gap: cry
wolf across 546 references until someone disables it, or match none and **report green** — which is
the vacuous-gate failure this project has now hit three times in one session (BUG-0001's registry,
item 14.7's two tests that never reached their own branch).

So the limitation is printed on every run, beside the count of what *was* resolved. That is
`coverage_gate.py`'s pattern verbatim, and it is worth noting what that pattern produced: ADR-0007
stated its own limitation in output, and eighteen ADRs later that sentence became issue #109 and got
closed. **A stated gap is a filed issue waiting to happen; a silent one is not.**

#### 3b. A `.gitignore`'d target is out of scope, and the count is printed too

*(Amendment, 2026-08-21 — see Status.)* A link into a path `.gitignore` excludes is skipped, and the
number skipped is printed beside the number resolved, in §3's own idiom. Here that is six references
into the `.eados-core/` factory bundle, which `.gitignore` admits only under `learning/runs/`.

**The rule is keyed on ignore status, not on the file being missing**, and that distinction is the
whole amendment. Keying on absence would leave the defect in place: the bundle exists on a
maintainer's machine and in no clone, so the same commit passed locally and failed in CI. Keying on
ignore status makes the verdict identical on every host — proved by creating one of the ignored files
and confirming the counts do not move, where before its mere presence flipped FAIL to OK.

The status is asked of `git check-ignore` rather than pattern-matched in the lint, so the rule stays
where the project already states it and cannot drift from `.gitignore`.

**A note for whoever ports this**: it must be invoked `-z` and over bytes. Text mode translates the
outgoing newline to `\r\n` on Windows, git receives every path with a trailing carriage return, and
then C-quotes the reply — `'".eados-core/tools/autotune.py\r"'` — which matches nothing on the way
back. The first implementation did exactly that and reported nothing ignored.

**And the lesson §5's proof did not cover.** §5 proved this check could fail, which is the standing
method — but it proved it on a machine holding files the check depends on and CI never sees. So:
**a checker's first green run is not evidence it will stay green elsewhere.** Prove a new gate on a
clean clone, or read its CI run, before treating a local green as the verdict.

### 4. The roll step is amended, and the checker is what enforces it

`docs/workflow/release.md` §2 moves `[Unreleased]` entries from the repository root into
`docs/changelog/v<MAJOR>/`, two directories down, and rebased no paths — so it **manufactured dead
links mechanically**. Six of the nineteen came from exactly that, at `v1.0.0`.

The step now says to rebase (`docs/…` becomes `../../…`) *and* says the lint will catch a miss, which
is the important half: a procedure that relies on remembering is a procedure that fails, and step 5
already runs the lint.

### 5. The proof ships, and the real 19 are the stronger half of it

`tools/tests/verify_link_check.py`, fourteen cases over throwaway git repositories — the second
executable check for a `tools/*.py` here, after ADR-0068's. It includes a **direct reproduction of
item 7.5's defect**: `SECURITY.md` pointing at a `maintenance.md` section that was never written.

It also required a small addition, `consistency_lint.py --only <check>`: a proof that has to
interpret nine checks' combined output is proving less than one that asserts what a single check
reported.

But the strongest evidence is not synthetic. **The check found nineteen broken links on `master` the
first time it ran** — against the five the review board had counted — and every one is repaired in
the same PR. That demonstration is single-use, which is exactly why the repeatable half exists too.

## Alternatives Considered

- **lychee** — fast, checks external URLs too, and rejected in §1: no `§`-to-heading resolution, and
  a Rust binary plus a pinned action for a check the standard library does.
- **markdown-link-check** — same shortfall, plus an npm toolchain in a PHP repository.
- **Checking external URLs as well.** Deliberately out of scope: they fail for reasons that are not
  this repository's defects (rate limits, transient outages, sites that block CI ranges), and a gate
  that goes red on somebody else's downtime gets ignored, which costs more than it catches. The
  external links here are overwhelmingly to `github.com` paths in this same repository.
- **Attempting the numeric `§` form with a heuristic** (nearest preceding link, or the enclosing
  document when no link is near) — rejected in §3. Measured first: 546 instances is far too many to
  cry wolf across, and the heuristic's failure mode when it matched nothing would be silence.
- **A standalone `tools/link_check.py`** — rejected: the eight existing checks are cross-artifact
  congruence and so is this, it needs the same `git ls-files` and `ROOT` plumbing, and one more
  script is one more thing to remember to run.
- **Excluding `.specs/`** as imported EADOS input we do not own. Two of the nineteen live there.
  Rejected: an exclusion is a blind spot, both were mechanical path errors (a sibling addressed as
  though it were in an `adr/` subdirectory), and item 7.5 already set the precedent of repairing this
  class as rot. If the maintainer prefers `.specs/` frozen, an exclusion is one list away — but it
  should be a decision, not a default.

## Consequences

- `consistency_lint.py` gains the `links` check and a `--only <check>` flag;
  `tools/tests/verify_link_check.py` gains its proof. CI runs both — the check inside the existing
  `consistency / lint` step, the proof beside ADR-0068's in the same job.
- **Nineteen broken links repaired, from three distinct root causes** — worth recording separately,
  because "five dead links" described one of them:
  - **Renamed ADRs (4).** ADR-0040 was cited under **three different wrong names** across two files;
    ADR-0012 and ADR-0045 once each. Renaming an ADR file is a routine tidy-up that silently breaks
    every inbound reference, and nothing was looking.
  - **Wrong relative depth (7).** Six journal entries at `docs/journal/YYYY/MM/` and one benchmark
    record wrote `../../adr/…` where the target needs `../../../adr/…` — a template copied one level
    shallower and never resolved.
  - **The unrebased roll (6).** §4's mechanical cause, at `v1.0.0`.
- **Closes #117 as well as #116**, and the count in #117 was wrong: six in that file, not five, and
  thirteen more elsewhere. Recorded because an issue's own estimate becoming the definition of done
  is how the other thirteen would have survived.
- **What this does not check**, stated so a green run is not over-read: external URLs, and bare or
  numeric `§` references. The numeric form is the larger gap by volume (546 references) and the
  smaller one by risk — a wrong section *number* is a reader's momentary confusion, where a wrong
  *path* is a dead end. If it is ever worth closing, the tractable route is to number ADR sections
  in a machine-readable way rather than to parse prose.
