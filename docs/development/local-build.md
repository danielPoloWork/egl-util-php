# Local Build & Test

How to build, test, and check `egl-util-php` on your machine. CI runs the same commands
on Linux (PHP 8.1, 8.2, 8.3); reproducing them locally avoids a red round-trip.

## Prerequisites

- **PHP 8.1+** toolchain.
- **Build system:** Composer (PSR-4 autoload).
- **Package manager:** Composer (composer.lock committed).
- **Formatter / linter:** PHP-CS-Fixer (PSR-12), PHPStan (max level).
- **Docs:** phpDocumentor **v3.7.1**, fetched as a PHAR rather than installed as a dependency
  (ADR-0031's stance, ADR-0070). Not required to work on the code — CI builds the reference on
  every PR — but to reproduce that build locally:

  ```bash
  curl -sSfL -o phpDocumentor.phar https://github.com/phpDocumentor/phpDocumentor/releases/download/v3.7.1/phpDocumentor.phar
  php phpDocumentor.phar --config=phpdoc.dist.xml --no-interaction
  python tools/api_docs_gate.py build/api
  ```

  **Do not use `latest`**: v3.10.0 crashes on startup before printing its own `--version`.
  And run the gate — phpDocumentor exits `0` even when its report names errors, so its exit
  status is not the verdict.

## One-time setup

```bash
git config core.hooksPath .githooks
```

Git does not install hooks from a checkout, deliberately, so this is per clone. It enables the
pre-push guard that refuses an unsigned or lightweight `v*.*.*` release tag before it reaches the
remote (issue #115, ADR-0032) and is silent on every other push. Not needed unless you cut releases,
and harmless if you do not.

## Commands

```bash
# Build
composer install --optimize-autoloader

# Test
vendor/bin/phpunit

# Format check
vendor/bin/php-cs-fixer fix --dry-run --diff

# Lint
vendor/bin/phpstan analyse

# Benchmark
vendor/bin/phpbench run --report=aggregate

# Cross-artifact congruence (run before drafting any PR)
python tools/consistency_lint.py
```

## The randomized-order CI cell (issue #100)

`ci.yml`'s `build` job runs one extra matrix cell — `php-8.3 / random-order` — with
`vendor/bin/phpunit --order-by=random`, alongside the three default-order cells (PHP 8.1, 8.2,
8.3) that always run in declaration order. PHPUnit prints `Random Seed: <N>` in its own output
header whenever `--order-by=random` is used; reproduce a specific run locally with:

```bash
vendor/bin/phpunit --order-by=random --random-order-seed=<N>
```

**A failure in that cell alone is coupling, not flake.** The three default-order cells already
prove the suite passes; if only `random-order` goes red, a test's outcome changed with the order
it ran in — shared static state, a filesystem leftover from an earlier test, or an assumption
about what ran before it. **Do not re-run it into silence.** Reproduce with the printed seed,
find the shared state, and fix the coupling (or, if the two tests are asserting the same global
resource by design, make that assumption explicit rather than order-dependent).

## Before you open a PR

1. `vendor/bin/php-cs-fixer fix --dry-run --diff` and `vendor/bin/phpstan analyse` are clean.
2. `vendor/bin/phpunit` passes; new/changed behavior is covered (≥ 90% line, PHPStan max level).
   **CI now holds the diff to that floor as well as the whole tree** — `diff_coverage_gate.py`
   intersects the coverage report with the lines you touched (issue #109, ADR-0068), so an
   untested addition can no longer hide inside the project's headroom. It needs a coverage
   driver, so it runs on the runner rather than here; if a changed line provably cannot execute,
   annotate it `@codeCoverageIgnore` with the reason rather than leaving it uncovered.
3. `python tools/consistency_lint.py` passes — its **`links`** check resolves every relative
   link, `#anchor` and quoted `§ "Section"` reference in tracked Markdown, so a docs-only
   change is not exempt from it (ADR-0069). Read the line it prints about what it does *not*
   resolve rather than assuming a green run vouched for everything. The two tool proofs,
   `python tools/tests/verify_diff_coverage_gate.py` and
   `python tools/tests/verify_link_check.py`, need only git and Python — so unlike the coverage
   gates themselves they run on any machine.
4. The relevant docs (README, ROADMAP, ADRs, patterns, changelog) are updated in the same
   PR — see [`../workflow/documentation.md`](../workflow/documentation.md).
