# Benchmark Report: NFR-01 DTO hydration — compiled closure vs. interpreted hydrator

- **Date:** 2026-08-04
- **Version / commit:** v0.0.0 @ `9c6828a` (baseline) → this branch (`perf/hydrator-compiled-closure`)
- **Environment:** 12th Gen Intel Core i7-12700, 31.7 GB RAM, Windows 11 Pro 10.0.26200,
  PHP 8.3.1 CLI, phpbench 1.4.3, OPcache **off**, JIT **off**, Xdebug **off**
  (`--php-disable-ini`; spec NFR-06's methodology, on this machine rather than its named
  reference machine — see Interpretation)
- **Command:** `vendor/bin/phpbench run src/bench/php/d4np/utils/HydrationBench.php --report=aggregate`

## Scenario

Spec **NFR-01** budgets hydrating a DTO of 10 scalar properties, warm (reflection already cached),
at **≤ 5 µs** and **≤ 3× manual constructor assignment**. `HydrationBench` measures both halves at
once: `benchHydrateWarm` calls `TenScalarPropsDto::fromArray()`, and `benchManualConstruction`
builds the identical object by hand from the identical payload, so the ratio cancels out the
machine's absolute clock speed.

Roadmap item **3.5** recorded the first measurement of this and found the ratio ~5× over budget.
Item **3.7** — this report — is the change that closes it.

Methodology per NFR-06: 10 iterations × 100 revs, 5% retry threshold, PHP 8.3, OPcache and JIT off.

## Results

**Before** (interpreted hydrator, commit `9c6828a`):

| Metric | Value | Spread |
|--------|-------|--------|
| `benchHydrateWarm` (mode) | 14.155 µs | ±1.48% rstdev |
| `benchManualConstruction` (mode) | 0.919 µs | ±1.30% rstdev |
| **ratio** | **15.40×** | budget ≤ 3× ❌ |
| absolute | 14.155 µs | budget ≤ 5 µs ❌ |

**After** (compiled closure for the eligible shape, ADR-0013), two independent runs:

| Metric | Run 1 | Run 2 | Spread |
|--------|-------|-------|--------|
| `benchHydrateWarm` (mode) | 2.511 µs | 2.524 µs | ±1.60% / ±1.87% |
| `benchManualConstruction` (mode) | 0.915 µs | 0.919 µs | ±1.91% / ±1.76% |
| **ratio** | **2.74×** | **2.75×** | budget ≤ 3× ✅ |
| absolute | 2.511 µs | 2.524 µs | budget ≤ 5 µs ✅ |

**Headline: 15.40× → 2.74×** (5.6× faster), meeting both halves of NFR-01.

NFR-04's companion benchmark improved as a side effect, without its budget being touched:
`MemoryBench::benchHydrateTenThousand` went **149.693 ms → 37.786 ms**, and its 16 MiB peak
assertion still passes.

### The alternatives that were measured and rejected

The change was chosen from measurement, not preference. Every approach that does **not** generate
code lands above budget:

| approach | µs/op | ratio | meets ≤3×? |
|---|---|---|---|
| interpreted (the hydrator as it was) | 13.86 | 16.6× | ✗ |
| interpreted, all avoidable waste removed | 3.97 | 4.80× | ✗ |
| one pre-built closure per parameter (no `eval`) | 3.31 | 4.00× | ✗ |
| **generated closure** | **1.93** | **2.28×** | ✓ |

Those three rejected prototypes were *optimistic* — none carried path building, exception
construction, or the nested-DTO branches production needs — so their real numbers would be worse,
not better. Two secondary findings from the same profiling run, both counter-intuitive enough to
be worth recording: iterating `ParameterMetadata` **objects** is faster than iterating flattened
plain arrays (0.63 µs vs 1.59 µs per pass), so "flatten the metadata" would have been a
pessimisation; and named-argument spread (`new $c(...$named)`) costs roughly **twice** a positional
call (1.49 µs vs 0.80 µs), which is why eligibility excludes defaulted parameters.

### Cost of the mechanism

`eval()`'d code is not cached by OPcache, so the closure is built once per class **per process**:

| Metric | Value |
|--------|-------|
| compile cost (per class, per process) | 66.9 µs |
| saving per hydration | 12.3 µs |
| **break-even** | **≈5.5 hydrations of one class per process** |

## Interpretation

Both halves of NFR-01 are met, with ~9% headroom on the ratio and ~50% on the absolute figure.

**The ratio is the trustworthy half of this result.** Both subjects run in the same process on the
same hardware in the same invocation, so the machine cancels out — which matters here because this
run is on an i7-12700, not the Ryzen 7 5800X that NFR-06 names as the reference machine. The
absolute 2.511 µs should be read as "comfortably inside 5 µs on a machine of this class", not as a
number directly comparable to the spec's reference. Establishing an absolute baseline on reference
hardware, and gating regressions against it, remains roadmap item **7.1**.

**What is compiled is narrow, and the headline number only covers that shape.** Only an all-scalar
constructor (non-variadic builtin `int`/`float`/`string`/`bool`, no defaults) is eligible — which
is exactly the shape NFR-01 specifies, but it means a DTO with a nested DTO, a `Collection`, an
enum, a union or a defaulted parameter still runs the interpreter and is still ~14 µs. That is
stated plainly rather than left for a reader to infer from a headline: this change meets the
spec's stated budget for the spec's stated shape, and does not make every DTO fast.

**Caveats.** Measured on Windows with OPcache off, on an otherwise-idle but not
specially-quiesced machine; rstdev stayed under 2% across all reported runs and phpbench's 5%
retry threshold rejected and re-ran the one variant that exceeded it, so the run-to-run agreement
(2.74× vs 2.75×) is the better evidence of stability than any single figure.

## Reproduce

```bash
composer install && vendor/bin/phpbench run src/bench/php/d4np/utils/HydrationBench.php --report=aggregate --dump-file=build/logs/after.xml && python tools/bench_ratio_gate.py build/logs/after.xml --numerator benchHydrateWarm --denominator benchManualConstruction --max-ratio 3
```

To measure the interpreter instead of the compiled path, construct the hydrator with compilation
declined — the same switch `HydrationParityTest` uses:

```bash
php -r "require 'vendor/autoload.php'; \$h = new D4np\Utils\Dto\Hydrator(new D4np\Utils\Support\ReflectionCache(), new D4np\Utils\Dto\HydrationCompiler(false));"
```
