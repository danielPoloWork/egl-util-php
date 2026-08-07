# 2026-08-07 — A gate that cried wolf about noise it could name

Roadmap item **10.9**, closed. Route `standard / medium (adr)` — the roadmap's own filing
deliberately did not escalate this to `frontier-reasoning` the way item 1.10 did for an
open-ended policy question, because the decision space here was narrow: four enumerated options,
evidence already gathered by item 10.5. Session model Sonnet 5, a tier mismatch against the
`standard` route (resolves to Opus 5 per the catalog) — the human switched models explicitly
mid-session via `/model`, which is model authority exercised, not a mismatch to second-guess.

## The question was never "is this noise" — that was settled already

Item 10.5 already proved the two failures were noise, not regressions: a test-only PR with an
empty `src/main` diff failed the gate at `+40.10%` and `+13.75%`, and the identical commit passed
on re-run. What item 10.9 actually asked was what to *do* about a gate that can produce that
result — and the roadmap text was explicit that "just re-run it" is the wrong answer, because a
gate a contributor learns to route around by retrying is a gate that has stopped protecting
anything.

## Why retry tolerance didn't already fix it

Both flaky subjects already carry an elevated `RetryThreshold` — `FileSequenceBench` at 5,
`HashBench` at 20 (double this project's typical 5) — which means someone had already noticed
these two were noisier and reached for the obvious lever. It didn't stop the failure, and
understanding *why* is what made the fix specific rather than a blanket workaround:
`RetryThreshold` governs agreement **within** one phpbench invocation's iterations. The failure
here is a **cross-run** comparison — base measured, then head measured, minutes apart, on
whatever the VM's memory pressure and disk queue happened to be at each moment. No amount of
in-run retrying touches that gap.

The two subjects' own mechanism explains the gap directly. `FileSequence::next()` is an
`flock()`-guarded read-modify-write against a real file — filesystem latency variance the PHP
process doesn't control. `Hash::verify()`/`make()` on Argon2id is **deliberately** memory-hard;
that is the security property NFR-05 exists to protect, and it is exactly what makes the subject
sensitive to a shared runner's ambient memory pressure in a way a CPU-bound hydration benchmark
never is.

## The exclusion is a criterion, not three names

The temptation with a fix like this is to special-case the two failing subjects and move on. The
ADR states the reason instead: a subject qualifies for `--exclude` when its dominant cost is real
I/O with locking, or a primitive that is deliberately memory-hard by security design — not merely
"this one happened to be slow once." `HashBench::benchMakeBcrypt` stays in the relative gate on
that test: bcrypt is CPU time-cost only, no memory contention, and has not shown the failure mode.
The next person adding a benchmark for, say, a file-locking primitive or a KDF has something to
check it against rather than a precedent to imitate blindly.

## What the fix actually is

`bench_regression_gate.py --exclude Benchmark::subject`, repeatable. An excluded subject still
appears in the printed report — marked `skipped` rather than a percentage — because a silent drop
would be indistinguishable from the tool simply forgetting the subject existed, and this tool's
own docstring already commits to "absence is failure, never a pass" for missing reports and
subjects. An `--exclude` naming something absent from the report fails loudly for the same reason:
an exclusion that quietly means nothing once a subject is renamed is worse than no exclusion.

Both excluded subjects keep everything else: their absolute budget in `bench_budget_gate.py` (the
actual spec requirement — NFR-10's ceiling, NFR-05's range) runs unchanged on every PR, and the
nightly workflow's absolute-budget pass is untouched, since it never ran the relative comparison
to begin with (ADR-0030 §3).

## Verified without a test suite, matching every other tool here

No `tools/*.py` gate in this repo has a pytest suite — `consistency_lint.py`, `bench_ratio_gate.py`
and the rest are all verified by direct invocation, recorded in the ADR or journal that introduces
them (ADR-0030's own "gates proven to fail before being trusted" table is the precedent). Followed
here: a synthetic fixture reproducing item 10.5's exact numbers confirms three cases — `--exclude`
reports `skipped` and exits 0, the same fixture without `--exclude` still exits 1 (the underlying
check is unchanged), and `--exclude` naming an absent subject fails loudly rather than silently.

## Lesson

A retry-threshold bump is a hint that a benchmark's own mechanism is noisier than the project's
default, not a fix for a comparison the retry never touches. When the second lever (raising the
threshold further) also wouldn't touch the actual gap, that is the signal to ask what class of
cost the subject has that the gate's whole design assumption doesn't hold for — and to write that
class down, so the next flaky subject is recognized rather than rediscovered.
