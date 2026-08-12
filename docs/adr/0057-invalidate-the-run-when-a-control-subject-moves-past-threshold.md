# ADR-0057: Invalidate the run when a control subject moves past threshold

- **Status:** Accepted
- **Date:** 2026-08-08
- **Deciders:** tech-lead (agent-drafted), maintainer (merge)
- **Related:** ROADMAP item **12.6**, filed from item 12.4 · spec NFR-06 ·
  [ADR-0030](0030-same-runner-ab-because-a-stored-baseline-cannot-carry-a-10-percent-gate.md)
  (the same-runner A/B this amends) ·
  [ADR-0045](0045-exclude-io-bound-and-memory-hard-subjects-from-the-relative-gate.md)
  (`--exclude`, the sibling mechanism this is not a duplicate of) ·
  item 10.12 (the control-subject method this generalizes from one bench file's design choice
  into a gate-level mechanism)

## Context

Item 12.4's CI ran the same commit twice and failed **two different gates on two different runs**,
neither attributable to the diff (a new `Mail` group nothing else imports):

- **Run 1** — the relative gate (`bench_regression_gate.py`, ADR-0030) failed on five subjects,
  **+11.19% … +19.44%**. One of them was `RowNormalizerBench::benchInlineTrimHundredRows` — a
  hand-written inline `trim()` loop with no dependency on this project's code, added at item
  10.11 specifically as *"the floor the overhead is measured against."* It cannot regress. It
  moved anyway.
- **Run 2** — the relative gate passed and the *absolute* ceiling (`bench_budget_gate.py`) failed
  instead, on item 11.7's router (7.021 µs against 5). Every subject in run 2 measured
  **27–103% slower** than the identical subjects in run 1 —
  `benchWriteTenThousandByTen` alone moved 9 691 → 19 660 µs.

Both runs measured a **slow runner**, not a code change. The mechanism is specific to ADR-0030's
design: base and head are measured **sequentially** on one runner (`git worktree`, then two
`phpbench` invocations in the same job), which is what makes the comparison immune to
*runner-to-runner* variance (ADR-0030's own problem) but leaves it exposed to *within-run* drift —
a runner that changes speed **between** the two measurements shifts every subject in one
direction, and the gate attributes the shift to whichever half ran second.

`--exclude` (ADR-0045) does not fix this. It removes one *named, individually noisy* subject from
the pass/fail decision because that subject's own mechanism (filesystem locking, memory-hard
hashing) cannot supply 10%-precision on shared hardware. `benchInlineTrimHundredRows` is the
opposite case: it is not noisy on its own — it is one of the *quietest* subjects in the suite, a
pure PHP loop with nothing to contend for — and it moving is exactly what makes it useful. It is
not the subject that needs excluding; it is the **signal that nothing else in the run can be
trusted.**

## Decision

### `bench_regression_gate.py` gains a repeatable `--control Benchmark::subject` flag

A control names a subject that calls no code this project owns. When a control's measured delta —
in **either** direction — exceeds `--max-regression`, the gate prints `INVALID`, lists the
breaching control(s) with their measured change, states plainly that no other subject's number
from this run should be trusted, and exits **`2`** — a code distinct from `0` (pass) and `1` (a
real, or at least trustworthy, failure).

**Both directions, deliberately.** The observed incident was a slowdown, but the mechanism —
runner speed changing mid-job — is directional-agnostic: a runner speeding up between the two
halves would show every subject as an *improvement*, which is just as untrustworthy a comparison
and, worse, could mask a genuine regression underneath it. `abs(delta) > max_regression` is what
the reasoning demands, not only the sign that happened to be observed.

**A distinct exit code, not exit `0`.** GitHub Actions has no built-in notion of "this check is
neither pass nor fail, please re-run it" — any non-zero exit still shows the job red. Exiting `0`
was considered and rejected: an invalidated run tells us nothing about whether the code regressed,
and reporting that as a pass would let a real regression through under exactly the cover this
finding describes. `2` keeps the job red — requiring the same human attention a real failure
would — while giving the log an unambiguous instruction (*"re-run the job"*, not *"read the
diff"*) and leaving room for CI tooling to act on the distinction later without a second change to
this script.

**Individual regressions are not still reported as a separate `FAIL` when a control breaches.**
Case 4 in this item's verification (a control breaching *alongside* a subject whose own delta
looks like a real regression) is the reason: once the comparison itself is compromised, that
other subject's number carries no more information than the control's does. Printing both an
`INVALID` control notice and a `FAIL` regression list for the same run would let a reader pick
whichever framing they preferred; only one can be true.

### `RowNormalizerBench::benchInlineTrimHundredRows` and `LoggingBench::benchSinkDirectly` are the
### first two named controls

Both already existed as controls in their own bench files' design (items 10.11 and 12.3
respectively) — this wires that existing property into the gate rather than inventing a new
subject. **One control is not enough by construction**: a runner slowdown localized to one part
of a job's timeline could, in principle, land entirely inside one control's measurement window and
outside the other's. Two independent, cheap sentinels in the same job cost nothing extra to run
and halve that blind spot. `ci.yml`'s regression-gate step now reads:

```
--control "RowNormalizerBench::benchInlineTrimHundredRows"
--control "LoggingBench::benchSinkDirectly"
```

Not wired into `nightly.yml`: that workflow never ran the relative comparison at all (ADR-0030
§3), so there is nothing for `--control` to guard there.

### A name may not appear in both `--control` and `--exclude`

Refused loudly at argument-parsing time, before either file is even read. A control's entire value
is being a clean, low-noise signal; excluding it from pass/fail would silently defeat the property
`--control` depends on to mean anything, and the two flags giving contradictory instructions about
the same subject is exactly the kind of thing this project refuses rather than tolerates (the same
stance `LoggerFactory`'s closed key set and `Level::rankOf()`'s validate-before-filter took at
item 12.3).

## Verification

No permanent pytest suite exists for `tools/*.py` in this repository — the standing method
(items 10.5, 10.9) is a synthetic `phpbench --dump-file` XML fixture. Eight cases, including an
exact reproduction of item 12.4's run-1 numbers:

1. The reproduced incident (`benchInlineTrimHundredRows` +19.44%, `benchNormalizeHundredRows`
   +13.82%) with the control named → `INVALID`, exit `2`, and **no** old-style `FAIL — 2
   subject(s)` block.
2. The same two numbers with **no** `--control` given → the pre-existing `FAIL — 2 subject(s)
   exceeded the 10% budget` behaviour, byte-for-byte unchanged. Proves the change is additive.
3. A control that moves but stays inside the threshold → ordinary `OK`, unaffected.
4. A control breach coexisting with a separately-regressed, unrelated subject → still `INVALID`,
   not a `FAIL` for the other subject (the reasoning behind the "no separate FAIL block" choice
   above, performed rather than only argued).
5. A control that improves *past* the threshold in the negative direction → still `INVALID`
   (the symmetric-direction decision, performed).
6. An unknown `--control` name → loud `FAIL`, same discipline `--exclude` already holds to.
7. A name given to both `--control` and `--exclude` → loud `FAIL` before either report is read.
8. `--exclude` alone, reproducing ADR-0045's original scenario → unchanged `OK` with a `skipped`
   marker, confirming the two mechanisms do not interfere.

## Alternatives Considered

1. **(b) Net each subject's delta against the control's own delta**, so a run-wide shift cancels
   out arithmetically (e.g. subtract the control's % from every other subject's %) — the option
   named in the roadmap item as *"the most precise and the easiest to get subtly wrong."*
   Rejected for now: it assumes the runner's slowdown is *uniform* across every subject, which is
   an assumption this incident's own data does not fully support (run 2's subjects moved
   27–103%, not a single consistent factor), so a naive subtraction could under- or
   over-correct and manufacture a false pass or a false fail from real drift. Left as a follow-up
   if simple invalidation proves too coarse in practice — the mechanism this ADR adds
   (`--control`) is the prerequisite either way, since (b) still needs a named, trusted-zero
   subject to measure the shift against.
2. **(c) Interleave base and head measurements** instead of running them sequentially — rejected
   as a larger change than this finding requires: it touches `ci.yml`'s worktree/phpbench
   choreography (ADR-0030 §1) rather than the gate script, multiplies the job's complexity, and
   does not eliminate the need for a sanity check that the two halves are comparable at all.
3. **(d) Accept flapping, re-run by hand** — the status quo this ADR replaces, and the roadmap
   item's own evidence against it: item 12.4 needed three CI attempts (one lost to an unrelated
   concurrency cancellation, one to a phpbench timeout, one that finally produced a clean signal)
   before a human could tell "re-run" from "investigate" apart — exactly the ambiguity `INVALID`
   now states directly in the log.
4. **Exit `0` on invalidation** (treat it as a soft pass) — rejected in the Decision section above:
   it would hide a real regression exactly when the runner's noise is large enough to mask one.
5. **A single, shared control across `ci.yml` and `nightly.yml`** — rejected: `nightly.yml` never
   ran the relative comparison ADR-0030 §3 scoped to `ci.yml` alone, so there is nothing there for
   a control to guard against.

## Consequences

**Easier:** a run-wide runner slowdown is now named in the log as exactly that, rather than
requiring a human to notice a suspicious pattern (a "regressed" subject that calls no library
code) the way this ADR's own incident was diagnosed. Re-running is now the tool's own advice, not
folklore a contributor has to already know.

**Harder / accepted:** two control subjects are two more things every benchmark PR's CI output
must carry, and `INVALID` is a third possible outcome a reader of `gh pr checks` must learn to
distinguish from `FAIL` — the same kind of ambiguity item 12.4 hit when a `cancelled` job read
identically to a `failed` one in the checks rollup. `2` is a plain exit code, not a GitHub Actions
"neutral" status, so the job still shows red either way; the distinction lives in the log, not
(yet) in the checks UI. A genuine regression that happens to land in the *same* run as a
runner-wide slowdown is invisible until the job is re-run clean — accepted, because there is no
way to separate the two signals from inside one compromised run, which is this ADR's whole
premise.

**Watch for:** if `INVALID` starts firing often enough that it becomes background noise itself
(the same fate `--exclude`'s subjects were rescued from), that is evidence CI's runners have
gotten less stable since ADR-0030's baseline measurement, and the same-runner A/B's own premise
should be re-measured, not silently worked around with a wider threshold.

**Not yet exercised on real CI, and that is itself evidence of a second problem.** The PR that
introduced this mechanism failed `ci.yml`'s `benchmark` job at the *absolute-budget* step (item
11.7's router regression, unrelated to this change), and GitHub Actions stops a job at its first
failed step by default — every step after it, including the regression gate this ADR's own logic
lives in, was reported `skipped`. This repeats the exact shape item 10.10's journal already named
once, with NFR-09 in the blocking role that 11.7 plays here: as long as any absolute-budget item
stays open, every diagnostic added downstream of it in the same job — this one included — ships
untested against real CI until a PR happens not to trip that ceiling. Correctness here rests on
the eight synthetic-fixture cases in Verification, not on a live CI run; the note is recorded on
item 11.7 rather than fixed here, on the same restraint item 10.10 held to (a step-ordering fix is
a different, job-wide decision from this ADR's subject).

## References

- ROADMAP item 12.4 (the evidence), item 10.11 (`benchInlineTrimHundredRows`'s origin as a
  control), item 12.3 (`benchSinkDirectly`'s origin), item 12.6 (this decision)
- ADR-0030 (the same-runner A/B this amends), ADR-0045 (`--exclude`, the sibling mechanism)
