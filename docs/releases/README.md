# Releases

**This is the canonical home of the GitHub Release body.** One narrative file per release,
`v<MAJOR>.<MINOR>.<PATCH>.md`, written for a consumer deciding whether to upgrade: what the
release means for them, what to know before upgrading, the known limits, and how it was
published. `release.yml`'s `draft-release` job reads the file for the matching tag **verbatim**
as the Release body (`body_path`), so what is written here is what consumers read on GitHub.

**Not the same document as the changelog, and not a copy of it.**
[`../changelog/v<MAJOR>/`](../changelog/) holds the exhaustive Keep-a-Changelog record — every
`Added`/`Changed`/`Fixed`/`Deprecated`/`Removed`/`Security` entry rolled out of
[`CHANGELOG.md`](../../CHANGELOG.md)'s `[Unreleased]` section at release time. For `v1.0.0` that
is 1,213 lines against this file's 156. The changelog answers *"what exactly changed"*; these
notes answer *"should I upgrade, and what should I know first"*. Both are maintained; neither
supersedes the other.

The release process is [`../workflow/release.md`](../workflow/release.md); the maintainer
publishes the drafted Release (AGENTS.md §11). The consistency lint's `version-lockstep` check
keeps the latest file here in step with the version constant and the README badge.

## Index

| Version | Date | Highlights | Notes |
|---------|------|------------|-------|
| v1.1.0 | 2026-08-21 | Milestone 14's five additive seams — PSR-20 clock, sortable ids, pagination values, detached HMAC, rate limiter — plus Milestone 13's documentation and release-hygiene close-out | [v1.1.0.md](v1.1.0.md) |
| v1.0.0 | 2026-08-09 | The first release — every milestone M1–M12: DTOs, Database + Persistence, Security, Http, Errors, Mail, Support — and the API freeze (ADR-0059) | [v1.0.0.md](v1.0.0.md) |
