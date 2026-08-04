# ADR-0024: Assert the work factor, not the wall clock — NFR-05 split by what each half can actually prove

- **Status:** Accepted
- **Date:** 2026-08-04
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 5.5 (closes Milestone 5) · spec NFR-05, NFR-06, §7 · item 7.1 ·
  [ADR-0022](0022-argon2id-by-default-with-a-fallback-decided-at-construction.md) (the class, and
  the `selectAlgorithm()` seam this matrix depends on) ·
  [ADR-0011](0011-benchmark-scope-and-the-measured-hydration-ratio.md) /
  [ADR-0018](0018-querybuilder-benchmark-scope-and-the-measured-build-time-gap.md) (the
  measure-and-defer precedent) · **Benchmark record:**
  [`docs/benchmarks/2026/08/nfr05-password-hashing-cost.md`](../benchmarks/2026/08/nfr05-password-hashing-cost.md)

## Context

Item 5.5 owes two things: spec §7's *"Hash matrix tests (argon2id/bcrypt fallback, rehash
triggers)"* and NFR-05's timing — *"`Hash::make` (Argon2id defaults): 50–200 ms on the reference
machine (deliberately slow; documented for capacity planning)."*

**NFR-05 is a range, and that makes it structurally unlike every other budget in this project.** For
NFR-01 and NFR-03 only the ceiling matters and faster is strictly better. Here, falling *below* the
floor is the more serious failure: a password hash completing in 5 ms means the work factor is
inadequate, and no amount of fast redeems it.

Measured before deciding anything (PHP 8.3.1, i7-12700):

| subject | mode | NFR-05 |
|---|---|---|
| `make()` Argon2id | **349.5 ms** ±1.82% | 50–200 ms — **over** |
| `make()` bcrypt | 141.1 ms ±1.16% | within |
| `verify()` Argon2id | 347.8 ms ±0.81% | not budgeted |

Two facts about `needsRehash()` were also probed, and one was surprising:

- A hash with **stronger** parameters than the current defaults also reports `true`. PHP compares
  parameters for *equality*, not for "at least as strong", so a hardened hash is silently
  **downgraded** on next login.
- A malformed or empty stored hash reports `true`.

## Decision

### 1. Split NFR-05 by what each half can actually prove

**The security half is asserted as the work factor, not as a duration.** Wall-clock time depends on
CPU, memory bandwidth and ambient load; `memory_cost` and `time_cost` do not.
`testTheArgon2idWorkFactorMeetsTheOwaspFloor()` checks them against OWASP's published Argon2id
minimums (m ≥ 19456 KiB, t ≥ 2).

This is the assertion that survives the case a timing test would miss: if a future PHP lowered its
defaults, a fast machine could still produce a plausible-looking duration while the actual work
factor had dropped. A stopwatch cannot distinguish "strong parameters on slow hardware" from "weak
parameters on fast hardware"; the parameters can.

A second assertion checks the library is not *overriding* PHP's defaults, since ADR-0022 decided
those belong to PHP. Both were verified non-vacuous by planting weak cost parameters — 2 failures.

**The capacity-planning half is a benchmark that asserts nothing**, per NFR-05's own stated purpose
and the precedent of ADR-0011 and ADR-0018: the absolute range is tied to NFR-06's reference machine,
and gating on it from arbitrary CI hardware fails for reasons unrelated to regressions. Item **7.1**
owns baseline-tracked enforcement.

### 2. The measured overshoot is recorded as a capacity finding, and the parameters are not touched

`make()` at ~350 ms is roughly 1.75× NFR-05's ceiling. **The correct response is to leave the cost
parameters alone.** They are PHP's defaults, they clear OWASP's floor by a wide margin (64 MiB
against 19 MiB required), and the duration is what that work factor costs on this hardware. Lowering
them to land inside 50–200 ms would trade security for latency — the exact inversion of what
"deliberately slow" asks for.

Two caveats are recorded with the number rather than left for a reader to infer: this is not the
reference machine, and the bcrypt figure (141 ms at `cost=10`, commonly 50–80 ms) suggests this
PHP build is slow at password hashing generally, so **both** figures are probably inflated. The
ratio between them is the more portable observation.

### 3. `verify()` is measured too, though NFR-05 does not budget it

NFR-05 budgets `make`, which runs at registration and on upgrade. **`verify` runs on every login**,
costs the same (~348 ms — symmetric by design in Argon2id), and is therefore what actually
determines sustainable authentication throughput. A capacity plan built on the `make` figure alone
would understate the load. Measuring it is within NFR-05's stated purpose even though it is outside
its stated budget.

### 4. The matrices are exhaustive cross-products, not examples

The fallback matrix covers all four cells of *availability × policy*. Item 5.3 tested three of them
incidentally; a policy tested at three of its four corners has an untested corner. Each cell is also
asserted to behave identically with no logger attached — the logger records the decision, it must
not influence it.

The rehash matrix covers current defaults, weaker `memory_cost`, weaker `time_cost`, **stronger**
parameters, a different algorithm, bcrypt at a raised cost, malformed, and empty. Every well-formed
entry is additionally asserted to still *verify*, because upgrade-on-login depends on a login that
must succeed first.

## Alternatives Considered

- **Asserting the 50–200 ms range in a unit test** — rejected: it would be flaky on CI and, worse,
  it cannot distinguish a weak work factor on fast hardware from a strong one on slow hardware,
  which is the only distinction that matters for the floor.
- **Tuning the cost parameters down to land inside the range** — rejected in §2. It trades security
  for latency and contradicts both "deliberately slow" and ADR-0022.
- **Treating the ~350 ms overshoot as a defect to fix** — rejected: the work factor is correct, so
  there is nothing to fix in the library. It is a fact about the hardware, recorded for planning.
- **Omitting `verify()` because NFR-05 does not name it** — rejected in §3: it is the figure a
  capacity plan actually needs.
- **Asserting the exact PHP default values** (`memory_cost === 65536`) rather than a floor —
  rejected: it would fail whenever PHP *raises* its defaults, which is the direction we want, and
  would turn an improvement into a broken build.

## Consequences

- **Spec §7's Hash matrix is complete**, and NFR-05 has both an assertion (work factor) and a
  measurement (timing), each doing the job it can actually do.
- 1037 tests total; `--group T-06` covers the security suites.
- **NFR-05's timing budget is not met on this machine** — ~350 ms against 50–200 ms — recorded with
  its caveats rather than adjusted away. The third NFR in this project to miss its stated number on
  development hardware, and the third deferred to item 7.1's reference-machine harness. That
  accumulation is itself worth noticing: 7.1 now carries NFR-01's absolute half, NFR-03's residual,
  and NFR-05's range.
- **A PHP behaviour worth knowing is documented**: `needsRehash()` reports `true` for *stronger*
  parameters, so a hash hardened beyond the defaults is downgraded on next login. This library
  always uses PHP's defaults so it cannot produce such a hash itself, but a consumer migrating from
  a hardened setup would silently lose that hardening.
- The work-factor floor is OWASP's, an external standard, rather than a number chosen here — so
  "deliberately slow" is checked against something with an owner outside this repository.

## References

- Spec NFR-05 (the range and its stated purpose), NFR-06 (reference machine), §7 (the matrix)
- ADR-0022 — the class, the cost-parameter decision, and the `selectAlgorithm()` seam without which
  half the fallback matrix could not be written
- ADR-0011 / ADR-0018 — the measure-honestly-and-defer precedent this follows
- OWASP Password Storage Cheat Sheet — the Argon2id minimums used as the floor
- Benchmark record: `docs/benchmarks/2026/08/nfr05-password-hashing-cost.md`
