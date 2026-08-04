# Benchmarks

Reproducible performance measurements for `egl-util-php`. Any performance claim in the
spec, README, or a PR must be backed by a benchmark here and by code under
`src/bench/php/d4np/utils/`. Numbers without a reproducible method
are not evidence.

## Methodology

- **Harness:** `Composer (PSR-4 autoload)` builds the bench target; run with `vendor/bin/phpbench run --report=aggregate`.
- **Environment:** record the machine (CPU, RAM, OS), the toolchain version, and the build
  configuration (release/optimized) with every result — a number without its environment is
  not comparable.
- **Discipline:** warm up, run multiple iterations, report a central tendency **and** spread
  (e.g. median + p99), and pin the commit SHA the run was taken at.
- **Regression gate:** the CI `benchmark` job runs the suite; a result is a regression only
  against a recorded baseline on comparable hardware (note when CI hardware is too noisy to
  gate and the run is informational).

## Results

One report per measured scenario, from [`template.md`](template.md). Keep the index newest-first.

| Date | Scenario | Version | Headline result | Report |
|------|----------|---------|-----------------|--------|
| 2026-08-04 | NFR-01 DTO hydration (10 scalar props), compiled closure vs. interpreter | v0.0.0 | **15.40× → 2.74×** manual construction (14.155 µs → 2.511 µs); NFR-01 met | [report](2026/08/nfr01-hydration-compiled-closure.md) |
