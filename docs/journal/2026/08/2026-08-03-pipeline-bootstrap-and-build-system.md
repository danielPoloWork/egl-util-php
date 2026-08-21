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

## Item 1.9 — the benchmark job hardened, and the CI matrix finally all green

The last red from item 1.1. Three defects, one root cause: the profile's *matrix-oriented*
setup steps were injected verbatim into a job that declares no matrix.

- **It failed instead of waiting.** `vendor/bin/phpbench` is a dev dependency no M1 item
  introduces, so the job could only go red until the harness lands (3.5 / 7.1). Now guarded
  the same way as `layering` and `mutation` — a first step probes for `phpbench.json`
  (or `.dist`) and every later step is conditional on it, emitting a `::notice` while pending.
  Lesson L-0010's rule, applied to the one job that had escaped it.
- **`php-version` read `matrix.toolchain` in a job with no matrix.** Verified rather than
  reasoned: on run 30811447268 the job installed **PHP 8.3.33** — the ternary had fallen
  through to its final branch, because `matrix` is null here. The value it landed on happens
  to be the one spec NFR-06 requires, which is *worse* than a visible error: right by
  accident, and it would have silently selected the wrong interpreter the moment anyone gave
  this job a matrix. Now a literal `'8.3'` citing NFR-06.
- **`coverage: pcov` on a performance job.** Coverage instrumentation distorts the very
  measurements the job exists to take. Now `coverage: none`.

The remaining NFR-06 methodology (10 iterations × 100 revs, 5% retry threshold, OPcache + JIT
off, >10% regression fails) belongs to the harness in item 7.1 and is noted in the job's own
comment so it is not silently forgotten.

**Filed, not fixed here (§10):** item **1.10** — the same action is pinned two ways in one
workflow (`actions/checkout` by SHA in the template-generated steps, by floating tag `@v7` in
the manifest-authored quality jobs). Under the enterprise posture a supply-chain choice is a
security-relevant decision and needs an ADR. Checked first per lesson L-0004 (before filing an
apparent inconsistency, see whether a governing ADR already decided it): this repo has only
ADR-0001 and ADR-0002, and EADOS's ADR-0009 governs the *factory*, not this self-governing
repository — so it is genuinely open, not a re-discovered trade-off.

## Item 1.5 + 1.8 — the version constant, and the lint bug it exposed

Fixed **1.8 before 1.5**, not after: creating `Version.php` first would have made
`version-lockstep` compare against the doubled path silently returning "use the README badge"
(the check falls back to the badge whenever the configured file does not exist) — the exact
lesson **L-0008** shape, a gate that stays green while asserting nothing.

Root cause, confirmed by reading the template rather than guessing: `templates/tools/consistency_lint.py`
composes `"{{SRC_MAIN}}/{{VERSION_FILE}}"`. The `toolchain.version_file` field this project's
manifest set at scaffold (RFC-0001 A-4) was the **full path**
(`src/main/php/d4np/utils/Version.php`) where the template already prepends `SRC_MAIN` — the
profile's own reference value is just `"Version.php"`. Fixed in `orchestrator/project.yaml`
(the source of truth, not the generated file) and re-rendered `tools/consistency_lint.py` to
staging; diffed staged output against the working copy (after stripping the CRLF this Windows
checkout adds) to confirm the version-file line was the *only* change before applying it.

Then **1.5**: `src/main/php/d4np/utils/Version.php` — `Version::VERSION = '0.0.0'`, matching
the manifest's `governance.start_version` and the README badge. Proved the now-fixed gate
non-vacuous the same way as the earlier items: flipped the constant to an impossible value
(`9.9.9`), confirmed `version-lockstep` reports exactly `README badge v0.0.0 != version file
... (9.9.9)`, reverted, confirmed clean. Also loaded the class through the real autoloader
(`D4np\Utils\Version::VERSION` resolves to `0.0.0`) rather than trusting the file exists.

**Bookkeeping, folded into this same PR:** flipped **1.4** and **1.7** — both were delivered
by PR #3 / the #7 fix commit and are now verified *executing*, not just present (the CI matrix
runs for real, `lowest-deps` passes) — closing the open question from the last two entries.
**Milestone 1 is complete**: every M1 item is checked except **1.10** (the action-pinning ADR,
filed, deliberately open).

## Item 1.10 — ADR-0003, the CI action-pinning policy

The first ADR this project decided for itself (0001 and 0002 came seeded from the factory).

**Route mismatch, recorded rather than quietly accepted.** The item was *filed* as
`standard / medium` — wrong, and corrected in the roadmap when it was taken: `os/routing`
resolves `label:adr` to **frontier-reasoning / extra**, because an item whose deliverable *is*
a decision is decision-heavy by definition. `route_advice.py --check` confirmed the session
model (`opus-5`, standard tier) sits below that route. Surfaced to the maintainer, who holds
model authority (ADR-0017); work proceeded on their standing decision.

**The decision:** every `uses:` in this repository's workflows is pinned to a 40-character
commit SHA with a comment naming the version, no exceptions by publisher. The comment must be
**true** — resolved from the upstream repository, never copied from another local file — and
Dependabot (already configured for `github-actions`, and already exercised: PR #4) owns the
routine updates.

The argument that settled it is that **a version tag is not immutable either**. The intuitive
middle ground `@v7.0.1` *looks* pinned and is an ordinary git ref the publisher can force-push;
it narrows the window without closing it. Verified while writing the ADR:
`shivammathur/setup-php@v2` resolves today to `f3e473d1…`, the same commit as release
`2.37.2` — the value is fine *today*, which is exactly the property nobody can rely on
tomorrow.

**Applied and verified, in that order:**
- 20 `uses:` references across both workflows now carry a SHA; a grep for any `@` reference not
  matching `[0-9a-f]{40}` returns nothing.
- Every version comment was then **re-resolved against the GitHub API** and compared to the SHA
  actually written — all four distinct actions truthful. This is lesson **L-0011**'s rule
  applied at the moment the pins were introduced, not deferred: a cross-file consistency check
  cannot see an error applied uniformly, so the check has to resolve toward the external
  source.
- Both workflows re-parsed as YAML (9 jobs + 1) to confirm no structural regression from the
  bulk edit.

**Filed, not claimed:** the policy is enforced by **review, not by a gate** —
`tools/consistency_lint.py` does not inspect workflow files. The ADR says so in its own
*Consequences* rather than implying coverage that does not exist, and item **1.11** files the
mechanical check.

## Item 1.11 — the gate for ADR-0003, and what it can honestly observe

`tools/action_pin_lint.py`, stdlib-only, wired into the `consistency / lint` CI job. Kept as
its own tool rather than folded into `consistency_lint.py`: that file is generated from the
EADOS templates and is about cross-artifact congruence, while this is a supply-chain policy
check with an entirely different failure mode.

The design point worth recording is **the split in observability**:

- `pin-shape` is **offline and always runs** — every `uses:` names a 40-hex SHA and carries a
  version comment. Pure text.
- `pin-label-truth` **needs the network** and runs only with `--verify-upstream` (which CI
  passes) — it resolves each version comment against its own upstream repository.

These are not interchangeable, and the tool never lets the cheap half stand in for the whole:
a run without `--verify-upstream` prints, in its own output, that comment truthfulness went
unverified. That is lesson **L-0006**'s rule — *a checkpoint that can observe only part of what
it governs must state the unobservable part in its own output* — applied at design time rather
than discovered later.

**Proved it can fail before trusting it,** three ways, each restored afterwards with
`git checkout --` and the tree confirmed clean:

1. A mutable tag in place of a SHA → `pin-shape` fails, naming the action and the reason.
2. A SHA with its version comment removed → `pin-shape` fails.
3. **A comment that lies, applied uniformly** — every `# v7.0.1` rewritten to `# v7.0.0` while
   the SHAs stayed correct. The offline run **passes** (the shape genuinely is fine) and says
   it did not check truthfulness; the `--verify-upstream` run catches all nine occurrences and
   prints the SHA the claimed version actually resolves to upstream. This is exactly lesson
   **L-0011**'s scenario — *a cross-file consistency check cannot see an error applied
   consistently* — reproduced on demand, which is what makes the assertion non-vacuous rather
   than merely green.

ADR-0003's *Consequences* section was updated in the same PR: it had said the policy was
enforced by review rather than by a gate, and that is no longer true. The paragraph records
that the gap existed and was closed, rather than being rewritten to hide the interval.

## How the next session resumes

1. **Milestone 2 (`v0.2.0`) — Support layer**: the exception hierarchy, `Str`, `File`, `Env`,
   `Json`, and the shared reflection-metadata cache. The first items with actual behavior to
   write; T-05 property tests land alongside them.
2. **One-time admin** — [`docs/workflow/github-setup.md`](../../../workflow/github-setup.md):
   branch protection on `master`, squash-only, label import from `.github/labels.yml`. The
   label import matters more now: every PR in this milestone has carried `enhancement` or
   `documentation` as a stand-in because the repo's own type labels (`build`, `test`, `ci`,
   `docs`) do not exist yet.
