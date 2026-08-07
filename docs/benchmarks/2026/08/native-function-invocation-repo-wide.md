# Benchmark Report: prefixing internal function calls, repo-wide

- **Date:** 2026-08-07
- **Version / commit:** v0.0.0 — base `4b0fdec` (`master`) vs head `084def7` (item **10.12**)
- **Environment:** GitHub Actions `ubuntu-24.04`, PHP **8.3.33** CLI, **OPcache and JIT off** —
  asserted by the job itself, not assumed: `bench-env: OK — PHP 8.3.33, opcache.enable_cli='0',
  opcache.jit='disable' (spec NFR-06)`.
- **Command:** the CI `benchmark / reproducible perf` job — `vendor/bin/phpbench run
  --report=aggregate` at head, then the same suite against the base checkout in a `git worktree`
  **on the same runner, in the same job** (ADR-0030), compared by `tools/bench_regression_gate.py`.

## Scenario

Item 10.11 measured PHP's **namespace fallback lookup** on unqualified internal function calls —
inside a namespace, `trim()` is resolved by trying `D4np\Utils\Persistence\trim` before the global
`trim` — at 13.6 µs per 100 rows in one hot method, on a Windows development box. Item 10.12 was
filed to decide the repo-wide policy, and filed with an explicit instruction: **measure on CI, not
locally**, because that same item had just caught the dev box overstating this class of cost by
~8×.

The change measured here enables PHP-CS-Fixer's `native_function_invocation`
(`include: ['@all']`, `scope: 'namespaced'`, `strict: true`) — 95 files, 795 insertions.

**The measurement carries its own control.** `src/bench` is outside the CS-Fixer finder, so
benchmark-internal code is unprefixed on *both* sides of the A/B. Two subjects are therefore
expected not to move at all, and they are what says whether anything else moved for real.

## Results

Base = `master` (unqualified), head = prefixed, same runner, same job. µs unless noted.

| Subject | base | head | Δ |
|---|---|---|---|
| **`RowNormalizerBench::benchNormalizeHundredRows`** | 22.798 | **17.322** | **−24.02%** |
| `GatewayBench::benchGatewayFetchNormalizeHydrate` | 150.718 | 144.723 | **−3.98%** |
| `ContainerBench::benchFirstAutowiredResolve` | 7.464 | 7.222 | −3.25% |
| `QueryBuilderBench::benchBuildRealisticPagedQuery` | 6.924 | 6.700 | −3.23% |
| `ContainerBench::benchWarmInstanceResolve` | 0.056 | 0.054 | −3.01% |
| `ContainerBench::benchFirstAutowiredResolveOfASingleClass` | 1.092 | 1.066 | −2.37% |
| `QueryBuilderBench::benchBuildFiveConditionSelect` | 3.803 | 3.717 | −2.25% |
| `HydrationBench::benchHydrateWarm` | 0.968 | 0.951 | −1.82% |
| `CsvBench::benchWriteTenThousandByTen` | 20178.693 | 20054.425 | −0.62% |
| `MemoryBench::benchHydrateTenThousand` | 10081.630 | 10035.691 | −0.46% |
| `HashBench::benchMakeBcrypt` | 57085.074 | 57064.135 | −0.04% |
| `ContainerBench::benchWarmSingletonResolve` | 0.060 | 0.061 | +1.83% |
| `FileSequenceBench::benchSequenceNext` (excluded, ADR-0045) | 164.355 | 169.252 | +2.98% |

**The controls — benchmark-internal code, unprefixed on both sides:**

| Control subject | base | head | Δ |
|---|---|---|---|
| `RowNormalizerBench::benchInlineTrimHundredRows` | 19.843 | 19.827 | **−0.08%** |
| `GatewayBench::benchHandWrittenPdoLoop` | 87.022 | 87.410 | +0.45% |
| `HydrationBench::benchManualConstruction` | 0.399 | 0.393 | −1.55% |

**Gates:** `bench-regression` OK (nothing regressed >10%); `bench-budget` OK; NFR-01
`benchHydrateWarm / benchManualConstruction` = **2.42×** (budget 3×, unchanged); NFR-09
`benchGatewayFetchNormalizeHydrate / benchHandWrittenPdoLoop` = **1.66×** (budget 2.5×, was 1.73×).

## Interpretation

**The noise band comes from the controls, and it is wider than most of the wins.** Code the rule
cannot touch moved between −1.55% and +2.98%. So the small negative deltas — Container,
QueryBuilder, Hydration, at 1.8–3.3% — are individually **inside** that band and cannot be claimed
one by one.

What *can* be claimed is the pattern. Eleven of thirteen prefixable subjects moved negative, while
the three unprefixable controls scattered in both directions; a rule with no effect does not
produce that lean. And two results sit clearly outside the band:

- **`RowNormalizer::normalize()` at −24.02%** — an order of magnitude above the noise. This is the
  method item 10.11 had already optimized, and it is the shape that explains everything else: a
  tight loop calling internal functions once per value, where the fallback lookup is a
  double-digit share of the work.
- **The gateway path at −3.98%**, which is that same method plus everything around it.

**The benefit is therefore concentrated, not spread.** That matches the diff: `sprintf()` is the
most-prefixed call in the change (~93 sites), almost entirely formatting exception messages, where
a 36 ns lookup on a cold path buys nothing. The rule pays where a loop calls internal functions
per item, and is inert everywhere else — which is most of the 95 files.

**Local overstated it, as item 10.11 predicted it would.** The dev box put this method's fallback
cost at 13.6 µs per 100 rows; CI measures the whole change on that subject at **5.48 µs**
(22.798 → 17.322). The direction was right, the magnitude was 2.5× too high — recorded because
item 10.12 was filed on the local number and the correction is the point of having measured again.

**Two caveats stated rather than buried.**

1. **NFR-09's ratio improved partly by asymmetry.** The library side is now prefixed and its
   hand-written comparator, living in `src/bench`, is not — so 1.73× → 1.66× is not purely the
   library getting faster relative to equivalent code. It is arguably the *more* realistic
   comparison (a consumer writing that loop in their own namespaced application would not prefix
   it either), but it is a change in what the number means, and NFR-09's methodology is the spec's
   to own (ADR-0040). Named as an open question in ADR-0048, not settled here.
2. **This is the OPcache-off environment NFR-06 pins.** Consumers running OPcache — most
   production deployments — get a different distribution: the fallback lookup is cheaper there,
   while prefixed calls in the `@compiler_optimized` set become eligible for opcode substitution,
   which unprefixed calls are not. Neither effect was measured here, and no claim is made about
   the net for such a deployment.

## Reproduce

```bash
# The measurement is the CI job itself; locally it is one branch against the other:
git checkout master && composer install --optimize-autoloader
vendor/bin/phpbench run --report=aggregate --dump-file=base.xml
git checkout perf/native-function-invocation && composer install --optimize-autoloader
vendor/bin/phpbench run --report=aggregate --dump-file=head.xml
python tools/bench_regression_gate.py base.xml head.xml --max-regression 10
```

Note the environment gate: `php tools/assert_bench_env.php` must pass first (OPcache and JIT off,
spec NFR-06), or the numbers describe a different interpreter than the budgets do.
