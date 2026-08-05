# 2026-08-05 — The gate NFR-06 asked for would have fired on nothing

Roadmap item **7.1**, opening Milestone 7. Route `standard / medium` → Opus 5, the session model. No
mismatch.

## The specification asked for a gate this infrastructure cannot carry

NFR-06: *"nightly CI; regression > 10% fails."* Before building it I went looking for whether a 10%
threshold means anything on GitHub runners — and the evidence was already in the repository's own CI
history, free.

Twelve `master` runs. Nine of them with `QueryBuilder`, its benchmark and `DatabaseConnection`
provably unchanged (`git diff` over all three, empty — checked, because "probably unchanged" would
have made the whole exercise worthless):

```
3.690  3.746  3.767  3.735  3.713  3.644  3.720  2.684  3.492  µs
```

**40.4% peak to peak on identical code.** A nightly 10% gate would have failed most nights, on
nothing at all, and taught everyone to ignore it.

So I measured the alternative rather than guessing at it — a throwaway workflow running five
consecutive phpbench passes inside **one** job:

```
benchHydrateWarm               0.41%
benchFirstAutowiredResolve     0.59%
benchBuildFiveConditionSelect  0.84%
benchBuildRealisticPagedQuery  1.43%
```

~1.5% same-runner against 40% cross-runner. **The threshold was never the problem; the comparison
was.** So the gate measures base and HEAD on the same runner via a `git worktree`, and the 10% budget
gets about six times the noise it has to clear.

The nightly run still exists, because NFR-06 asks for one and it earns its keep — the absolute
ceilings catch slow drift the relative gate structurally cannot, and `composer install` re-resolves
nightly so an upstream dependency that got slower shows up even in a week with no commits. What it
does *not* do is compare against last night, and it says so in a comment rather than leaving someone
to wonder.

## All three deferred budgets are met, and not because anything got faster

Items 3.5, 4.5 and 5.5 each deferred an absolute budget here. All three pass. **No production code
changed in this item.**

| | before | now |
|---|---|---|
| NFR-01 warm hydration | 2.511 µs | **0.958 µs** |
| NFR-01 ratio | 2.74× | **2.40×** |
| NFR-03 five-condition build | 12.979 µs — over | **3.776 µs** |
| NFR-05 `Hash::make` | 349 ms — over | **148.326 ms** |

A 2.6–3.4× gap is far more than two CPUs of a similar generation should differ by, and that
discrepancy is the actual finding. The earlier runs used **`--php-disable-ini`** — my workaround for
this machine's broken extension list. It does not disable OPcache alone; it throws away the whole
`php.ini` and every extension with it. On Windows, which NFR-06 never contemplates.

They were honest measurements of a different thing.

That is the **third** time in this project a benchmark has been wrong in this shape — ADR-0020's
NFR-03 workload, ADR-0028's autoload-inside-the-subject, and now the environment. Every time, the
total looked plausible and what was actually being measured was wrong. Three is enough to state as a
rule rather than a coincidence: *a benchmark number is not a measurement until you can say what was
in the workload and what environment it ran in.* Which is why the environment is now **asserted** by
a script that refuses to let the suite run outside NFR-06's conditions, rather than configured in a
workflow and hoped for.

That assertion was proven by the machine it was written on: it refuses here with
`opcache.jit is "tracing"`.

## Revisiting a decision of my own

ADR-0018 declined to gate absolute budgets on CI hardware, on the grounds that a slower runner would
fail for reasons unrelated to a regression. Sound reasoning, thin evidence — nobody had measured the
spread.

With 2.6×–33× of headroom against a worst observed 40%, that concern does not survive contact with
the numbers, so the ceilings are gated now. The failure message says what a breach means instead of
asserting a regression, because a pass here is *"not breached on this runner"* and the runner is still
not the Ryzen 7 5800X the spec names.

## Two mistakes worth recording

**My gate verification was itself wrong.** I checked eight failure modes and every one reported
`exit=0` — because I read `$?` through a `| tail` pipeline, so I was reading *tail's* exit code. The
gates were fine; my check of them was measuring the wrong process. Same family as the T-03 probe that
reported `ABSENT` from a fatal error: a result that looks like confirmation.

**CI never ran on the first push.** I pushed the branch and waited on a monitor that found nothing,
because `ci.yml` triggers on `pull_request` and pushes to `master` — not on an arbitrary branch. Ten
minutes of watching a queue that was never going to have anything in it.

## Left named, not closed

- The runner is **not** the reference machine. Every "met" is "met on this runner". Closing that needs
  the named hardware or a self-hosted runner.
- **NFR-04 is not gated.** It is a memory budget, and phpbench's `mem_peak` reports the whole
  process's peak — an identical `5.367mb` for every subject — not a per-subject delta. A gate for it
  needs a different reader. Named in the ADR and the benchmark record.

## Next

**7.2** — `roave/backward-compatibility-check` on release PRs, and the deprecation policy.
