# 2026-08-04 — First real benchmarks, and a genuine number the maintainer had to weigh in on

Roadmap item **3.5**. Route: `--step optimize` (`--flags step:optimize` is the wrong flag —
`routing.yaml` separates `steps:` from `flags:`), routed `fast / medium`; session model matched.

## Setting up the toolchain

`phpbench/phpbench: ^1.2` (resolved to 1.4.3) added to `require-dev`, `phpbench.json.dist`
written pointing at `src/bench/php/d4np/utils`, and a `D4np\Utils\Bench\` PSR-4 mapping added to
`autoload-dev` — missing on the first run, giving `Class "...TenScalarPropsDto" not found` until
`composer dump-autoload` picked it up.

`phpbench.json.dist`'s mere existence flips the CI `benchmark` job's step-level guard (from item
1.9) from skip to run — this PR activates a CI job that has been silently self-skipping since
Milestone 1.

## Two facts that shaped both benchmarks, checked rather than assumed

**`@Assert`'s `baseline` means a previous *tagged* run, not a sibling subject.** Read directly:
`docs/guides/assertions.rst`, `docs/expression.rst`, `docs/guides/regression-testing.rst`, and
the shipped `examples/Assertion/ExampleAssertionsBench.php`. NFR-01's ≤3× budget compares two
subjects run *together* — nothing in `@Assert`'s vocabulary expresses that. Decided the ratio
needs an external tool, in the shape `coverage_gate.py` and `action_pin_lint.py` already
established: parse phpbench's own `--dump-file` XML, compute a number, report it.

**`mem.peak` is process peak, not a subject-only delta.** Read
`lib/Executor/Benchmark/template/memory.template` directly: it's
`memory_get_peak_usage()` at the end of one iteration, inside phpbench's own forked subprocess —
so it includes the autoloader and executor harness's own overhead. Measured with a disposable,
never-committed `ProbeBench.php` (an empty method): **≈1.841 MB** baseline on this machine. NFR-04's
16 MB budget has generous headroom above that, so the distinction matters for interpretation but
not for the outcome here.

## A syntax error phpbench's own docs would have led me into

`docs/expression.rst` documents the mebibyte unit as `"mibibyte"`. Writing
`mode(variant.mem.peak) < 16 mibibyte +/- 10%` throws `SyntaxError: Unexpected "name" at end of
expression`, pointing straight at the unit token — not a typo of mine, the documented spelling
doesn't parse. Read `PhpBench\Util\MemoryUnit` directly instead of guessing further: the real
constant is `MEBIBYTES = 'mebibytes'` (plural). Fixed to `16 mebibytes` and it ran clean.
Recorded in `MemoryBench`'s own docblock and in ADR-0011 so nobody re-loses this time.

## The number `HydrationBench` produced

`benchHydrateWarm` mode ≈ 14.07µs, `benchManualConstruction` mode ≈ 0.91µs → **ratio ≈ 15.4×**.
Re-ran at different iteration/rev counts to rule out noise before trusting it — stable both
times, in the 15.6–16.1× range on an earlier probe run and 15.40× on the run committed here.

Against NFR-01's ≤3× budget, that is not close. It is the reflection-walking, per-property
type-coercing hydrator paying real, repeated cost per call that a compiled/cached-closure
hydrator would not — a genuine architectural gap, not a bug this item's tests should have
caught, and not something "write the benchmark" was ever scoped to fix.

## Stopping to ask, rather than deciding alone

Three honest options existed and none was obviously correct on its own: ship the benchmark and a
non-blocking ratio tool with the real number recorded and a follow-up item filed; wire the ratio
gate into CI now, at the current measured value, and accept that it fails until someone rewrites
the hydrator; or treat the finding as evidence NFR-01's own budget should be revisited. Used
`AskUserQuestion`. The maintainer chose the first, recommended option — no threshold lowered, no
premature CI block, honest recording, follow-up filed. Written up as **ADR-0011**.

## What this item did and did not do

Did: two working, measured benchmarks; a genuine, CI-enforced NFR-04 assertion; a non-blocking
ratio tool proven against real data (checked it fails at `--max-ratio 3`, passes at a loose
budget, and fails cleanly on a missing subject or missing report — the same "absence is failure"
discipline as every other gate in this repo).

Did not: touch the hydrator. Roadmap **item 3.7** is filed to close the ratio gap, most likely via
a compiled/cached-closure strategy cached alongside `ClassMetadata` — deliberately a separate
item, with its own measurement-first discipline, rather than folded in here under time pressure.

Full quality bar green: PHPUnit (243 tests, 464 assertions, 7 skipped — unchanged), PHPStan max
clean on the new bench files, PHP-CS-Fixer clean (the one pre-existing `BootstrapTest.php`
finding is untouched, unrelated, out of this item's scope), `consistency_lint.py` OK,
`action_pin_lint.py --verify-upstream` OK.

## State of Milestone 3

3.1–3.5 done. Remaining: **3.6** (deptrac layering gate, filed at 3.1) and **3.7** (this item's
follow-up: close the NFR-01 ratio gap).
