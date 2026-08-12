# Changelog

All notable changes to `egl-util-php` are documented here, following
[Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning 2.0.0](https://semver.org/).

Every PR that introduces a user-visible change adds a line to `[Unreleased]` in the same
PR. A release PR moves the `[Unreleased]` entries into a new per-version file under
`docs/changelog/v<MAJOR>/v<X.Y.Z>.md` and adds an index row below.

## [Unreleased]

### Added

- **Milestone 14, the first functional roadmap after the freeze** (issue #84), specified by the
  newly accepted [RFC-0003](docs/rfc/0003-post-1-0-functional-scope.md). Five numbered items, all
  additive under ADR-0059: **FR-45** PSR-20 clock (`SystemClock`/`FrozenClock`), **FR-46**
  time-sortable identifiers (`Str::ulid()`/`Str::uuidV7()`), **FR-47** pagination value objects in
  `Persistence`, **FR-48** `Security\Hmac`, **FR-49** `Support\RetryPolicy`. Item 14.1 is sequenced
  first because three of the others need a clock and `src/main` contains no time abstraction at all.
  Two of the review board's seven candidates are **deferred with their reasons recorded and their
  issues left open** — the rate limiter (#91) because a single-node limiter behind a load balancer
  looks like protection and is not, and the PSR-18 bridge (#93) because it would be the second
  consumer of a split-publication pipeline that has never executed. The **do-NOT-add list** (money
  arithmetic, ORM features, an SMTP client, console/i18n helpers) is recorded in the milestone
  preamble so scope-creep requests have a citable answer. `orchestrator/project.yaml` records
  `RFC-0003` and `M14` — and `M13`, which had been missing since the milestone was created.

- **`egl/utils` registered on Packagist** (issue #121), with the GitHub integration wired so
  future tags publish by webhook. Verified end to end: `composer require egl/utils:^1.0` in a
  clean throwaway project resolves `v1.0.0` at source commit `be7f34e` — the exact commit the tag
  points at — installs cleanly with no security advisories, and its autoloaded classes load.
  `README.md` gains a minimal `## Install` section stating the fact; `docs/releases/v1.0.0.md`'s
  "never been installed from Packagist" line is corrected without being rewritten to look
  prescient; `docs/workflow/release.md`'s prerequisite is marked done with the evidence.
  **This also closes the issue's squat-protection criterion, at no extra cost.** Packagist
  protects a vendor namespace as soon as one package under it is published — *"you can not
  publish packages with a vendor name that already exists on packagist without permission"*,
  and publishing under an existing vendor requires being maintainer of a package already in it.
  Registering `egl/utils` therefore locked the whole `egl/` namespace, so `egl/utils-psr7-bridge`
  cannot be squatted by anyone else and no split repository is needed to defend the name. What
  the split repository (issue #120) is still needed for is *publishing* the bridge, since
  Packagist resolves a package from a repository with `composer.json` at its **root** and the
  bridge's sits under `packages/`. The original acceptance criterion had fused protection and
  publication into one line; they are independent, and only the second is still open.
- **Supported-versions window for the post-1.0 line**, defined in
  [`docs/workflow/maintenance.md`](docs/workflow/maintenance.md) and pointed to from `SECURITY.md`:
  the latest release of the current MAJOR, with the previous MAJOR's final release on security
  fixes until `X+1.1.0` ships. `SECURITY.md` had deferred to that section since the repository was
  generated; the section had never existed (ADR-0060).
- **[`ISSUES.md`](ISSUES.md), a reverse-chronological index of the issue tracker.** One bullet per
  GitHub issue, newest first, each carrying the advisory `route: <tier> / <effort>` from
  `ROADMAP.md`'s routing vocabulary; new issues are prepended, closed ones get their checkbox
  flipped in place. Seeded with the 39 issues (#84–#122) consolidated from the 2026-08-09
  seven-seat release review of `v1.0.0`; issues mirroring a `ROADMAP.md` M13 item cross-reference
  it rather than replacing it. `README.md` gains the pointer row.

### Fixed

- **`docs/releases/v1.0.0.md` no longer claims the release gate approved it.** The notes stated the
  published tree was *"the one the gate approved"*; it is not. `v1.0.0`'s tag is unsigned, so
  `release.yml`'s `The tag must be signed` step failed (run 31283673519), the tagged-tree 8.1/8.2/8.3
  matrix and the draft-Release job were both **skipped**, and the GitHub Release was published by
  hand 13 minutes later. The sentence is gone and a `How this release was published` section records
  the bypass, what the missing matrix would have proved, that `quality / backward compatibility`
  reported green on the release PR having compared nothing (no `v*.*.*` tag existed), and that the
  copy of these notes *inside the tagged tree* still carries the stale `0.x` text a tag's
  immutability puts out of reach. The GitHub Release body carries the same correction. The signing
  decision itself stands and is not reopened here; completing the signing chain is issue #115
  (ROADMAP item 13.1).
- **The same claim removed from the two other places it had been copied to.**
  `docs/changelog/v1/v1.0.0.md`'s *Superseded pre-release* section said the shipped tree "is the
  one the gate approved" and now records the bypass instead; **ADR-0059**'s Decision point 4 said
  it too, and carries a Status annotation correcting the fact while leaving the decision intact
  (ADR-0041's annotate-don't-edit precedent). Both were written before the tag was pushed, which is
  how a claim about the future ended up recorded as history.
- **`docs/releases/v1.0.0.md` no longer contradicts itself.** Its closing section still declared
  that the 1.0.0 API-freeze review *"has not happened"* and that *"this is a `0.x` release"* — text
  carried over from the unpublished `v0.11.0` notes, inside the document announcing the freeze.
  Removed. The same file recorded the bridge's constraint on the core as `^0.11`; corrected to the
  `^1.0` that `packages/utils-psr7-bridge/composer.json` actually declares.
- **`SECURITY.md`'s supported-versions table applies at a `1.0.0` HEAD.** It previously offered only
  `latest released 0.x` and `older 0.x`, and no `0.x` was ever published — so the policy's table had
  no row a consumer could be standing on.
- **`packages/utils-psr7-bridge/README.md` no longer announces itself as a scaffold** whose
  converters *"land in 8.2"*. The converters and their BFR-01…BFR-22 contract suite shipped in item
  8.2 and the publication pipeline in 8.3; the banner now states the real remaining gap (the
  one-time publication setup), and *Usage* carries a worked example verified against the frozen 1.0
  signatures instead of a forward reference.
- Two dead `ROADMAP.md` links to ADR-0040, which had pointed at the file's pre-rename name since it
  was renamed.

---

## Released versions

| Version | Date | Notes |
|---------|------|-------|
| [v1.0.0](docs/changelog/v1/v1.0.0.md) | 2026-08-09 | The first release — every milestone M1–M12 — and the API freeze (ADR-0059). |
