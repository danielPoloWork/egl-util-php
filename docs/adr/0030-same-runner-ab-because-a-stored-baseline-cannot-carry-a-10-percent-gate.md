# ADR-0030: A same-runner A/B, because a stored baseline cannot carry NFR-06's 10% gate

- **Status:** Accepted
- **Date:** 2026-08-05
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 7.1 · spec NFR-01, NFR-02, NFR-03, NFR-04, NFR-05, **NFR-06** ·
  [ADR-0018](0018-querybuilder-benchmark-scope-and-the-measured-build-time-gap.md) (the deferral this
  revisits) · [ADR-0020](0020-correct-the-nfr03-workload-and-resolve-the-driver-once.md) and
  [ADR-0028](0028-container-exceptions-live-in-the-container-group-and-get-carries-a-type.md) (the
  two earlier benchmark-workload errors, same shape) ·
  [ADR-0024](0024-assert-the-work-factor-not-the-wall-clock.md) (why NFR-05's wall clock stays
  ungated) · [ADR-0003](0003-pin-ci-actions-by-commit-sha.md) (the action pins these workflows obey) ·
  **Benchmark record:** [`docs/benchmarks/2026/08/nfr-budgets-under-nfr06-methodology.md`](../benchmarks/2026/08/nfr-budgets-under-nfr06-methodology.md)

## Context

NFR-06 asks for *"nightly CI; regression > 10% fails"*. Implementing that literally means comparing
tonight's numbers against a stored baseline — and the first question is whether GitHub's runners are
stable enough for a 10% threshold to mean anything.

They are not, and the evidence was already in the repository's own CI history. Across twelve `master`
runs, nine of them had `QueryBuilder` and its benchmark **provably unchanged** — `git diff` over both
paths plus `DatabaseConnection`, empty — and `benchBuildFiveConditionSelect` measured:

```
3.690  3.746  3.767  3.735  3.713  3.644  3.720  2.684  3.492  µs
```

**40.4% peak to peak on identical code.** A 10% gate against a stored baseline would have failed
repeatedly, on nothing. Shipping it would have been shipping the flaky gate this project's discipline
exists to prevent.

So the alternative was measured directly, on a throwaway workflow: five consecutive phpbench passes
inside **one** job.

| subject | range | spread |
|---|---|---|
| `benchHydrateWarm` | 0.967–0.971 µs | **0.41%** |
| `benchFirstAutowiredResolve` | 6.900–6.941 µs | **0.59%** |
| `benchBuildFiveConditionSelect` | 3.451–3.480 µs | **0.84%** |
| `benchBuildRealisticPagedQuery` | 6.226–6.315 µs | 1.43% |
| `benchManualConstruction` | 0.393–0.410 µs | 4.33% |

Same-runner noise is **~1.5%** for subjects above a microsecond, against **40%** cross-runner. The
threshold is not the problem; the comparison was.

## Decision

### 1. The regression gate is a same-runner A/B

`ci.yml` measures the base commit and HEAD **on the same runner** — the base via `git worktree` — and
compares the two. Runner-to-runner variance cancels because both sides meet it equally, which leaves
NFR-06's 10% threshold roughly six times the noise it must clear.

This is a deviation from NFR-06's *"nightly"* wording, and the right one: the specification asked for
nightly **and** for a 10% threshold, and on this infrastructure those two are incompatible. A gate
that fires on noise protects nothing. The nightly run still exists (§3) and does what it honestly can.

### 2. Two gates, because they catch different failures

- **`bench_regression_gate.py`** — HEAD vs base, >10% fails. It compares two `--dump-file` XMLs
  rather than using phpbench's own `--ref`/`--assert`. That expression does work, verified:
  `mode(variant.time.avg) < mode(baseline.time.avg) +10%` exits 0 inside the threshold and 2 outside.
  But phpbench's result store lives in the working directory, and this comparison spans two
  checkouts.
- **`bench_budget_gate.py`** — the absolute NFR ceilings. The relative gate structurally cannot see
  slow drift: twenty commits at +9% each pass every one of its checks and still double the runtime.
  It supports a **range** as well as a ceiling, because NFR-05's *lower* bound is the serious kind —
  a hash that got faster got weaker, and no ceiling would ever notice.

Both hold `coverage_gate.py`'s absence-is-failure discipline. A subject **new** at HEAD is a notice
rather than a failure (a benchmark added by the change under test has nothing to regress against), and
one that has **disappeared** is also reported, because a deleted benchmark is how a regression stops
being visible.

### 3. The nightly run drops the night-to-night comparison, and says why

`nightly.yml` runs the suite, the absolute budgets and the NFR-01 ratio on a cron, keeping each
night's report for 90 days. It does **not** compare against the previous night: that is a
cross-runner comparison, and the 40% figure above is exactly why it would be meaningless.

What it adds over the per-PR gate is real: the absolute ceilings catch slow drift, and
`composer install` re-resolves every night, so an upstream dependency that got slower shows up even
in a week with no commits.

### 4. NFR-06's environment is asserted, not configured and hoped for

`tools/assert_bench_env.php` refuses to let the suite run unless PHP is 8.3 with OPcache and JIT off.
Setting those ini values in a workflow and trusting them is how a measurement environment drifts in
silence — a `setup-php` upgrade that changed a default would leave the benchmarks green, *faster*, and
no longer comparable to anything recorded before it. Proven non-vacuous by the developer machine it
was written on, where it correctly refuses with `opcache.jit is "tracing"`.

### 5. Absolute budgets **are** gated on CI hardware, revisiting ADR-0018

ADR-0018 declined to gate absolute budgets because *"a slower CI runner would fail for a reason having
nothing to do with a regression"*. The reasoning was sound and the evidence was thin. Measured, the
headroom is 2.6×–33× while the worst observed cross-runner spread is 40%; a ceiling with 2.6× of
headroom is not something 40% of noise reaches.

The failure message says what a breach means rather than asserting a regression, and prints the
measured value rather than only `OK`, because a pass here means *"not breached on this runner"* — which
is weaker than the specification's claim.

## The three deferred measurements, and what actually happened

Items 3.5, 4.5 and 5.5 each deferred an absolute budget to this item. All three are now **met** — and
the reason is not an optimisation. No production code changed in item 7.1.

| Budget | Earlier record | Here | Ratio |
|---|---|---|---|
| NFR-01 warm hydration | 2.511 µs | **0.958 µs** | 2.6× |
| NFR-01 ratio | 2.74× | **2.40×** | — |
| NFR-03 five-condition build | 12.979 µs — **over** ≤ 10 | **3.776 µs** | 3.4× |
| NFR-05 `Hash::make` | 349 ms — **over** the range | **148.326 ms** | 2.4× |

A 2.6–3.4× gap is far more than two CPUs of a similar generation should differ by, so the honest
reading is not "the runner is fast". The earlier runs used **`--php-disable-ini`** — a workaround for
this developer machine's broken extension list, which does not disable OPcache alone but discards the
entire `php.ini` and every extension with it — on **Windows**, which NFR-06 does not contemplate.
They were honest measurements of a different thing.

That is the **third** benchmark-workload error on record in this project, after ADR-0020's NFR-03
subject and ADR-0028's autoload-inside-the-subject. All three times the total looked plausible and the
thing being measured was wrong. The pattern is now explicit enough to state as a rule: *a benchmark
number is not a measurement until you can say what was in the workload and what environment it ran
in.*

## What this does not settle

- **The runner is not the reference machine.** NFR-06 names a **Ryzen 7 5800X**; this is a GitHub
  `ubuntu-24.04` runner of unstated CPU. Every "met" is *"met on this runner"*. Closing that needs a
  run on the named hardware or a self-hosted runner, and cannot be done from here.
- **NFR-04 is not gated.** It budgets *"hydrating 10 000 DTOs ≤ 16 MB peak delta"*, and phpbench's
  `mem_peak` reports the whole process's peak — an identical `5.367mb` for every subject in this run —
  not a per-subject delta. `MemoryBench` measures the delta itself; a gate for it needs a different
  reader than `stats/mode`. Named rather than quietly skipped, and a fair candidate for a follow-up.
- **NFR-05's wall clock stays ungated**, per ADR-0024, whose split this does not disturb: the
  security-relevant property is the work factor, asserted in a unit test against OWASP's floors,
  because wall-clock time depends on hardware and the work factor does not. 148 ms inside the range is
  a capacity-planning data point. With 1.35× of headroom to the 200 ms ceiling against 40% observed
  spread, a wall-clock gate would be precisely the flaky kind this ADR spent its first section
  refusing.

## Alternatives Considered

- **A stored nightly baseline at 10%** — rejected on measured evidence: 40.4% cross-runner spread on
  identical code.
- **Raising the threshold above the noise** (~50%) — rejected: it would exist without catching
  anything short of a doubling, while reading like a working gate.
- **A dudect-style statistical comparison across nights** — rejected as the wrong instrument for a
  throughput budget; the variance is environmental, not distributional, and more samples of a
  different machine do not converge on the same machine.
- **A self-hosted runner on the reference machine** — the only way to honour NFR-06 literally, and not
  something this item can provision. Named above as what remains.
- **phpbench's own `--ref` + `--assert`** — works (verified), rejected only because its store is
  per-working-directory and the comparison spans two checkouts.
- **Gating NFR-05's duration** — rejected in §5 and above; contradicts ADR-0024 without new evidence,
  and has too little headroom to be stable.

## Consequences

- Two new gates, `tools/bench_regression_gate.py` and `tools/bench_budget_gate.py`, plus
  `tools/assert_bench_env.php`. `tools/bench_ratio_gate.py` is finally wired into CI — it has sat in
  the tree since item 3.5 marked it *"advisory today, not yet wired into CI"*, naming this item.
- **Gates proven to fail before being trusted** (lesson L-0008), eight cases with verified exit
  codes: regression on a 25% slowdown, a missing report, an empty report; budget on a breached
  ceiling, a value below a floor, an unknown subject, and no budgets given; plus new/disappeared
  subjects passing with a notice.
  The first pass of that verification was itself wrong — `$?` read through a `| tail` pipeline, so
  every case reported 0. Re-run without the pipe for the codes above.
- `.github/workflows/nightly.yml` is a new workflow, scheduled at **03:17 UTC** rather than on the
  hour: GitHub's scheduler queues heavily at `:00`, and a benchmark that waited in a queue measured a
  busier machine.
- All 27 action pins across three workflows verified upstream (ADR-0003).
