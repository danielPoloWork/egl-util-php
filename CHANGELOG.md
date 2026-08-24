# Changelog

All notable changes to `egl-util-php` are documented here, following
[Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning 2.0.0](https://semver.org/).

Every PR that introduces a user-visible change adds a line to `[Unreleased]` in the same
PR. A release PR moves the `[Unreleased]` entries into a new per-version file under
[`docs/changelog/v<MAJOR>/`](docs/changelog/) and adds an index row below.

**There are two release documents, and they are not copies.** This file and its archive are the
exhaustive record of *what changed*. The consumer-facing narrative — *should I upgrade, what should
I know first* — is [`docs/releases/`](docs/releases/), which is also the file published verbatim as
the GitHub Release body. Editing "the release notes" almost always means that one.

## [Unreleased]

### Fixed

- **`phpdoc.dist.xml` no longer ships in the Packagist dist**, and a gate now asserts the dist's
  contents rather than trusting the rule list (issue #119). `.gitattributes`' `export-ignore` rules
  cut the archive from 524 files to 121 at `v1.1.0` — and `phpdoc.dist.xml` shipped inside it
  anyway, added in a later PR with no rule of its own and unnoticed until the tag was published.
  **The reasoning that failed was written down as if it were sound**: `.gitattributes` argued a
  deny-list avoids the rot an allowlist suffers, when a deny-list **includes** a new top-level file
  by default and so rots the same way, silently. Neither list is self-maintaining. `tools/dist_gate.py`
  asserts what is actually in `git archive` — everything under `src/main/` plus `LICENSE`,
  `README.md`, `composer.json`, and nothing else — and refuses (exit 2) an archive it cannot read
  or one containing no source at all. It found the real leak on its first run, before any synthetic
  case existed; `tools/tests/verify_dist_gate.py` is the repeatable half.
  `v1.1.0`'s published dist is unchanged: one 1.5 KB config file does not justify moving a tag.


## Released versions

| Version | Date | Notes |
|---------|------|-------|
| [v1.1.0](docs/changelog/v1/v1.1.0.md) | 2026-08-21 | Milestone 14's five additive seams, and Milestone 13's documentation and release-hygiene close-out. |
| [v1.0.0](docs/changelog/v1/v1.0.0.md) | 2026-08-09 | The first release — every milestone M1–M12 — and the API freeze (ADR-0059). |
