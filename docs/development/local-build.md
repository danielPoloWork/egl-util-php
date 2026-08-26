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

## The wire-capture mail leg (issue #101)

T-10's fourth leg asserts what mail actually puts on the wire, and needs a receiver a checkout
cannot provision. With no `EGL_TEST_MAILPIT_URL` set it **skips**, so `vendor/bin/phpunit` behaves
exactly as it did. To run it:

```bash
docker run -d -p 1025:1025 -p 8025:8025 axllent/mailpit
```

Then start PHP with `mail()` pointed at a relay into that sink — `sendmail_path` is `PHP_INI_SYSTEM`,
so it cannot be set from the suite — and run the group:

```bash
EGL_TEST_MAILPIT_URL=http://127.0.0.1:8025 vendor/bin/phpunit --group mail-wire
```

CI adds `--fail-on-skipped`, which is what makes a missing variable red rather than green. **The skip
is raised per test, from `setUp()`, and must stay there:** a skip raised from `setUpBeforeClass()`
becomes a skipped *suite* with zero executed tests, and `--fail-on-skipped` exits 0 on it (so does
`--fail-on-empty-test-suite`) — see [ADR-0078](../adr/0078-a-wire-witness-for-t10-and-the-receiver-that-rewrites-the-evidence.md) §2.

**Before adding an assertion, read that ADR's §1.** Mailpit rewrites the message it stores: it
prepends a synthetic `Bcc:` header naming any envelope recipient the headers omit. So "no `Bcc:`
header survived" fails against a pipeline that is working perfectly, and the correct assertion is its
inverse.

## The randomized-order CI cell (issue #100)

`ci.yml`'s `build` job runs one extra matrix cell — `php-8.3 / random-order` — with
`vendor/bin/phpunit --order-by=random`, alongside the three default-order cells (PHP 8.1, 8.2,
8.3) that always run in declaration order. PHPUnit prints `Random Seed: <N>` in its own output
header whenever `--order-by=random` is used; reproduce a specific run locally with:

```bash
vendor/bin/phpunit --order-by=random --random-order-seed=<N>
```

**A failure in that cell alone is not a flake to re-run.** The three default-order cells already
prove the suite passes; if only `random-order` goes red, a test's outcome changed with the order it
ran in. **Do not re-run it into silence.** Reproduce with the printed seed, and expect one of two
diagnoses — they are not the same problem and do not have the same fix:

1. **Coupling.** Shared static state, a filesystem leftover from an earlier test, an assumption about
   what ran before — or a **shared external process** one test leaves busy. Fix the shared state.
2. **Timing fragility.** A test whose wall-clock margin is thin enough that its neighbours' load
   decides the outcome. It would flake in declaration order too, given a busy enough runner. Widen
   the margin.

**The two look identical in the failure output and are told apart by the seed.** Re-run the printed
seed: a failure that reproduces every time is coupling, because the order is fixed; one that comes
and goes on the same seed is timing.

The cell's first real failure is the worked example, and it was the first kind. Seed `1787753886`
failed **deterministically** — three times out of three, and on unmodified code, which is what
established it as pre-existing rather than introduced. `HttpClientLiveTest`'s tests share one
`php -S` origin, and **`php -S` is single-threaded.** The silent-origin test's client gives up after
0.4 s while the origin sleeps on for 1.6 s, so ~1.2 s of server-side sleep outlived the request that
started it — longer than a neighbouring test's entire budget. Any order that scheduled the drip test
straight after the silent one therefore failed, with the drip test's `fopen()` timing out against a
server that was still asleep on someone else's request. It reported `produced no response` instead of
`total time budget`, which reads like a bug in the client and was neither.

The fix was to stop the origin over-sleeping (0.8 s, still twice the timeout it exists to exceed),
not to widen the drip test's margin — the margin was a symptom. Worth internalising as the general
shape: **a fixture that keeps working after the test abandoned it is shared state**, even though it
looks like nothing more than a slow response.

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
