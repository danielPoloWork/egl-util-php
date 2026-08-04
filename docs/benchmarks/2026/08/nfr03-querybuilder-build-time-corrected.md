# Benchmark Report: NFR-03 QueryBuilder build time — corrected workload, and two optimisations

- **Date:** 2026-08-04
- **Version / commit:** v0.0.0 @ `f532f1c` (baseline) → this branch (`perf/querybuilder-build-time`)
- **Environment:** 12th Gen Intel Core i7-12700, 31.7 GB RAM, Windows 11 Pro 10.0.26200,
  PHP 8.3.1 CLI, phpbench 1.4.3, OPcache **off**, JIT **off**, Xdebug **off**
- **Command:** `vendor/bin/phpbench run src/bench/php/d4np/utils/QueryBuilderBench.php --report=aggregate`
  (locally with `--php-disable-ini --php-config='{"extension_dir":"…","extension":"pdo_sqlite"}'`,
  per the item 4.5 record — CI needs neither flag)

**This report corrects [the item 4.5 report](nfr03-querybuilder-build-time.md).** See §Correction.

## Correction: the earlier report measured the wrong workload

Spec NFR-03 budgets a **"5-condition SELECT"**. Item 4.5's benchmark subject built a query with
five `WHERE`-family conditions **plus** a five-column `select()` list, an `orderBy`, a `limit` and
an `offset` — twelve quoted identifiers against the five conditions the NFR names. Its own docblock
claimed to count *"exactly five calls that each contribute one condition"*, so the documentation
and the code disagreed, and the code was measuring roughly twice the work.

That figure (~23 µs) was then reported as NFR-03's, and item 4.6 was filed on the strength of it.
**Most of the "gap" was benchmark scope, not builder cost.**

The subject is now split, and both halves are reported:

- `benchBuildFiveConditionSelect` — `SELECT *` with exactly five `WHERE` conditions. **This is the
  one NFR-03's budget applies to.**
- `benchBuildRealisticPagedQuery` — the heavier item-4.5 shape, kept deliberately, asserting
  nothing. It is what an application actually builds, and keeping it visible is what stops the
  correction from reading as a benchmark quietly rewritten until it passed.

## Results

Two attributable changes were made, and measured on a quiet machine (all rstdev < 3%):

| subject | before | after | change |
|---|---|---|---|
| `benchBuildFiveConditionSelect` | 14.430 µs ±2.59% | **12.979 µs** ±2.49% | **−10.1%** |
| `benchBuildRealisticPagedQuery` | 24.690 µs ±1.57% | 22.168 µs ±2.29% | −10.2% |

### Change 1 — resolve the driver's quote characters once per builder

`quote()` asked `DatabaseConnection::driver()` — a `PDO::getAttribute()` round trip — on **every
identifier**. The driver cannot change during a builder's life, so it is now resolved once in the
constructor and carried across clones for free.

Proven **by counting rather than by timing**, because the per-call saving (~0.13 µs) is inside the
noise of an end-to-end run, while the count is exact:

| workload | before | after |
|---|---|---|
| five-condition SELECT (6 identifiers) | **7** lookups | **1** |
| realistic paged query (12 identifiers) | **13** lookups | **1** |

Pinned by `QueryBuilderTest::testTheDriverIsResolvedOncePerBuilderRegardlessOfChainLength()`, which
fails with `13 is not identical to 1` if the lookup returns to `quote()`.

### Change 2 — concatenation instead of `sprintf()` on the per-condition paths

Measured at roughly a third the cost (0.080 µs vs 0.259 µs per call) and executed once per
condition, per `orderBy`, and once in `toSql()`.

## Interpretation

**NFR-03's budget is still not met on the project's declared instrument.** 12.979 µs against
≤ 10 µs — about 30% over, versus the 2.3× over that item 4.5 reported. Roughly two thirds of the
apparent gap was the workload error; the optimisations closed about 10% of what remained.

**The instruments disagree by more than the remaining overage, which is why further
micro-optimisation here would be premature.** Measured on the same quiet machine, minutes apart:

| instrument | five-condition SELECT |
|---|---|
| phpbench (`--report=aggregate`, 10×1000) | **12.979 µs** |
| a plain in-process loop (60 000 iterations, median of 7 rounds) | **9.246 µs** |

An empty phpbench subject costs 0.079 µs, so this is **not** harness overhead — it is a real
difference in what the two measure, and it is ~3.8 µs, larger than the ~3.0 µs by which the
benchmark misses the budget. Whether NFR-03 passes today depends on which instrument is used,
which makes the remaining question a **methodology** question before it is an optimisation one.
Spec NFR-06 fixes the methodology and the reference machine (a Ryzen 7 5800X, not this i7), and
roadmap item **7.1** is what implements it. Tuning against an instrument that may not be the
authoritative one risks optimising for the measurement rather than the program.

**What is structural.** For the five-condition query, roughly 2.6 µs is identifier validation and
quoting (ADR-0015's allowlist, six identifiers) and ~1.5 µs is the six `clone`s ADR-0015's
immutability requires. Those ~4 µs are the direct cost of two recorded security/design decisions
and are not available to optimise without revisiting them.

## Reproduce

```bash
composer install && vendor/bin/phpbench run src/bench/php/d4np/utils/QueryBuilderBench.php --report=aggregate
```

The driver-lookup count, which is deterministic and machine-independent:

```bash
vendor/bin/phpunit --filter testTheDriverIsResolvedOncePerBuilderRegardlessOfChainLength
```
