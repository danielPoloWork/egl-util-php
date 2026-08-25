# 2026-08-25 — The rejection that expired

Issue **#112**, both criteria. Route `standard / medium`; session model Opus 5 — matched.
**ADR-0031** annotated.

ADR-0031's *Alternatives Considered* contains this:

> **Running the checker on every PR** — rejected: pre-1.0 it would report breaks that are legal by
> §4 on most PRs, and a gate that is routinely and correctly ignored stops being read at all.

That was right when it was written, and the interesting part of this item is *why it stopped being
right without anyone editing it*. The reasoning is entirely about `0.y.z`: under SemVer §4 anything
may change, so most reported breaks were legal, so a per-PR check would be noise. **ADR-0059's
freeze inverted the premise.** Post-1.0 every 1.x break is signal — and the gate, firing only on
release PRs, surfaces it at the moment furthest from the change that caused it.

A rejected alternative whose rationale has an expiry date is not a permanent decision. Nothing in
the document said so, because nothing had made it visible yet.

## Two runs, two questions — which is what makes the baselines differ

The easy version of this item is "run the same check more often". That version is wrong, and the
place it shows is the baseline.

The **gate** asks *"are these breaks allowed in this bump?"* — its baseline is the **newest** tag,
because the bump is measured from the previous release.

The **report** asks *"is the frozen public surface still intact?"* — its baseline is the **oldest**
tag of the current MAJOR line, because that is where ADR-0059's promise starts. Comparing against
the newest tag would silently forgive anything already broken in `v1.1.0`; comparing against the
freeze does not.

Both are legitimate; neither answers the other's question. So they are two runs in one job, sharing
the checkout and the throwaway install, and the gate's steps are byte-for-byte what they were.

## The baseline is computed, not written

Issue #112 says "against the v1.0.0 tag". Hard-coding that string would be correct today and wrong
the day a `v2.0.0` exists — which is #161's lesson exactly: *a claim that has to be edited every
release will be wrong between them.* So the workflow derives it: take the MAJOR from `Version.php`,
list `v${MAJOR}.*.*` oldest-first, take the first. Today that resolves to `v1.0.0`. On a MAJOR
release PR it resolves to nothing, and the step says so in a notice rather than quietly comparing
against the previous line.

## The discount stopped being a release-time convenience

`bc_gate.py` discounts one finding: `Version::VERSION` changing value. It was added because a
release PR changes that constant by definition, so the gate failed every release by construction.

Running the report against the frozen tag makes it **load-bearing on every PR**: `master`'s VERSION
is `1.1.0` and the baseline is `v1.0.0`, so Roave reports that line on every pull request, forever,
by design. Without the discount the new report would cry wolf on its first run and every run after.
Verified locally before wiring it: with the version line as the only finding, `--report-only` prints
`findings=0`.

## What report-only must NOT be allowed to do

A report that never fails is one keystroke away from a permanently green tick nobody reads. This
repository has now written that failure down six times, so the split is explicit:

- **A finding never fails the build.** That is the whole point — the remedy for a real break is a
  MAJOR or a fix, and neither belongs to whoever happens to open the next PR.
- **An unreadable report always fails the build.** `--report-only` suppresses the *verdict* exit
  code and nothing else: an unparseable report, a missing report, a non-integer exit code, or a
  missing `findings=` line all exit 1. The step being broken is not the same fact as the code being
  fine.

Fourteen new cases in `verify_bc_gate.py` pin both halves.

## A pre-existing defect found by touching the file

`verify_bc_gate.py` reads the gate's stdout back and asserts on it. On Windows it had been failing
three of its fourteen cases **since it was written** — and not for any reason involving the gate:
the child wrote stdout in the console codepage, the parent decoded UTF-8, and the first em dash
raised `UnicodeDecodeError` inside `subprocess`'s reader thread. The exit codes still arrived, so
every `code == N` case passed and only the "…and says so" cases failed. Green on CI's UTF-8 Linux,
which is why nobody saw it.

Confirmed pre-existing by running the unmodified file on `master` — identical three failures —
rather than assuming. `PYTHONIOENCODING` is now pinned for the child.

## Where this leaves the project

28 cases in `verify_bc_gate.py`, all green on Windows for the first time. The `quality / backward
compatibility` job now does real work on every PR (~1 minute: the throwaway install plus one
analysis) where it previously exited in eight seconds having compared nothing on all but release
PRs.

Open, and worth stating: the report proves the surface against the **frozen tag**, not against
every intermediate release. If something broke between `v1.0.0` and `v1.1.0` it will now be
reported on every PR until it is fixed or the baseline moves — which is the correct behaviour for a
freeze, and will read as noise to anyone who expects a per-PR diff.
