# 2026-08-06 — A budget this machine cannot honestly measure

Roadmap item **9.6**, closing Milestone 9. Route `fast / medium` — matched, no mismatch.
Two benchmark subjects, `FileSequenceBench::benchSequenceNext()` (NFR-10, ≤ 200 µs) and
`CsvBench::benchWriteTenThousandByTen()` (NFR-12, ≤ 150 ms), wired into the same
`bench_budget_gate.py` call ADR-0030 already established for the other four.

## The design was the easy part

Both subjects follow shapes this codebase already has names for. `CsvBench` takes
`MemoryBench`'s reasoning verbatim — 10 000 rows is the unit NFR-12 names, so `Revs(1)`
measures it directly rather than averaging several apart. `FileSequenceBench` takes
`ContainerBench`'s *opposite* lesson: that class needs a fresh subject every revolution
because its cold path is what is being timed; `FileSequence::next()`'s cost is dominated
by `File::update()`'s lock-acquire-and-rewrite, not by the counter's numeric value, so one
state file created per **iteration** (phpbench's hooks run once per iteration, not per
revolution — the fact `ContainerBench`'s docblock had to go read the runner template to
learn) is correct, reused across a thousand revolutions inside it.

NFR-12's other half — "memory O(row), never a full-table buffer" — is not a benchmark's
business at all, for the reason `QueryBuilderBench` already states: a stopwatch cannot see
an absence. `Csv::write()` streams through `File::writeStream()`'s handle; that is proven
by `CsvRoundTripTest`, not measured here.

## What this machine reported, and why it does not count

Direct timing (bypassing phpbench's own CLI, which fails its environment-detection capture
on this box before a single subject runs — a pre-existing warning-noise issue, not this
item's) put `benchSequenceNext` at **~49.8 ms per call** against a 200 µs budget: roughly
**250×** over. `CsvBench` came in at 213 ms against 150 ms — over, but by a plausible
margin rather than an alarming one.

The instinct is to treat a 250× miss as a defect. It is the same shape ADR-0030 already
named and closed, on this exact machine: Windows/NTFS plus (very likely) real-time
antivirus scanning turns each `File::update()` call's lock-file create, temp-file rename,
and lock-file delete into several disk round-trips that on Linux ext4 cost microseconds
and here cost milliseconds. ADR-0030's own three earlier benchmark corrections
(ADR-0018, ADR-0020, ADR-0028) all had the same tell — *"the total looked plausible and
the thing being measured was wrong"* — and each time the fix was to the workload, never to
trust a bad environment less loudly. This is a fourth instance of the same tell pointing
the other way: the workload here is exactly what NFR-10/12 name, and the environment is
what is wrong.

Re-deriving that conclusion by re-running `--php-disable-ini` (ADR-0030's own rejected
workaround, which discards the whole `php.ini`) was not repeated — the ADR already spent a
section rejecting it. Nothing about that finding needed a new ADR: ADR-0030 already
decided *that* absolute budgets are CI-gated on Linux hardware despite the reference
machine being a Ryzen 7 5800X nobody here runs on, and *why* a same-runner comparison
(not this developer's box) is the trustworthy read. Item 9.6 applies that standing
decision to two more subjects; it does not make a new one.

## What actually verified it, and what that verification is worth qualifying

The CI benchmark job, on `ubuntu-24.04`, gated by `tools/assert_bench_env.php` (PHP 8.3,
OPcache and JIT off) — the same authority every other NFR in this project answers to. It
reported `benchSequenceNext` at **182.981 µs** and `benchWriteTenThousandByTen` at
**20.213 ms**, both under budget. The 250× local number was indeed this machine, not the
library.

One number is worth not just checking off. NFR-10's budget clears by **~9%** — the
thinnest margin of any gated NFR here, against NFR-01's 2.6×, NFR-03's 3.4×, NFR-05's 2.4×,
and this item's own NFR-12 at 7.4×. ADR-0030 measured 40% peak-to-peak noise on identical
code across `master` runs, which dwarfs a 9% margin. This PR's single run passing is not
evidence the margin is real; the regression gate (base-vs-HEAD, same runner) is what would
catch drift going forward, and it correctly reported both subjects as *new* — nothing to
compare against yet. Recorded here so a future flaky-looking failure on this subject is
read as "the margin was always this thin" rather than investigated as a fresh regression.

## Lesson

A wild local benchmark number is information about the machine at least as often as about
the code — check which one changed before trusting either.
