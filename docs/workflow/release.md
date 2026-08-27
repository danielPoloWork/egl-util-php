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
   `docs/changelog/v<MAJOR>/v<X.Y.Z>.md` and add an index row to `CHANGELOG.md`. **Open the new
   file with a `## Highlights` section** — a handful of consumer-facing bullets (what's in the
   box, any breaking or notable behavior, verification numbers worth repeating) sitting above the
   rolled `[Unreleased]` entries, which stay newest-first exactly as written. The highlights say
   nothing the narrative log beneath doesn't already say at length; they exist so a reader doesn't
   have to read a hundred engineering-record bullets to learn what shipped (issue #88, ROADMAP
   13.5's single-sourcing question is separate and unaffected — this step applies wherever the
   per-version file ends up living).

   **Rebase every relative link as you move it.** `CHANGELOG.md` sits at the repository root, so
   its entries write `docs/patterns/endpoint-kernel.md`; the per-version file sits two directories
   down, where the same target is `../../patterns/endpoint-kernel.md`. Moving the text without
   rebasing the paths **manufactures dead links mechanically** — which is exactly what happened at
   `v1.0.0`: six of them, sitting in `docs/changelog/v1/v1.0.0.md` until issue #116 wired a checker
   that found them (the review board had counted five).

   The rule for a `docs/changelog/v<MAJOR>/` file: a root-relative `docs/…` becomes `../../…`, and
   anything already relative was written against the root and needs the same two levels. **You do
   not have to get this right by inspection** — step 5's lint now resolves every relative link in
   tracked Markdown and names the file and line of any that does not exist, so a missed rebase is a
   red gate rather than a defect a reader finds a year later.
3. **Refresh the README** status badge (and milestone table on a MINOR that closes a
   milestone).
4. **Draft release notes** under `docs/releases/v<X.Y.Z>.md`.
5. **Run the consistency lint** (`python tools/consistency_lint.py`) — version lockstep must
   pass, and so must the `links` check, which is what catches a missed rebase from step 2. Its
   output states what it does *not* resolve (external URLs; bare and numeric `§` references), so
   read that line rather than assuming a green run vouched for everything.
6. **Open the release PR** — *the maintainer does this*. The agent prepares it.
7. **Merge** — *the maintainer*.
8. **Tag + draft (carry-through)** — the agent runs `git tag -a -s v<X.Y.Z> -m "<headline>"` and
   `git push origin v<X.Y.Z>` immediately after merge; the tag push lets CI open the GitHub Release
   as a **draft**. The agent always carries the release this far — only **Publish** is the human's.
   **The `-s` is not optional**: the release workflow refuses an unsigned tag (see *Release-time
   gates* below).
9. **Publish** the GitHub Release — *the maintainer* (the deliberate human checkpoint).
10. **CI drafts the Release body** on the tag push, rendered rather than copied.
    `tools/release_body.py` reads `docs/releases/v<X.Y.Z>.md`, **rebases its relative links to
    absolute `blob/<tag>` URLs**, drops the H1 (the Release page supplies its own title), and
    `draft-release` publishes that. The rebasing is not cosmetic: the notes' links are written
    against `docs/releases/`, and a Release body is not served from there — published verbatim they
    would not resolve. The tool **refuses (exit 2)** on a relative link whose target is missing,
    rather than shipping a 404 to every consumer, so a dangling link fails the release instead of
    reaching it. `tools/tests/verify_release_body.py` proves both behaviours and runs on every PR.
    The release is still the tagged source (`composer require` resolves it via the Packagist
    integration, item 2 above), not a downloadable binary — but `draft-release` also attaches a
    **CycloneDX SBOM** (`bom.xml`, production dependencies only) as a release asset (issue #98,
    ADR-0076), generated from the tagged tree's own `composer.lock` in the same job.

    **That SBOM is attested** (issue #115 criterion 3, ADR-0084). An unattested SBOM on a Release is
    a supply-chain claim anyone with write access to the Release can replace, with no way for a
    consumer to tell — so `draft-release` produces a signed SLSA provenance attestation for it,
    before the draft exists, and no draft is created if that fails. A consumer verifies it with:

    ```bash
    gh attestation verify bom.xml --repo danielPoloWork/egl-util-php
    ```

    **It attests the SBOM, not the source.** The artifact `composer require` installs is the zip
    Packagist builds from the tag, which this workflow does not produce and therefore cannot attest.
    The assurance for that is the **signed tag** — issue #115's first criterion, still open, and the
    maintainer's own action.

    **Do not hand-edit a published Release body.** It is generated from the notes file, so an edit
    made on GitHub is overwritten by the next render and lost from the record. Correct
    `docs/releases/v<X.Y.Z>.md` instead. The `v1.0.0` body was hand-written only because
    `verify-tag` failed on an unsigned tag and skipped `draft-release` altogether — the mechanism
    was never the problem; the skipped gate was (issues #115, #122).
11. **Verify the publish actually reached the world** (issue #105, ADR-0081) — run this immediately
    after clicking Publish:

    ```bash
    python tools/post_publish_gate.py --tag v<X.Y.Z>
    ```

    Everything above this step verifies the tag and drafts the Release; nothing verified what
    happened *after* a human clicked the button. That gap was not hypothetical — this repository's
    own `v1.1.0` tag was pushed, failed `verify-tag` on an unsigned signature, and sat for three
    days with no GitHub Release and nothing on Packagist while `ROADMAP.md` read as though it had
    shipped, because nothing checked. The gate asks the four questions that answer "did this
    actually ship": the tag is signed and verified, the GitHub Release exists and is not a draft,
    its body matches the canonical rendering of the notes (catches a hand-edit, or the render
    itself drifting), and the version resolves on Packagist. Exit **0** means all four hold; **1**
    means a real problem was found; **2** means a check could not even complete — network down, the
    release does not exist yet — which is never the same thing as "verified fine."

    **This step also runs nightly** (`nightly.yml`'s `post-publish` job) against whatever the
    latest published Release happens to be, so a release that slips through this manual step —
    exactly what happened with `v1.1.0` — is caught within a day rather than found by accident.


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
| SBOM attestation | an SBOM whose provenance could not be signed — no draft is created rather than an unverifiable asset published (ADR-0084) |

`release_gate.py` exists because the consistency lint structurally cannot do this job: it runs on a
working copy and has no tag to compare against. `git tag -a v0.2.0` on a tree whose constant still
says `0.1.0` produces a release that installs as one version and reports itself as another, and
nothing inside the tree disagrees with itself — so no lint notices.

### Releasing a bridge — decided against, 2026-08-27

> **No bridge is published, and this is a decision rather than a pending step.** Issue
> [#120](https://github.com/danielPoloWork/egl-util-php/issues/120) is closed *as not planned*:
> publishing a bridge means pushing to a generated split repository, which needs a credential in
> this repository's secrets (`GITHUB_TOKEN` structurally cannot write outside its own repository —
> the consequence of ADR-0033's design, not an implementation detail), and the maintainer has
> decided not to hold one.
>
> The procedure below is **kept, not deleted**, because it is correct and was verified up to the
> push — see *Release mode has been exercised* — and because the decision is reversible: a deploy
> key scoped to a single split repository would need one workflow change rather than a rebuild. It
> documents a path nobody is currently walking. Nothing in it is stale.
>
> **What is unaffected:** both bridges stay contract-tested against two PSR-17 vendors on every
> pull request and usable from the monorepo, and the `egl/` vendor namespace is squat-protected by
> `egl/utils` regardless (step 2 below) — not publishing costs nothing in name protection.

Each bridge versions **independently** of the core and of the other bridges (ADR-0033 §3). Since
issue #93 / ADR-0075 **one pipeline serves every bridge**, and the tag names the package it
publishes — adding a third bridge needs a repository variable and no workflow edit. There are two
today, `egl/utils-psr7-bridge` and `egl/utils-psr18-bridge`. Releases are cut from package-scoped
tags here and published to a generated, read-only split repository per package:

```bash
git tag -a -s utils-psr7-bridge-v0.1.0 -m "<headline>"
git push origin utils-psr7-bridge-v0.1.0
```

`.github/workflows/bridge-release.yml` then verifies the tag is annotated and signed, checks it
against `packages/<package>/CHANGELOG.md`'s `## [X.Y.Z]` heading (a Composer library carries no
version constant, so the changelog is what anchors the tag), runs the contract suite in **release
mode**, splits the package and pushes it as a plain `vX.Y.Z`. Validate a tag's shape and changelog
agreement before pushing it:

```bash
python tools/bridge_release_gate.py --tag utils-psr7-bridge-v0.1.0
```

Three things to know before cutting one:

- **The core must be released first, and this precondition is now met.** Release mode installs the
  package resolving `egl/utils` from Packagist, exactly as a consumer would. That is the only
  evidence for the constraint the package publishes, so it is never skipped — which means a bridge
  release was impossible until a core version matching its constraint existed (ADR-0035 §2).
  **Note which core version that is:** Packagist serves only `v1.0.0`; the `v1.1.0` tag exists but
  its publication never completed (issues #115, #105). `^1.0` therefore resolves to `v1.0.0`, which
  satisfies both bridges' constraints.
- **Release mode has been exercised, ahead of any tag** (issue #120, 2026-08-27). Both packages were
  copied out of the monorepo, installed against Packagist and run: PSR-7 **65 tests / 202
  assertions**, PSR-18 **28 tests / 72 assertions**, both green. This was the first time the gate
  ADR-0035 §2 calls unfakeable had ever run. It is also the last thing that ran: the decision above
  landed before any tag, so **`bridge-release.yml` still has zero runs** and its "refuses to publish
  what it cannot prove installable" property remains unproven live. What *is* proven is everything
  up to the push.
- **The changelog heading a tag would need is not present.** `## [X.Y.Z]` is what anchors a bridge
  tag, and both packages are back at `## [Unreleased]` — a versioned heading would claim a release
  that does not exist. Add one at the moment a tag is actually cut, not before.
- **`workflow_dispatch` is a dry run.** Running the workflow manually against an existing tag
  validates everything and pushes nothing. It needs an existing tag, so it cannot pre-validate a
  release that has not been tagged — the gate command above is what covers that gap.

### One-time maintainer prerequisites

All of these are the maintainer's, not the agent's, and the first release cannot succeed without
them.

0. **The pre-push guard enabled**, once per clone: `git config core.hooksPath .githooks`. It
   refuses an unsigned or lightweight `v*.*.*` tag before it reaches the remote, which is where
   `v0.11.0`, `v1.0.0` and `v1.1.0` each went wrong — the CI check fires after publication, and a
   published tag cannot be corrected in place. Check any tag by hand with
   `python tools/tag_guard.py --tag v<X.Y.Z>`. A deliberate unsigned release is still possible and
   must say so: `EGL_UNSIGNED_TAG_REASON="why" git push origin v<X.Y.Z>` (issue #115, ADR-0032).
1. **A signing key registered on the GitHub account** whose identity cuts tags — GPG or SSH. The
   workflow asks GitHub whether the tag verifies rather than importing key material onto the
   runner, so nothing needs configuring in CI; but an unsigned tag fails the release.
   Locally: `git config --global tag.gpgSign true` makes `-s` the default and removes the chance of
   forgetting it.

   **Decided against, 2026-08-27.** No signing key is being registered. The consequence is exact
   and worth stating rather than discovering: `release.yml`'s `verify-tag` job **fails on every
   tag**, so `test-tagged-tree` and `draft-release` are skipped and the GitHub Release must be
   written by hand — which is what happened to `v0.11.0`, `v1.0.0` and `v1.1.0`, and why `v1.1.0`
   never reached Packagist at all (issue #105, ADR-0081).
   So a release now takes the documented deliberate path in step 0 —
   `EGL_UNSIGNED_TAG_REASON="why" git push origin v<X.Y.Z>` — and **`tools/post_publish_gate.py`
   becomes the load-bearing check** rather than a formality, because it is the only thing left that
   notices a tag which never became a release. Run it every time (step 11 above); it also runs
   nightly.
   Issue **#115** stays open on this criterion: its other two are done (the pre-push guard, #162;
   the SBOM attestation, #181), and the decision is reversible — an **SSH** signing key needs no
   email address and `ssh-keygen` is already present, so registering one later is a settings change
   and not a rework.
2. **The Packagist ↔ GitHub integration**, once, for `egl/utils`. Packagist then updates itself from
   each tag push, which is why no Packagist token lives in this repository. The workflow prints the
   package URL to confirm after publishing; it deliberately does not call the Packagist API, since
   that would both duplicate the integration and cross the agent-vs-human line below.
   **Done 2026-08-10** (issue #121): `egl/utils` is registered with the integration wired, and
   `composer require egl/utils:^1.0` was verified in a clean throwaway project — it resolves
   `v1.0.0` at source commit `be7f34e`, the exact commit the tag points at.

   **This also protects every other `egl/` name, including the bridge's.** Packagist locks a
   vendor namespace to the first publisher: a package cannot be published under an existing
   vendor without permission, and publishing under one requires being maintainer of a package
   already in it. So `egl/utils-psr7-bridge` cannot be squatted, and **name protection is not a
   reason to hurry the split repository** — step 3 is about *publishing* the bridge, not about
   defending its name. Do not conflate the two: the original issue did, and it made a solved
   problem look blocked.
3. **For the bridges only** — a **split repository per package**, and one token that can write to
   them. Do this once per bridge; the token may cover every bridge.

   **The repository variable is named per package**, derived from the package directory by
   upper-casing it and turning `-` into `_` (ADR-0075) — *not* a single shared `BRIDGE_SPLIT_REPO`,
   which is what this step said before issue #120 and would have failed the prerequisite check after
   every other gate passed:

   | Package | Repository variable | Suggested split repository |
   |---|---|---|
   | `utils-psr7-bridge` | `BRIDGE_SPLIT_REPO_UTILS_PSR7_BRIDGE` | `danielPoloWork/egl-utils-psr7-bridge` |
   | `utils-psr18-bridge` | `BRIDGE_SPLIT_REPO_UTILS_PSR18_BRIDGE` | `danielPoloWork/egl-utils-psr18-bridge` |

   For each bridge:
   - create the repository, empty, and treat it as **read-only**: it is generated, and accepts no
     commits or pull requests;
   - set that package's repository variable to its `owner/name`;
   - register the package (`egl/utils-psr7-bridge`, `egl/utils-psr18-bridge`) on Packagist, pointing
     at its split repository. The `egl/` vendor is already locked to this account by `egl/utils`
     (step 2), so neither name can be squatted and there is no reason to hurry this.

   Then once, for all of them:
   - set the secret `BRIDGE_SPLIT_TOKEN` to a token with write access to the split repositories —
     `GITHUB_TOKEN` cannot write to another repository, which is why this one is needed.

   Until a package's variable and the secret exist, `bridge-release.yml` fails at its prerequisite
   step and names the exact variable it wants — after the gates have passed, so the message
   distinguishes "not configured" from "the release is bad".

   **The pipeline has never run.** Its "refuses to publish what it cannot prove installable"
   property is therefore still unproven live, which is the whole of what issue #120 has left. The
   gates it runs *before* the push have been reproduced by hand and are green (see *Releasing a
   bridge* above), so the first real run is exercising the split and push, not the package.

## Boundary

| Action | Who |
|---|---|
| Bump version, roll changelog, draft notes | Agent |
| Open / merge the release PR | **Human** |
| Create & push the annotated tag, then the **draft** release (CI drafts it on tag-push) | Agent |
| Publish the GitHub Release (click **Publish**) | **Human** |
| Build & attach artifacts | CI |
| Verify the publish reached GitHub Releases and Packagist | Agent/human, manually — **and CI, nightly** (issue #105, ADR-0081) |


Agents never publish releases, never amend or delete published tags, and only delete-and-
repush an *unpublished* tag whose release run visibly failed.
