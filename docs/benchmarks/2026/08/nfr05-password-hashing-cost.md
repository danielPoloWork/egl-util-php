# Benchmark Report: NFR-05 password hashing cost

- **Date:** 2026-08-04
- **Version / commit:** v0.0.0 @ `4ad28dc`
- **Environment:** 12th Gen Intel Core i7-12700, 31.7 GB RAM, Windows 11 Pro 10.0.26200,
  PHP 8.3.1 CLI (NTS, VS16 x64), phpbench 1.4.3, OPcache **off**, JIT **off**, Xdebug **off**
- **Command:** `vendor/bin/phpbench run src/bench/php/d4np/utils/HashBench.php --report=aggregate`

## Scenario

Spec **NFR-05**: *"`Hash::make` (Argon2id defaults): 50–200 ms on the reference machine
(deliberately slow; documented for capacity planning)."*

**This budget is a range, which makes it unlike every other NFR here.** For NFR-01 and NFR-03 only
the ceiling matters and faster is better. For NFR-05, being *under* the floor is the more serious
failure: a password hash completing in 5 ms means the work factor is inadequate.

That asymmetry splits the requirement in two, and the split is the point:

- **The security half is not measured here.** Wall-clock time depends on CPU, memory bandwidth and
  ambient load; the **work factor** does not.
  `HashMatrixTest::testTheArgon2idWorkFactorMeetsTheOwaspFloor()` asserts `memory_cost` and
  `time_cost` against OWASP's published Argon2id minimums (m ≥ 19456 KiB, t ≥ 2) — machine-
  independent, and the assertion that would still catch a future PHP lowering its defaults while a
  fast machine made the timing look plausible.
- **The capacity-planning half is this report.** Nothing is asserted; the absolute range is tied to
  NFR-06's reference machine and gating on it from arbitrary hardware would fail for reasons
  unrelated to any regression (the reasoning items 3.5 and 4.5 recorded; item **7.1** owns
  baseline-tracked enforcement).

## Results

| subject | mode | rstdev | NFR-05 range |
|---|---|---|---|
| `benchMakeArgon2id` | **349.503 ms** | ±1.82% | 50–200 ms — **over** |
| `benchMakeBcrypt` | 141.098 ms | ±1.16% | (fallback; within range) |
| `benchVerifyArgon2id` | 347.849 ms | ±0.81% | not budgeted |

Cost parameters in use are PHP 8.3's defaults, unmodified: Argon2id `memory_cost=65536` (64 MiB),
`time_cost=4`, `threads=1`; bcrypt `cost=10`.

## Interpretation

**`make()` costs ~350 ms here — about 1.75× NFR-05's ceiling — and this is a capacity finding, not
a security one.** The work factor is PHP's default and clears OWASP's floor by a wide margin
(64 MiB vs 19 MiB required, t=4 vs t=2). The duration is simply what that work factor costs on this
hardware. Lowering the cost parameters to land inside 50–200 ms would trade security for latency,
which is the opposite of what "deliberately slow" asks for, and ADR-0022 already decided those
parameters belong to PHP rather than to this library.

**Two caveats on the absolute figures**, both pointing the same way:

- This is not NFR-06's reference machine (a Ryzen 7 5800X). Argon2id at 64 MiB is memory-bound, so
  it is sensitive to memory subsystem differences rather than raw clock.
- The bcrypt figure is itself a signal: 141 ms at `cost=10` is high — that operation is commonly
  50–80 ms — which suggests this Windows PHP build is slow at password hashing generally, and that
  **both** numbers are likely inflated relative to the reference machine. The ratio between them
  (Argon2id ≈ 2.5× bcrypt) is the more portable observation.

**`verify()` costs the same as `make()` (~348 ms), and that is the number that scales.** NFR-05
budgets only `make`, which runs at registration and on upgrade-on-login. `verify` runs on *every*
login, so it — not `make` — is what determines how many concurrent authentications a deployment can
sustain. This is symmetric by design in Argon2id (verification repeats the same derivation), and it
is recorded here because NFR-05's stated purpose is capacity planning and a plan built on the
`make` figure alone would understate the load.

## Reproduce

```bash
composer install && vendor/bin/phpbench run src/bench/php/d4np/utils/HashBench.php --report=aggregate
```

The machine-independent security assertion:

```bash
vendor/bin/phpunit --filter testTheArgon2idWorkFactorMeetsTheOwaspFloor
```
