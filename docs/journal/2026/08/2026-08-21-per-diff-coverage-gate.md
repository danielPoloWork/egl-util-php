# 2026-08-21 — Shipping the proof instead of describing it

Issue **#109**, issues-only with no mirroring ROADMAP item. Route `standard / medium`; session model
Opus 5 — above the route, because it followed item 14.7 in the same session and switching down for a
CI tool would have cost more than it saved.

The 2026-08-09 review board's SDET Lead filed this against a limitation ADR-0007 had documented in
itself: `coverage_gate.py` enforces **total** line coverage, so with the suite well above 90% an
untested addition rides inside the headroom. `tools/diff_coverage_gate.py` intersects the same Clover
report with the lines a change actually touched.

## Why this item earned its place right after 14.7

Item 14.7's planted-defect campaign found two tests that **passed while never reaching the branch they
were named after** — one idled an hour past a sixty-second TTL, so the code it claimed to test never
ran at all. BUG-0001, two items before that, was a guard green on an empty set for ten items. In both
cases the only instrument that noticed was a planted defect, which is expensive and manual.

A per-diff coverage gate is the cheap automatic version of the same signal. It cannot tell whether a
test *asserts* anything useful — and I said so in ADR-0068's consequences rather than letting a green
square imply it — but it can tell that a line was never executed.

## The one real decision: the floor

There is no coverage driver on this machine, so I could not measure the actual per-diff figure for any
real change. That rules out deriving a number the way ADR-0058 derived its benchmark ceilings.

So I did not invent one. **The floor is NFR-07's own 90%, applied to a different denominator.**
ADR-0040 reserves budget numbers for the spec, and a second coverage threshold conjured here would be
exactly the thing that rule exists to prevent. The supporting argument is one line: a change that is
itself ≥90% covered cannot drag a 90%-covered library below its floor.

What I did instead of guessing: **a temporary CI step that prints the gate's reading for the three
most recent merged PHP items** against today's report, report-only. That gives the maintainer the first
real per-diff numbers this project has ever had, on run one, so the floor is confirmed or revised on
evidence. It is labelled for removal. Same shape as item 11.5's draft-PR-for-real-numbers method,
which exists precisely because local figures on this box are not evidence.

## A strict floor is affordable *here*, and the reason is a project habit

A per-diff floor with no small-diff exemption normally invites friction: one uncoverable defensive line
in a five-line PR fails the gate. I rejected the conventional minimum-diff-size exemption anyway — it
is a second number, and it makes the gate weakest on exactly the small focused changes where one
uncovered line is cheapest to fix.

That is only defensible because **this project already forbids dead defensive code**: ADR-0022 removed
it from `Hash`, item 12.1 from `Crypto`, item 12.4 wrote a MIME-boundary check and deleted it as
unreachable, and item 14.7 removed a redundant clamp last hour. A codebase with that habit has very few
uncoverable lines. Somewhere without it, this floor would be cruel.

Where a line genuinely must exist and cannot run, the escape is `@codeCoverageIgnore` with a reason —
and there are **zero uses of it in the tree**, so `grep -rn codeCoverageIgnore src/` is the whole
review list. That is ADR-0041's `composed()` property, reused because it works: an escape hatch with no
uses is auditable in a second, and each new use is a decision somebody has to defend in a diff.

## ★ The part I would want reviewed

Items 1.11 and 2.7 established that a gate is not trusted until it has been watched failing. Every
prior `tools/*.py` here satisfied that **by hand, in a scratch directory, with the outcome written into
an ADR** — item 10.9's eight synthetic phpbench documents cannot be re-run by anyone today.

That is a claim nothing rests on, and this session produced two defects of exactly that shape:
BUG-0001's vacuous guard, and item 14.5's ADR sentence about a coupling no test exercised. "Proved it
can fail" in prose is the same species.

So `tools/tests/verify_diff_coverage_gate.py` ships: fifteen cases, each building a throwaway git
repository so the diff parsing runs for real rather than against a canned hunk list (that parsing is
half the tool, and the `@@` arithmetic and three-dot range would otherwise be untested). It is the
**first executable check for any tool in this repository**, and CI runs it in the `consistency` job —
which needed no new setup, since it already has Python and `fetch-depth: 0`.

Four of the fifteen are load-bearing: a wholly untested addition fails, a partially covered one fails,
**a diff that is untested while total coverage is high still fails** — issue #109's exact complaint,
asserted directly rather than argued — and every way the gate cannot run exits 2 rather than 0.

Two of my own harness bugs surfaced while writing it, both mine and not the tool's: a `dict(**{11: 0})`
that PHP-brained me wrote where Python wants string keys, and a scenario builder that never *deleted*
files absent from the head revision, so the deleted-file case could not commit. Worth noting only
because a verification script is code too, and item 14.5 already recorded that a harness which can fail
halfway needs the same scrutiny as what it tests.

## Small things, decided rather than defaulted

- **`pull_request` only.** On a push to `master` the change is already merged, so a per-diff figure
  reports on history rather than gating anything. The total floor still runs on both events.
- **`fetch-depth: 0`** on the coverage job's checkout. Without it the gate exits 2 on every PR — which
  is correct behaviour and useless as an arrangement.
- **Three-dot diff range**, so the change is judged against the merge base. With two dots, every commit
  merged into `master` since the branch started would count as this change's untested lines.
- **`--diff-filter=d`**, because a deleted file's lines do not exist to be covered.
- **Enforcing, not report-only** — and the reasoning is recorded because issue #112 asks for the
  opposite arrangement for the BC checker. Report-only suits a check whose *verdict* is advisory; an
  untested added line is a defect by NFR-07's own standard, and a gate that never fails teaches people
  to skip it. `--report-only` is one flag away if the first readings prove the floor noisy.

## Its own PR is the boring case

This change adds Python and YAML, so its diff contains no coverable PHP statements and the gate reports
"nothing to measure" on itself. **The first real reading arrives on the next PHP change** — stated in
the PR rather than left for someone to discover, because a gate that has never returned a number is not
yet a gate that works.

## Postscript — the numbers arrived, and they changed the tool

`#144` merged, and the temporary step delivered on run **32464266232**:

| commit | item | changed statements covered |
|---|---|---|
| `e781934` | 14.4 `Hmac` | 62 / 62 — **100.00%** |
| `3a42911` | 14.5 `RetryPolicy` | 89 / 89 — **100.00%** |
| `1dea68c` | 14.7 rate limiter | 167 / 175 — **95.43%** |

Total tree coverage on the same run: 2 515 / 2 674 = **94.05%**. And on the PR itself, exactly as
predicted: *"no coverable statements changed"*.

**The floor is confirmed on evidence.** The worst of three real items clears 90% by 5.4 points, and the
two before it were exact. §2's reasoning justified the *number*; this justifies it being *affordable*,
which is the part an argument alone could not settle.

**But the 95.43% is the useful part, and it exposed a defect in my own tool.** Eight statements in the
rate limiter are never executed — and the gate *declined to name them, because it had passed*. It
enumerated uncovered lines only on failure. That is withholding the actionable half of its own
measurement: a run that knows precisely which lines are dead and reports a percentage instead is doing
less than it could for free. Fixed; it now names them on success too, with a verification case for
each direction and one asserting a fully covered run stays quiet (17 cases).

**And then the named lines were worth having.** `$clock ?? new SystemClock()` in `RateLimiter`,
`ArrayRateLimitStore` and `FileRateLimitStore` — **the path every production caller takes**, and the
one no test reached, because every test injects a `FrozenClock`. No behaviour was wrong; what was
missing was any assertion that the default wiring works at all. Three tests added.

The transferable bit: **the gate's first output was worth reading as carefully as a test failure.** It
passed, it was green, and it still contained two findings — a hole in the tool and a hole in the
suite. This session started with BUG-0001 (a guard green on an empty set) and item 14.7 (two tests
green without reaching their own branch). Green is not the end of the sentence.

The temporary measurement step is gone, its readings recorded in ADR-0068.
