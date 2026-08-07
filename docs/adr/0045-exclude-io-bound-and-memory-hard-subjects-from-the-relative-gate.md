# ADR-0045: Exclude I/O-bound and memory-hard subjects from the relative regression gate

- **Status:** Accepted
- **Date:** 2026-08-07
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 10.9 · spec NFR-06, NFR-10, NFR-05 ·
  [ADR-0030](0030-same-runner-ab-because-a-stored-baseline-cannot-carry-a-10-percent-gate.md)
  (the same-runner A/B this narrows, and the gate this amends) ·
  [ADR-0018](0018-querybuilder-benchmark-scope-and-the-measured-build-time-gap.md) and
  [ADR-0024](0024-assert-the-work-factor-not-the-wall-clock.md) (the absolute-vs-relative split
  this extends)

## Context

ADR-0030 measured that a same-runner A/B leaves NFR-06's 10% regression threshold roughly six
times the noise floor it has to clear — for the subjects it measured. Item 10.5, a **test-only**
PR whose diff touched no file under `src/main` (verified: `git diff origin/master...HEAD
--name-only | grep src/main` returned nothing), failed that same gate anyway:

```
FileSequenceBench::benchSequenceNext: 75.503 -> 105.779 (+40.10%)
HashBench::benchVerifyArgon2id: 113465.370 -> 129072.012 (+13.75%)
```

The identical commit passed cleanly on re-run. Neither subject is new to this project's noise
budget by design — `FileSequenceBench` already carries `RetryThreshold(5)`, and `HashBench`
already carries `RetryThreshold(20)`, both above this project's typical `5`, presumably because
someone already suspected these two were noisier. Raising the *within-run* retry tolerance did
not stop the *cross-run* (base vs. HEAD) comparison from firing.

This is not ADR-0030's problem restated: base and HEAD were measured on the same runner, exactly
as that ADR requires, and every other subject in the same run — including the whole `HydrationBench`
and `QueryBuilderBench` families — passed without incident. It is a narrower, second finding: **a
same-runner A/B is still not precise enough for a subject whose dominant cost is filesystem
locking or memory-hard hashing.**

The mechanism explains why, for each subject:

- **`FileSequenceBench::benchSequenceNext`** measures `FileSequence::next()`, whose cost is an
  `flock()` acquire, a read, and an atomic rewrite-via-rename (`File::update()`, ADR-0005/0038).
  On a shared CI runner this touches the filesystem layer directly — page cache state, disk queue
  depth from other work on the same VM, and the underlying storage backend's own latency
  variance, none of which the PHP process controls or can retry its way around.
- **`HashBench::benchVerifyArgon2id` / `benchMakeArgon2id`** measure Argon2id, which is
  **deliberately memory-hard** (NFR-05, ADR-0022/0024) — its whole security property is that it
  contends for the machine's memory bandwidth. A shared runner's other tenants (or even the CI
  job's own earlier steps) leave a memory-pressure signature that a CPU-bound benchmark like
  `HydrationBench` never sees.

Both properties are exactly why the spec budgets them **absolutely**, not relatively: NFR-10 is a
ceiling (≤ 200 µs), NFR-05 is a range (50–200 ms) already enforced by `bench_budget_gate.py`
(ADR-0030 §2). The relative gate on top of that was asking these two subjects for 10%-precision
their underlying mechanism cannot supply on shared hardware — a demand the spec itself never made
of them.

## Decision

**`tools/bench_regression_gate.py` gains a repeatable `--exclude Benchmark::subject` flag.** An
excluded subject is removed from the pass/fail decision but **not** from the report: it prints
with a `skipped` marker instead of a percentage, and a `NOTICE` line names it and points at its
absolute budget — the same "absence is failure, but an exclusion is not an absence" discipline the
`new`/`disappeared` handling already holds to. `ci.yml`'s regression-gate step excludes exactly
three subjects:

```
--exclude "FileSequenceBench::benchSequenceNext"
--exclude "HashBench::benchMakeArgon2id"
--exclude "HashBench::benchVerifyArgon2id"
```

**The criterion, stated so this stays a rule rather than three names bolted on:** a subject
qualifies for `--exclude` when its dominant, unavoidable cost is (a) real filesystem I/O with
locking — not CPU work that happens to touch a file, but a lock-acquire/write/fsync-class
operation — or (b) a primitive that is deliberately memory-hard or work-factor-hard by security
design (Argon2id today; any future KDF or password hash joins it on the same reasoning). A subject
that is merely *slow* does not qualify — `HashBench::benchMakeBcrypt` stays in the relative gate,
because bcrypt's cost is pure CPU time-cost with no memory contention, and it has not shown this
failure mode. Being slow in absolute terms and being *noisy relative to itself* are different
properties; only the second earns an exclusion.

**Both excluded classes keep every other protection this project has for them:**

- Their absolute ceiling/range in `bench_budget_gate.py` runs unchanged, on every PR.
- The nightly workflow (ADR-0030 §3) still runs their absolute budgets; it never ran the relative
  comparison at all, so nothing changes there.
- `RetryThreshold` stays elevated for both, since it still helps the *within-run* measurement even
  though it cannot fix the *cross-run* comparison.

## Alternatives Considered

1. **A wider per-subject threshold** (e.g. 50% for these two) — rejected: arbitrary, still not
   principled against a bad-luck spike, and it would silently normalize "sometimes this fires for
   no reason" instead of naming the reason and removing the check that cannot be satisfied.
2. **Best-of-N full measurement pairs** (run base+head N times, compare the best pair) — rejected:
   multiplies the cost of *every* PR's benchmark job (which already re-installs and re-measures a
   full second checkout) to fix noise in two specific subjects; the cost is paid by every
   contributor for a problem two subjects have.
3. **Accept re-runs as the documented protocol** — rejected, and the roadmap item that filed this
   ADR said why: *"a gate that cries wolf on a docs-and-tests PR teaches people to re-run until
   green,"* which is the exact failure mode a regression gate exists to prevent becoming habitual.
4. **Drop the two subjects from CI entirely** — rejected: their absolute budgets are real spec
   requirements (NFR-10, NFR-05) and still need enforcing; only the *relative* comparison is
   unreliable for them, not the subject itself.
5. **A dedicated statistical test (e.g. more revs, higher retry threshold) instead of exclusion**
   — considered and rejected as unproven: `RetryThreshold(20)` was already elevated on `HashBench`
   before this incident and did not prevent the cross-run failure, because retry threshold governs
   agreement *within* one phpbench run, not the gap between two separate runs measured minutes
   apart with different ambient load on the VM.

## Consequences

**Easier:** the regression gate stops producing a documented false-positive class on PRs that
never touch the affected code; a contributor no longer needs institutional memory of "just re-run
it, that one's flaky" to trust a red check.

**Harder / accepted:** a genuine regression *in* `FileSequence::next()`'s or `Hash::make()`'s own
logic (as opposed to environmental noise) will not be caught by the 10% relative gate — only by
its absolute budget, which has far more headroom (per ADR-0030's own table, 2.4–3.4× on the
subjects it measured) and therefore catches a **larger** regression later than the relative gate
would have. This is the accepted trade named in Alternative 4: the relative gate's whole value is
catching *slow drift* the absolute ceiling cannot see (ADR-0030 §2's *"twenty commits at +9% each
pass every one of its checks"* argument) — for these two subjects specifically, that value was
never real, because the gate could not distinguish drift from noise in the first place.

**Watch for:** any future benchmark whose dominant cost is I/O-with-locking or a memory-hard
primitive should be evaluated against this ADR's criterion at the time it is added, rather than
discovered by a false-positive PR the way this one was.

## References

- ROADMAP item 10.5 (the evidence), item 10.9 (this decision)
- ADR-0030 (the gate this amends), ADR-0005/ADR-0038 (`File`'s locking, the mechanism behind
  `FileSequence`'s cost), ADR-0022/ADR-0024 (Argon2id's memory-hardness and why NFR-05 is
  work-factor-gated, not wall-clock-gated)
