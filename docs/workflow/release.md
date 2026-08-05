# Release Process

The mechanical step-by-step for cutting a release of `egl-util-php`. The governance
(which SemVer level, how a fix flows, deprecation/security) is in
[`maintenance.md`](maintenance.md); the agent-vs-human boundary is
[`AGENTS.md`](../../AGENTS.md) §11.

## Versioning

**Semantic Versioning 2.0.0**, annotated tags `vMAJOR.MINOR.PATCH`. Start point:
pre-1.0 milestone-driven.

- Pre-1.0: `MINOR` bumps on each completed roadmap milestone; `PATCH` for hotfixes.
- Post-1.0: `MAJOR` for incompatible changes, `MINOR` for additions, `PATCH` for fixes.

## Cutting a release (the steps)

1. **Bump the version constant** (public const VERSION = 'X.Y.Z') in `src/main/php/d4np/utils/Version.php`; update any
   version-check test.
2. **Roll the changelog** — move the `[Unreleased]` entries into a new per-version file
   `docs/changelog/v<MAJOR>/v<X.Y.Z>.md` and add an index row to `CHANGELOG.md`.
3. **Refresh the README** status badge (and milestone table on a MINOR that closes a
   milestone).
4. **Draft release notes** under `docs/releases/v<X.Y.Z>.md`.
5. **Run the consistency lint** (`python tools/consistency_lint.py`) — version lockstep must
   pass.
6. **Open the release PR** — *the maintainer does this*. The agent prepares it.
7. **Merge** — *the maintainer*.
8. **Tag + draft (carry-through)** — the agent runs `git tag -a -s v<X.Y.Z> -m "<headline>"` and
   `git push origin v<X.Y.Z>` immediately after merge; the tag push lets CI open the GitHub Release
   as a **draft**. The agent always carries the release this far — only **Publish** is the human's.
   **The `-s` is not optional**: the release workflow refuses an unsigned tag (see *Release-time
   gates* below).
9. **Publish** the GitHub Release — *the maintainer* (the deliberate human checkpoint).
10. **CI builds & attaches artifacts** on the tag push.


## Release-time gates

A tag is the one artifact that cannot be corrected in place once it is published and consumed, so
`.github/workflows/release.yml` verifies it before any draft exists (**ADR-0032**). Nothing here
publishes.

| Gate | What it refuses |
|---|---|
| annotated tag | a lightweight tag — no tagger, no date, no message, and it cannot carry a signature |
| **signed tag** | `verified != true` per GitHub's own verification of the tag object |
| `tools/release_gate.py` | a tag that disagrees with `Version.php`, or missing/unindexed release notes and changelog split |
| `tools/consistency_lint.py` | the repository's invariants failing *at the tag*, not merely on the branch |
| test matrix | the tagged tree failing on PHP 8.1, 8.2 or 8.3 — a tag can point at a commit CI never ran |

`release_gate.py` exists because the consistency lint structurally cannot do this job: it runs on a
working copy and has no tag to compare against. `git tag -a v0.2.0` on a tree whose constant still
says `0.1.0` produces a release that installs as one version and reports itself as another, and
nothing inside the tree disagrees with itself — so no lint notices.

### One-time maintainer prerequisites

Both are the maintainer's, not the agent's, and the first release cannot succeed without them.

1. **A signing key registered on the GitHub account** whose identity cuts tags — GPG or SSH. The
   workflow asks GitHub whether the tag verifies rather than importing key material onto the
   runner, so nothing needs configuring in CI; but an unsigned tag fails the release.
   Locally: `git config --global tag.gpgSign true` makes `-s` the default and removes the chance of
   forgetting it.
2. **The Packagist ↔ GitHub integration**, once, for `egl/utils`. Packagist then updates itself from
   each tag push, which is why no Packagist token lives in this repository. The workflow prints the
   package URL to confirm after publishing; it deliberately does not call the Packagist API, since
   that would both duplicate the integration and cross the agent-vs-human line below.

## Boundary

| Action | Who |
|---|---|
| Bump version, roll changelog, draft notes | Agent |
| Open / merge the release PR | **Human** |
| Create & push the annotated tag, then the **draft** release (CI drafts it on tag-push) | Agent |
| Publish the GitHub Release (click **Publish**) | **Human** |
| Build & attach artifacts | CI |


Agents never publish releases, never amend or delete published tags, and only delete-and-
repush an *unpublished* tag whose release run visibly failed.
