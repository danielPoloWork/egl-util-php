# Benchmark Report: NFR-03 QueryBuilder build time

> **CORRECTED by [the item 4.6 report](nfr03-querybuilder-build-time-corrected.md).** The
> subject measured here builds a five-column `select()` list, an `orderBy`, a `limit` and an
> `offset` **in addition to** its five conditions — roughly twice the work NFR-03's
> "5-condition SELECT" describes. The ~23 µs figure below is real, but it is **not** the
> number NFR-03's budget applies to. Kept unedited as the record of what was measured and
> concluded at the time.

- **Date:** 2026-08-04
- **Version / commit:** v0.0.0 @ `b68a12e`
- **Environment:** 12th Gen Intel Core i7-12700, 31.7 GB RAM, Windows 11 Pro 10.0.26200,
  PHP 8.3.1 CLI, phpbench 1.4.3, OPcache **off**, JIT **off**, Xdebug **off**
- **Command:** `vendor/bin/phpbench run src/bench/php/d4np/utils/QueryBuilderBench.php --report=aggregate`

  On this machine the default PHP ini fails to load several unrelated Windows extension DLLs
  (`fileinfo`, `oci8_12c`, `pdo_firebird`, `zip`), and their startup warnings pollute phpbench's
  own environment-probe subprocess (the same issue item 3.5 documented). `--php-disable-ini`
  works around it, but this benchmark specifically needs `pdo_sqlite` (unlike items 3.5's, which
  needed no extension at all), so the workaround here is `--php-disable-ini` plus
  `--php-config='{"extension_dir":"...","extension":"pdo_sqlite"}'` to load exactly one clean
  extension. **Local diagnostic only** — CI's Linux runner has no broken extensions and runs
  `vendor/bin/phpbench run --report=aggregate` with neither flag.

## Scenario

Spec **NFR-03**: *"QueryBuilder: 5-condition SELECT builds in ≤ 10 µs; 0 queries executed at build
time."* `QueryBuilderBench::benchBuildFiveConditionSelect` builds a query with 5 `select()`
columns, 5 `WHERE`-family conditions (`where` ×3, `whereNotNull`, `whereIn`), one `orderBy`, and
`limit`/`offset`, then calls `toSql()` and `bindings()` — the two methods that constitute
"building". `get()`/`first()` are deliberately never called, since either would execute a real
statement and conflate the cost being measured with I/O.

The "0 queries" half is **not** measured here — timing a benchmark cannot prove an absence. It is
asserted directly in `QueryBuilderTest::testBuildingNeverRunsAQuery()`, which installs the same
`QueryLog`/`LoggedStatement` fixture item 4.4 built for T-02 and asserts the log stays empty
across the identical sequence of calls this benchmark times.

## Results

Two independent runs:

| Metric | Run 1 | Run 2 | Spread |
|--------|-------|-------|--------|
| `benchBuildFiveConditionSelect` (mode) | 24.074 µs | 22.719 µs | ±2.34% / ±2.10% |
| budget | ≤ 10 µs | ≤ 10 µs | — |

**~23–24 µs against a ≤ 10 µs budget — over, by roughly 2.3×, on this machine.**

`testBuildingNeverRunsAQuery` passes: 0 statements logged across the same call sequence. Verified
non-vacuous by planting an accidental `$this->connection->select(...)` inside `toSql()` and
confirming the test fails.

## Interpretation

**Where the cost comes from, measured rather than assumed.** A standalone probe isolated the
contributors:

| operation | µs |
|---|---|
| `DatabaseConnection::driver()` alone | 0.20 |
| `QueryBuilder` constructor (1 identifier: the table) | 0.95 |
| constructor + `select()` of 5 columns | 5.43 |
| the full benchmarked build (12 identifiers total: table, 5 select columns, 4 where/whereIn/whereNotNull columns, 1 orderBy column) | 17.6 |

The cost scales roughly linearly with the number of identifiers quoted, at **≈1 µs per
identifier** (driver lookup + allowlist regex + quote-and-double). `QueryBuilder`'s immutability
(ADR-0015: every fluent method returns `clone $this`) adds a clone per call on top. Neither is a
defect — the allowlist is the security mechanism ADR-0015 committed to, and immutability is a
stated design property — but a 12-identifier, 8-call query simply has more of both than a 10 µs
budget leaves room for.

**This is the same shape as item 3.5's NFR-01 finding**, and is handled the same way, per ADR-0011:
measured honestly, not silently absorbed into a lower number and not fixed under this item's own
route. Unlike NFR-01, NFR-03 has no relative-ratio clause to fall back on for a hardware-
independent assertion — it is a bare absolute figure, which ties it to spec NFR-06's reference
machine (a Ryzen 7 5800X) and methodology far more tightly than this run reflects. Establishing a
real baseline there, and gating regressions against it, is roadmap item **7.1**'s job, unchanged.

**The follow-up is roadmap item 4.6**: investigate reducing per-identifier quoting and per-call
clone overhead without weakening the allowlist or the immutability guarantee — most likely by
quoting each identifier once, at the point it is first accepted, rather than only at `toSql()`
time (which already happens once per identifier today, so the more promising angle is caching the
`driver()` value the constructor already resolves once, rather than recomputing per quote call).
That investigation needs its own measure-first pass and is not attempted here.

## Reproduce

```bash
composer install && vendor/bin/phpbench run src/bench/php/d4np/utils/QueryBuilderBench.php --report=aggregate --dump-file=build/logs/qb-bench.xml
```

The "0 queries executed" half:

```bash
vendor/bin/phpunit --filter testBuildingNeverRunsAQuery
```
