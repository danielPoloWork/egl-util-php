# ADR-0068: Intersect the report with the diff, and ship the proof it can fail

- **Status:** Accepted
- **Date:** 2026-08-21
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** issue [#109](https://github.com/danielPoloWork/egl-util-php/issues/109) (issues-only;
  no mirroring ROADMAP item) ·
  [ADR-0007](0007-measure-total-line-coverage-against-a-floor.md) (**the limitation this closes**,
  which that ADR documented in itself; annotated there) ·
  [ADR-0040](0040-install-the-mutation-tester-outside-the-graph-and-keep-nfr07s-own-number.md)
  (why §2 reuses NFR-07's number instead of inventing one) ·
  [ADR-0041](0041-constrain-sql-text-by-type-and-name-the-one-escape-hatch.md) (the
  zero-uses-so-grep-is-the-review-list property §3's escape hatch borrows) ·
  [ADR-0031](0031-run-the-bc-checker-outside-the-dependency-graph-and-gate-breaks-by-bump.md)
  (the skip-on-a-declared-condition shape §4 deliberately does **not** use) · spec **NFR-07**

## Context

`tools/coverage_gate.py` enforces total line coverage against NFR-07's 90% floor, and prints on
every run what it cannot see:

> measured: total line coverage. NOT measured: per-diff coverage of changed lines.

ADR-0007 recorded that as its own limitation rather than hiding it, and the 2026-08-09 review board's
SDET Lead filed it as issue #109: with the suite well above the floor, **an untested addition rides
inside the headroom** without the total moving enough to notice.

Two things from this session's own work argue the issue is not theoretical. Item 14.7's
planted-defect campaign found two tests that *passed while never reaching the branch they were named
after* — one idled an hour past a sixty-second TTL, so the code under test never ran. And BUG-0001,
two items earlier, was a guard that had been green on an empty set for ten items. In both cases the
only instrument that noticed was a planted defect. **A per-diff coverage gate is the cheap, automatic
version of the same signal**: it cannot tell whether a test asserts anything useful, but it can tell
that a line was never executed at all.

## Decision

### 1. A sibling tool, not a flag on the existing one

`tools/diff_coverage_gate.py` reads the same Clover report and adds one input the other gate does not
have: a git range. Separate because the failure modes differ — this one can fail for reasons that have
nothing to do with coverage (an unresolvable base ref), and folding that into the total gate would
mean a step named "coverage floor" failing because of `fetch-depth`.

The intersection is per-line: Clover's `<line num type="stmt" count>` rows against the line numbers a
`git diff --unified=0 --diff-filter=d base...head` reports as added on the new side.

Three details in that command are decisions:

- **`--unified=0`**, so there is no context to mistake for a change.
- **`--diff-filter=d`**, so a deleted file contributes nothing: its lines do not exist to be covered.
  A rename's new side is an addition, which is the correct reading — the lines are new at that path.
- **The three-dot range**, so the change is judged against the merge base. With two dots, every commit
  merged into `master` since the branch started would count as *this* change's untested lines.

**Only coverable statements count.** A changed line is in the denominator only if Clover calls it a
statement, so blank lines, comments, docblocks, closing braces and declarations are neither credit nor
penalty. `type="method"` rows are excluded too — counting them would put a signature line in the
denominator twice.

### 2. The floor is NFR-07's own 90%, not a new number

ADR-0040 reserves budget numbers for the spec, and a second coverage threshold invented here would be
exactly that. The argument for reusing the existing one is short: **a change that is itself at least
90% covered cannot drag a 90%-covered library below its floor.** Same number, different denominator.

This is also why the tool takes `--min` rather than hard-coding it: if the maintainer ever wants a
*different* per-diff figure, that is a spec decision and the tool is already able to carry it.

### 3. `@codeCoverageIgnore` is the one escape, and it starts at zero uses

Some lines provably cannot execute. `Crypto`'s `if ($ciphertext === false)` is this codebase's
documented example — kept as a guard with its reasoning written out, because *"cannot happen"* is not
the same claim as *"verified to not happen on every input this method can receive"*.

A strict per-diff floor and such lines can coexist for a reason worth stating: **this project already
forbids dead defensive code.** ADR-0022 removed it from `Hash`, item 12.1 removed it from `Crypto`,
item 12.4 wrote a MIME boundary check and then deleted it as unreachable, and item 14.7 removed a
redundant clamp. A codebase with that habit has very few uncoverable lines, which is what makes a
strict floor affordable here and might not make it affordable elsewhere.

Where a line genuinely must exist and cannot run, the honest mechanism is `@codeCoverageIgnore` with
a comment giving the reason. PHPUnit drops annotated lines from the report entirely, so they leave the
denominator rather than sitting in it uncovered. **There are zero uses of it in this tree**, so
`grep -rn codeCoverageIgnore src/` is the whole review list — the property ADR-0041 built for
`SqlStatement::composed()`, reused because it works: an escape hatch with no uses is one a reviewer
can audit in a second, and each new use is a decision somebody has to defend.

### 4. Enforcing from the start, and absence is failure

The gate exits **1** below the floor, **2** when it cannot run, and **0** when nothing coverable
changed.

**Absence is failure** — the stance `coverage_gate.py` already takes. A missing report, an unparseable
one, a base ref git cannot resolve, or a report measuring zero statements all exit 2. A gate that goes
green because it could not run is worse than no gate, and ADR-0031's skip-on-a-declared-condition
shape is deliberately *not* used: that shape is right for a check whose prerequisites do not exist
yet, and this one's prerequisites all exist today.

**A change touching no coverable statement passes, loudly.** A documentation-only diff has an
undefined per-diff percentage, and the honest answer is to say so rather than to divide by zero in
either direction. This very PR is such a change — it adds Python and YAML, so its own gate reports
"nothing to measure", which means **the first real reading comes on the next PHP change.**

Enforcing rather than report-only, and the reasoning is worth recording because issue #112 asks for the
opposite arrangement for the BC checker. Report-only suits a check whose *verdict* is advisory. This
one's verdict is not advisory — an untested added line is a defect by NFR-07's own standard — and a
gate that never fails trains people to ignore it. The mitigation is that `--report-only` is one flag
away if the first readings prove the floor noisy.

### 5. The proof that it can fail is shipped, not described

Items 1.11 and 2.7 established that a gate is not trusted until it has been watched failing. Every
prior `tools/*.py` on this project satisfied that by hand, in a scratch directory, with the outcome
written into an ADR — item 10.9's eight synthetic phpbench documents cannot be re-run by anyone today.

`tools/tests/verify_diff_coverage_gate.py` is the **first executable check for any tool in this
repository**, and CI runs it in the `consistency` job on every pull request. Fifteen cases, each
building a throwaway git repository so the diff parsing is exercised for real rather than stubbed —
which matters, because that parsing is half the tool and a canned hunk list would leave the `@@`
arithmetic and the three-dot range untested.

Four of the fifteen are the load-bearing ones: a wholly untested addition fails, a partially covered
one fails, **a diff that is untested while total coverage is high still fails** (issue #109's exact
complaint, asserted directly), and each way the gate cannot run exits 2 rather than 0.

The reason this ADR bothers to make a point of it: this session produced two defects that were
*claims nothing rested on* — BUG-0001's vacuous guard, and item 14.5's ADR sentence about a coupling
no test exercised. "Proved it can fail" written in prose is the same shape of claim. Shipped as a
script, it is not.

## Alternatives Considered

- **Extending `coverage_gate.py` with a `--base` flag** — rejected in §1: it would make a step named
  "coverage floor" fail for `fetch-depth` reasons.
- **A per-file floor instead of a per-line one** — considered, and it is genuinely simpler (Clover
  already reports per-file metrics, and `coverage_gate.py` prints the worst offenders). Rejected: a
  large well-covered file absorbs an untested method exactly as the project absorbs an untested file,
  so it reproduces the same hole one level down.
- **A minimum-diff-size exemption**, below which the gate reports without failing — the conventional
  arrangement, rejected. It is a second number, and it makes the gate weakest on precisely the small
  focused changes where a single uncovered line is easiest to notice and cheapest to fix.
- **An `--allow-uncovered N` tolerance** wired into CI — rejected for the same reason, and worse: a
  global tolerance is invisible per-PR, whereas `@codeCoverageIgnore` is visible in the diff that
  needs it.
- **Report-only on landing** — rejected in §4, with the reasoning stated because #112 asks for the
  opposite and the difference is whether the verdict is advisory.
- **Third-party diff-coverage tooling** — not considered seriously: it is a dependency for eighty
  lines of XML-and-git arithmetic, against NFR-08's posture, and the report is already produced.

## Consequences

- `tools/diff_coverage_gate.py`, `tools/tests/verify_diff_coverage_gate.py`, and two CI steps: the
  gate in `quality / coverage floor` on `pull_request` only, and its verification in
  `consistency / lint`. The coverage job's checkout gains `fetch-depth: 0`, without which the gate
  correctly exits 2 on every PR.
- **`pull_request` only, and deliberately.** On a push to `master` the change is already merged, so a
  per-diff figure would report on history rather than gate anything. The total floor still runs on
  both events.
- **ADR-0007 is annotated, not rewritten** — its decision (total coverage, measured once, against
  NFR-07's 90%) is untouched, and the per-diff gate reuses that same number.
- **A temporary measurement step ships with this**, and it is the honest way around a local
  constraint: there is no coverage driver on the maintainer's machine, so the first per-diff readings
  this project has ever had can only come from a runner. The step prints the figure for the three most
  recent merged PHP items (`e781934`, `3a42911`, `1dea68c`) against today's report, report-only, so
  the 90% floor is **confirmed or revised on evidence** rather than on §2's argument alone. It is
  labelled for removal once that is settled — the same shape as item 11.5's draft-PR-for-real-numbers
  method, which exists because local figures on this machine are not evidence.
- **What this does not measure**, stated so nobody reads more into a green square than it carries: that
  an executed line was *asserted* about. Item 14.7's two tests that passed without reaching their own
  branch would have satisfied this gate the moment any test touched those lines. Coverage is a floor
  under the mutation score (NFR-07's 70% MSI, ADR-0040), not a substitute for it — and neither is a
  substitute for a planted defect, which is still the only instrument that has caught a vacuous
  assertion on this project.
