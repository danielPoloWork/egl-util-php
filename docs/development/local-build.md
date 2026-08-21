# Local Build & Test

How to build, test, and check `egl-util-php` on your machine. CI runs the same commands
on Linux (PHP 8.1, 8.2, 8.3); reproducing them locally avoids a red round-trip.

## Prerequisites

- **PHP 8.1+** toolchain.
- **Build system:** Composer (PSR-4 autoload).
- **Package manager:** Composer (composer.lock committed).
- **Formatter / linter:** PHP-CS-Fixer (PSR-12), PHPStan (max level).
- **Docs:** phpDocumentor (for the API docs build).

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
