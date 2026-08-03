# 2026-08-03 — EADOS pipeline run and the Composer build system

## What happened

The repository went from an empty shell (README stub + LICENSE) to a governed project with a
working build system, in one session, through the full EADOS delivery pipeline.

| Phase | Outcome | Landed as |
|---|---|---|
| `init` | Framed the project: library, domain `software`, posture **enterprise**; manifest skeleton | — |
| `design` | Imported the pre-existing reviewed spec (`.specs/d4np-php.md` v2.0, 25 items + 2 ADRs) as **RFC-0001**, reviewed by the `reviewer` + `enterprise-architect` roles (18 findings, 0 blockers, all resolved), approved | PR #1 |
| `plan` | Negotiated the **M1–M7** roadmap with sizes + advisory routes; GitHub milestones `v0.1.0`–`v0.7.0` | PR #2 |
| `scaffold` | Rendered the governed repository (49 files: contracts, docs system, CI, source tree, consistency lint) | PR #3 |
| — | Dependabot bumped the GitHub Actions group | PR #4 |
| item 1.1 | Composer build system + component-group skeleton | this PR |

## Decisions taken (maintainer, precedence layer 1)

- **Namespace `D4np\Utils\` inside package `egl/utils`** — a deliberate mixed-vendor choice.
  The tech-lead's dissent (consumer-visible, MAJOR-break to reverse, discoverability cost) is
  recorded in RFC-0001 *Alternatives #5*; the decision stands.
- **Release mapping** — one minor per milestone (M1 → `v0.1.0` … M7 → `v0.7.0`); the 1.0.0
  decision is a dedicated post-M7 API-freeze review, not an automatic bump.
- **Item 1.1 ships alone, red CI accepted.** Landing `composer.json` flips the bootstrap guard
  and starts the toolchain jobs for real; without items 1.2 (PHPUnit) and 1.3 (cs-fixer /
  PHPStan configs) they fail. The agent proposed bundling M1 into one green PR and dissented
  on the same grounds as lesson L-0010 (a repo's first CI signal should not teach that red is
  normal); the maintainer chose the minimal scope. Recorded, not relitigated.

## Where the project stood after item 1.1 (PR #5, merged)

- **Green:** `consistency / lint`, `bootstrap / is the build system in place?`, and — thanks to
  the step-level config guard added during scaffold — `quality / deptrac layer rules` and
  `quality / infection mutation score`, which self-skip until their configs land.
- **Red, fixed by 1.2 + 1.3:** `build` (matrix ×3) and `lowest-deps` fail at `vendor/bin/phpunit`;
  `quality` fails at `php-cs-fixer`; `hygiene` fails at `composer normalize`.
- **Red, NOT fixed by 1.2 + 1.3:** `benchmark / reproducible perf` fails at `vendor/bin/phpbench`,
  a dev dependency no M1 item introduces. The agent's initial prediction of the red set omitted
  this job and wrongly claimed 1.2 + 1.3 would restore a fully green matrix; corrected and
  filed as item **1.9**, which also records that the job's `php-version` expression reads
  `matrix.toolchain` in a job that declares no matrix.
- Verified locally: `composer validate --strict` passes; dependency resolution works
  (`psr/container` 2.0.2, `psr/log` 3.0.2); the PSR-4 prefix `D4np\Utils\` resolves to
  `src/main/php/d4np/utils/` and a probe class in `Support/` autoloads through it.

## Item 1.2 — PHPUnit wired, smoke suite green

Added `phpunit/phpunit` (^10.5) as a dev dependency, `autoload-dev` for the test namespace
(`D4np\Utils\Tests\` → `src/test/php/d4np/utils/`), and `phpunit.xml.dist` (source coverage
scoped to `src/main/php/d4np/utils/`, `failOnWarning`/`failOnRisky`/`failOnDeprecation` on).

`BootstrapTest` is the one smoke suite the item calls for — two tests proving the harness
itself is wired, deliberately silent on any component's behavior (that belongs to M2–M6):
the PHP version floor, and that Composer's autoloader resolves `D4np\Utils\` to the expected
directory (recovered from the SPL autoload stack, since PHPUnit's own bootstrap has already
consumed `vendor/autoload.php`'s first-load return value). Proved non-vacuous locally: flipped
the version-floor assertion to an impossible bound, watched it fail with the expected message,
reverted, watched it pass again.

**Effect on the red set from 1.1:** `build` ×3 and `lowest-deps` should now pass their
`vendor/bin/phpunit` step — verify on the PR run rather than assuming (the 1.1 prediction was
wrong once already). `quality`, `hygiene`, and `benchmark` are untouched by this item.

## Item 1.3 — formatter, linter, and composer normalize wired

Added `friendsofphp/php-cs-fixer` (^3.75, installed 3.95.18), `phpstan/phpstan` (initially
required at ^1.12 per the profile default, then upgraded to **^2.2** after the tool's own
deprecation notice — an enterprise repo should not launch on a stale major), and
`ergebnis/composer-normalize` (^2.44) as dev dependencies.

- **`.php-cs-fixer.dist.php`** — `@PSR12` + `@PSR12:risky`, `declare_strict_types`,
  `strict_comparison`/`strict_param`, import ordering, single quotes — scoped to
  `src/main/php/d4np/utils/` and `src/test/php/d4np/utils/`.
- **`phpstan.neon`** — `level: max`, same two paths, its own `tmpDir`.
- **`ergebnis/composer-normalize`** required an explicit `allow-plugins` entry (it ships a
  Composer plugin); added deliberately rather than answering the interactive prompt, since a
  plugin-execution decision under the enterprise posture belongs in a reviewable diff, not an
  interactive `y`.

**Verified non-vacuously, not just "the command exited 0":**
- PHPStan: planted a throwaway class returning a string where `int` was declared, confirmed
  `analyse` reports exactly that `return.type` error, removed it, confirmed clean again.
- PHP-CS-Fixer's only reported diff is a **Windows-local artifact**: `core.autocrlf=true`
  converts the repo's LF-stored files to CRLF on checkout (confirmed against `git show HEAD:`
  and a fresh detached worktree — both come back CRLF locally, LF in the object store), and
  the fixer's line-ending rule flags it. It will not reproduce on the Linux CI runner, which
  checks out LF by default. Added **`.gitattributes`** (`* text=auto eol=lf`) so this stops
  being local noise for any future Windows contributor, rather than leaving it to rediscover.
- `composer normalize --dry-run` found one real issue (require-dev block ordering) — fixed
  with `composer normalize`, re-checked clean.
- `composer validate --strict` and `composer audit` — both clean.

**Effect on the red set from 1.1:** `quality` (php-cs-fixer + phpstan) and `hygiene` (composer
normalize + audit) passed as predicted.

**A real defect the CI matrix caught.** The first push of this item broke `build /
php-8.1`: dependency resolution ran on the local machine's PHP 8.3, so the lock file picked
`symfony/console` et al. at `v7.4.x`, which require PHP `>=8.2` — silently incompatible with
the declared `php>=8.1` floor and the CI matrix's own 8.1 cell, until that cell actually ran.
This is exactly the gap `lowest-deps` exists to catch for *version* floors, but it doesn't
catch a *resolved-on-a-newer-interpreter* floor violation, because that job also runs on
whatever PHP its own step sets up. Fixed by pinning `config.platform.php` to `8.1.34` in
`composer.json` and re-running `composer update` — Composer then resolves (and future
`composer update` runs keep resolving) against the declared floor regardless of which PHP
version the maintainer's or CI's shell happens to run, not just the version installed when a
dependency was first added. `symfony/*` relocked from `v7.4.x` to `v6.4.x`; full local
verification (PHPUnit, PHPStan, PHP-CS-Fixer, `composer validate`/`normalize`/`audit`)
re-ran clean.

## How the next session resumes

1. **Item 1.5 + 1.8 together** — the version constant and the doubled `version_file` path in
   `tools/consistency_lint.py` `CONFIG`. Fixing 1.5 without 1.8 leaves `version-lockstep`
   silently disarmed (it would keep falling back to the README badge). Prove the gate can fail.
2. **Item 1.9** — the `benchmark` job; the only red from item 1.1 that 1.2 and 1.3 do not clear.
   At that point the CI matrix should be fully green.
3. **Bookkeeping** — items 1.4 and 1.7 were delivered by PR #3 (the CI matrix, the explicit
   toolchain→version map, the `--prefer-lowest` job) but their checkboxes are unflipped; the
   maintainer's call whether "stood up but never executed" counts as done.
4. **One-time admin** — [`docs/workflow/github-setup.md`](../../workflow/github-setup.md):
   branch protection on `master`, squash-only, label import from `.github/labels.yml`.
