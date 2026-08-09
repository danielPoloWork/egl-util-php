# Changelog

All notable changes to `egl-util-php` are documented here, following
[Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning 2.0.0](https://semver.org/).

Every PR that introduces a user-visible change adds a line to `[Unreleased]` in the same
PR. A release PR moves the `[Unreleased]` entries into a new per-version file under
`docs/changelog/v<MAJOR>/v<X.Y.Z>.md` and adds an index row below.

## [Unreleased]

### Added

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
