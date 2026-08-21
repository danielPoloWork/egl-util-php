# Changelog archive

The per-version changelog record, one directory per MAJOR (`v1/`, `v2/`, …) and one file per
release inside it (`v<MAJOR>.<MINOR>.<PATCH>.md`).

**What lands here, and when.** Every PR with a user-visible change adds an entry to
[`CHANGELOG.md`](../../CHANGELOG.md)'s `[Unreleased]` section. A release PR *moves* those entries
into the file for the version being cut and adds its index row — so `CHANGELOG.md` only ever holds
what is not yet released, and everything released is here. The move is a cut, not a copy; the
procedure is [`../workflow/release.md`](../workflow/release.md) step 2, including the rule for
rebasing relative links two levels as they move.

**Not the same document as the release notes.**
[`../releases/`](../releases/) holds one narrative file per release — the consumer-facing *"should I
upgrade, and what should I know first"*, and the file `release.yml` publishes verbatim as the GitHub
Release body. This directory holds the exhaustive *"what exactly changed"*: every
`Added`/`Changed`/`Fixed`/`Deprecated`/`Removed`/`Security` entry, in the
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) shape. For `v1.0.0` that is 1,213 lines
against the release notes' 156. **Both are maintained and neither supersedes the other** — a
maintainer editing "the release notes" wants `../releases/`; one asking "when did this behaviour
change" wants this directory.

## Index

| MAJOR | Versions |
|---|---|
| [`v1/`](v1/) | [v1.0.0](v1/v1.0.0.md) |
