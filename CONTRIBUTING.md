# Contributing to egl-util-php

Thank you for considering a contribution. This project holds AI agents and human contributors to
the **same quality bar** — the checklist below is not a stripped-down version of anything; it is
literally what an agent working in this repository must clear before opening a PR
([`AGENTS.md`](AGENTS.md) §6, [`docs/development/local-build.md`](docs/development/local-build.md)).

Please also read the [Code of Conduct](CODE_OF_CONDUCT.md).

## Before you open an issue

- **Bug report**: include the PHP version, the exact call that fails, and what you expected instead
  of what happened. If it's a security issue, **do not open a public issue** — see
  [`SECURITY.md`](SECURITY.md).
- **Feature request**: this is a frozen 1.x API ([ADR-0059](docs/adr/0059-freeze-the-api-at-1-0-0-with-internal-symbols-outside-the-frozen-surface.md)) —
  new public surface can only be **additive**. Check [`ROADMAP.md`](ROADMAP.md) and
  [`ISSUES.md`](ISSUES.md) first; your idea may already be filed, accepted, or explicitly deferred
  with a reason.

## Before you open a PR

1. **Local setup**: [`docs/development/local-build.md`](docs/development/local-build.md) — install,
   build, run the suite.
2. **Format and analysis are clean**:
   ```bash
   vendor/bin/php-cs-fixer fix --dry-run --diff
   vendor/bin/phpstan analyse
   ```
3. **Tests pass, and new/changed behavior is covered** — `vendor/bin/phpunit`, ≥ 90% line coverage,
   PHPStan at max level (type soundness, not just "no errors").
4. **Cross-artifact congruence passes**:
   ```bash
   python tools/consistency_lint.py
   ```
   This checks version lockstep, the ADR index, the patterns catalogue, the spec coverage map,
   README↔ROADMAP milestone agreement, and bug-ledger integrity. It is not optional and CI re-runs
   it — running it locally first avoids a red round-trip.
5. **The relevant docs are updated in the same PR** — README, ROADMAP (flip the checkbox), an ADR
   for a non-trivial design choice, the patterns catalogue for a pattern adopted/rejected, the spec
   under `docs/specs/` if behavior diverges from it, `CHANGELOG.md`'s `[Unreleased]` section for any
   user-visible change. See [`docs/workflow/documentation.md`](docs/workflow/documentation.md).

## Commit and PR conventions

- **Commits**: [Conventional Commits](https://www.conventionalcommits.org/), one logical change per
  commit, imperative subject — `AGENTS.md` §6.3 for the exact format and this repo's scopes.
- **Branch names**: `<type>/<short-kebab-description>` — `AGENTS.md` §6.2.
- **PR title and body**: this repository squash-merges, so the PR title/body **becomes the commit
  on `master`** — write it as it should read in `git log` forever, not as a one-line collapse.
  [`.github/PULL_REQUEST_TEMPLATE.md`](.github/PULL_REQUEST_TEMPLATE.md) is the shape to fill in.
- **One type label, one milestone**: maintainers apply these on review; you don't need to set them
  yourself.

## Design decisions

If your change makes a non-obvious choice — especially one touching the public API — open the
question in the PR description rather than deciding silently. A design pattern adoption, a
layering exception, or anything that would need its own ADR is worth raising *before* the
implementation is finished, not after.

## What won't be merged

- Anything that breaks the frozen 1.x API without a MAJOR-level discussion first (ADR-0059).
- Scope explicitly ruled out already: money/decimal arithmetic, ORM features (identity map, change
  tracking, lazy loading), an SMTP client implementation, console/i18n helpers — see
  [RFC-0003](docs/rfc/0003-post-1-0-functional-scope.md)'s non-goals for the reasoning, not just the
  list.
- A PR that doesn't pass the checklist above. Gates exist so that "looks right" is never the bar.

## Questions

Open an [issue](https://github.com/danielPoloWork/egl-util-php/issues) for anything that isn't a
bug report or a concrete change proposal — GitHub Discussions is not enabled on this repository.
