# ADR-0076: A nightly `composer audit`, a require-checker gate, and an SBOM on every release

- **Status:** Accepted
- **Date:** 2026-08-26
- **Deciders:** project architect (agent), maintainer
- **Related:** issue [#98](https://github.com/danielPoloWork/egl-util-php/issues/98) ·
  [ADR-0003](0003-pin-ci-actions-by-commit-sha.md) (pinned actions) ·
  [ADR-0031](0031-run-the-bc-checker-outside-the-dependency-graph-and-gate-breaks-by-bump.md) /
  [ADR-0040](0040-install-the-mutation-tester-outside-the-graph-and-keep-nfr07s-own-number.md)
  (the throwaway-install pattern this decision reuses, and the one case it does not) ·
  spec **NFR-07**, revision **r24**

## Context

Issue #98 is a four-item supply-chain hygiene batch filed by the Release Review Board's Build and
Release Engineer seat (2026-08-09):

1. **Scheduled `composer audit`.** `ci.yml`'s `hygiene` job already runs it, but only on a push or a
   PR diff — a CVE published against an already-vendored dependency during a quiet week goes
   unnoticed until the next commit, whenever that is.
2. **`maglnet/composer-require-checker` (or composer-unused) in CI.** Prove the `require` block
   matches actual symbol usage — the issue names `ext-fileinfo` as the example worth proving, not
   assuming.
3. **Renovate (or a Dependabot config extension) for `composer.lock` refresh PRs.** A committed lock
   in a library needs a deliberate refresh cadence or it drifts stale silently.
4. **A CycloneDX SBOM generated in `release.yml` and attached to the draft GitHub Release** — which
   the issue notes also makes `docs/workflow/release.md`'s boundary-table row "Build & attach
   artifacts — CI" literally true rather than aspirational.

Item 3 turned out to already be met: `.github/dependabot.yml` runs a grouped, weekly `composer`
update against the root directory. Dependabot's version-update check considers the whole resolved
tree, not only `composer.json`'s direct entries, so a newer compatible release of any dependency —
direct or transitive — opens a PR that refreshes `composer.lock` on that cadence. Adding Renovate
alongside it would be two bots proposing the same class of PR against the same lock file, which is
exactly the kind of tool duplication CLAUDE.md's "never force-fit" instruction and this project's
own dependency-hygiene precedent both rule out. Item 3 needed a note, not a diff.

Items 1, 2 and 4 needed real changes, and each raised its own installation question.

## Decision

**Add all three as additive CI/release surface, choosing the install strategy per tool's own PHP
floor rather than applying one pattern uniformly.**

### 1. `composer audit`, nightly (`nightly.yml`)

A new `audit` job installs the committed `composer.lock` on PHP 8.3 and runs `composer audit` on a
fixed clock (the existing `03:17 UTC` schedule this file already uses, plus `workflow_dispatch`),
independent of any push. It reads the same lock the PR-time gate does — this is not a re-resolve
against latest, which would test a tree nobody has committed to.

### 2. ComposerRequireChecker, outside the dependency graph (`ci.yml`)

Every version of `maglnet/composer-require-checker` from 4.16 onward requires PHP >= 8.2 — above
this library's own `>=8.1` floor. `ci.yml`'s `lowest-deps` job installs `require-dev` too (it runs
plain `composer update --prefer-lowest`, no `--no-dev`), so adding the checker to `require-dev`
directly would break that job's PHP 8.1 install the moment a floor-respecting version is pinned.
This is the exact shape ADR-0031 and ADR-0040 already solved for Roave/Psalm/Infection: install the
tool into a throwaway directory outside the package's own dependency graph
(`composer require --working-dir="$RUNNER_TEMP/require-checker-tool"`), so its own PHP floor never
touches `composer.json`. Pinned to `^4.16` — a floor, not floating across the tool's full range, the
same reproducibility reasoning ADR-0031's pin gives.

The check itself (`composer-require-checker check composer.json`) is a **missing-requirement**
scanner: every symbol the source actually reaches must be a declared `require`. It is what proves
`ext-fileinfo` is genuinely used rather than a stale declaration — the issue's own example.

### 3. CycloneDX SBOM, *inside* the dependency graph (`release.yml`, `composer.json`)

`cyclonedx/cyclonedx-php-composer` is not a standalone analyser like Psalm or Infection — it is a
Composer **plugin** that reads *this* project's own `vendor/composer/installed.json` to build the
Bill of Materials. Installed into a throwaway directory outside the graph, it would describe that
empty directory's dependencies, not this library's. The outside-the-graph pattern does not apply
here, and forcing it would produce an SBOM of nothing.

Unlike the require-checker, its `php ^8.1` requirement (v6.x) matches this library's own floor
exactly — no conflict with `lowest-deps`. It is added to `require-dev` like any other project
tool (`^6.2`, `composer.lock` updated with only the seven new packages it and its own dependencies
resolve to — verified locally: `composer update cyclonedx/cyclonedx-php-composer` produced *zero*
incidental bumps to already-locked packages), and `cyclonedx/cyclonedx-php-composer` joins
`allow-plugins` alongside `ergebnis/composer-normalize`.

`release.yml`'s `draft-release` job installs dependencies (with dev, since the generator is a dev
tool), runs `composer CycloneDX:make-sbom --output-format=XML --output-file=... --omit=dev`, and
attaches the result via `softprops/action-gh-release`'s existing `files:` input in the same step
that already drafts the release. `--omit=dev` is deliberate: the SBOM describes what a consumer's
own `composer require` resolves, not this repository's own tool-chain — PHPStan, PHP-CS-Fixer and
the SBOM generator itself never reach a consumer's `vendor/` and have no place asserting
supply-chain provenance for it.

## Alternatives Considered

- **`composer-unused` instead of ComposerRequireChecker.** The issue offers it as an alternative.
  Rejected: `composer-unused` finds declared-but-unused requirements, the opposite direction from
  what #98 actually asks for (`ext-fileinfo` "genuinely reached" is a *missing*-requirement
  question, not an unused one). ComposerRequireChecker answers the question the issue poses.
- **Renovate alongside Dependabot for item 3.** Rejected in Context above — the existing weekly,
  grouped `dependabot.yml` composer config already provides the refresh cadence; a second bot would
  duplicate it rather than extend it.
- **Install ComposerRequireChecker as a pinned older version compatible with PHP 8.1, directly in
  `require-dev`.** Rejected: it would still install on every `lowest-deps` run, adding minutes to a
  job whose entire point is testing the *library's* lowest-supported install, not exercising a dev
  tool nobody but CI runs. The throwaway-install pattern keeps `lowest-deps` measuring exactly what
  it says.
- **Run the SBOM generator outside the dependency graph, pointed at the checked-out tree via
  `--working-dir`.** Considered and rejected: the plugin resolves the target project through
  Composer's own runtime API bound to the directory it is installed in, not an arbitrary
  `--working-dir` argument passed to its command — it needs to *be* a dependency of the project it
  describes.
- **Generate the SBOM including dev dependencies.** Rejected in the Decision above: it would assert
  provenance for packages a consumer never installs.

## Consequences

- **No production code changes.** The diff is `composer.json`/`composer.lock`, three CI/release
  workflow edits, `docs/workflow/release.md`, and this document plus the spec revision.
- **`nightly.yml` gains one more job**, cheap relative to the benchmark/taint/mutation jobs already
  there (an install and one `composer audit` call).
- **`ci.yml`'s `hygiene` job grows two steps** (throwaway install + check) — bounded cost, no
  `composer.json` change, so `lowest-deps` is untouched.
- **Every future draft release carries a production-only CycloneDX SBOM** as a release asset,
  which is also what turns `docs/workflow/release.md`'s "Build & attach artifacts — CI" row from
  aspirational to accurate.
- **`composer.lock` grows by seven packages** (`cyclonedx/cyclonedx-php-composer` and its own
  dependency tree) in `require-dev` only — no change to the runtime-resolved tree a consumer
  installs.
- **Known limit:** the require-checker's default configuration ships with this PR unmodified; if
  the first real CI run surfaces a false positive specific to this codebase (a symbol resolvable
  only through a suggested-but-optional extension, for instance), that is a follow-up config
  addition, not a defect in the approach.

## References

- Issue [#98](https://github.com/danielPoloWork/egl-util-php/issues/98) — 2026-08-09 Release
  Review Board, Build and Release Engineer seat.
- Spec **r24** — the recorded NFR-07 amendment.
- [`docs/workflow/release.md`](../workflow/release.md) — the boundary table row this closes.
