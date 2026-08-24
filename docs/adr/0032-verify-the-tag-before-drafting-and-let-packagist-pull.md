# ADR-0032: Verify the tag before a draft exists, ask GitHub about the signature, and let Packagist pull

- **Status:** Accepted — **a local guard added, and the bypass made explicit** (2026-08-24, issue
  #115). This ADR named the signing key as a one-time prerequisite and put the check in CI, where it
  fires *after* the tag is public — the one moment a tag cannot be corrected in place. `v0.11.0`,
  `v1.0.0` and `v1.1.0` each failed that check, three for three. `tools/tag_guard.py` plus
  `.githooks/pre-push` now refuse an unsigned or lightweight `v*.*.*` tag **before** the push.
  **Nothing decided here changes**, and the guard deliberately does *not* make an unsigned release
  impossible: the maintainer has chosen one three times with the outcome known, and a guard that
  simply blocked that would be bypassed with `--no-verify` and teach nothing. It requires the choice
  to be stated instead — `EGL_UNSIGNED_TAG_REASON="…"` — which is what #115 means by making the
  failure impossible to repeat *silently*. Annotated rather than edited, per ADR-0041's precedent.
- **Date:** 2026-08-05
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 7.3 · spec §8 (CI/CD & release engineering), NFR-07 ·
  [AGENTS.md §11](../../AGENTS.md) (the agent-vs-human release boundary) ·
  [ADR-0003](0003-pin-ci-actions-by-commit-sha.md) (the pinning policy this obeys) ·
  [ADR-0031](0031-run-the-bc-checker-outside-the-dependency-graph-and-gate-breaks-by-bump.md) (the
  release-PR gate this follows in the same pipeline) · ROADMAP item **1.9** (the `matrix.toolchain`
  defect corrected here) · `docs/workflow/release.md`

## Context

The generated `release.yml` drafted a GitHub Release from whatever was tagged. It checked that
`composer install` succeeded and nothing else — no signature, no test run, and no check that the tag
agreed with the tree it pointed at.

A tag is the one artifact in this project that **cannot be corrected in place** once it is published
and consumed. Packagist serves it, `composer.lock` files pin it, and the remedy for a bad one is a
new version plus an erratum. So the asymmetry is stark: a few minutes of verification against a
permanent mistake.

Reading the file also turned up a defect. Its single job referenced `matrix.toolchain`:

```yaml
php-version: ${{ matrix.toolchain == 'php-8.1' && '8.1' || matrix.toolchain == 'php-8.2' && '8.2' || '8.3' }}
```

…while declaring **no matrix**, so the expression silently fell through to `'8.3'`. That is exactly
the rendering artifact roadmap item **1.9** identified and fixed in `ci.yml`'s benchmark job; nobody
went back for `release.yml`. It "worked" — which is why it survived.

## Decision

### 1. Nothing is drafted until the tag is verified

`verify-tag` runs first and gates the rest. `draft-release` needs it *and* the test matrix, so a
failed check means no draft exists at all rather than a draft a human might publish before reading
the logs.

### 2. The tag must be annotated **and** signed

Annotated first, because a lightweight tag has no tagger, no date, no message — and cannot carry a
signature, so the signature check would report something confusing about an object that was never a
candidate.

Signing is a hard failure, not a warning. A release tag is an assertion about who cut a release; an
unsigned one asserts nothing, and a warning nobody has to act on is the "gap nobody closes" pattern
this project has already had to go back and fix twice.

### 3. The signature is verified by **asking GitHub**, not by importing keys

`gh api repos/{repo}/git/tags/{sha}` returns a `verification` object; the gate requires
`verified == true`.

The alternative — fetch the maintainer's public keys, import them into a runner keyring, and
`git verify-tag` — verifies locally but adds key material to CI and a keyring that silently goes
stale on a key rotation. GitHub already holds those keys and is already the trust root for the
checkout, the token and the release API in this same workflow; adding a second, worse-maintained
copy of the same trust would reduce reliability without adding independence.

### 4. `tools/release_gate.py` checks the tag against the tree, which no lint can

`consistency_lint.py`'s `version-lockstep` keeps the `VERSION` constant, the README badge and the
latest release file in agreement — but it runs on a working copy and has **no tag**.

`git tag -a v0.2.0` on a tree whose constant still says `0.1.0` yields a release that installs as
0.2.0 and reports itself as 0.1.0. Nothing *inside* the tree disagrees with itself, so every
existing check passes. That is the specific hole, and it is only visible at tag time.

The gate also requires the release notes and the per-version changelog split to exist **and be
indexed** — an unlinked per-version file is one nobody reaches from the changelog they actually open.

### 5. The matrix is restored rather than the expression hardcoded

The expression was written for a matrix; the honest fix is to give it one. It is also load-bearing
here for a reason the original job did not have: **a tag can be pushed at a commit CI never ran**, so
the tagged tree is tested on 8.1, 8.2 and 8.3 rather than trusted because some PR was once green.
Hardcoding `'8.3'` would have made the expression truthful and left the release untested on the
floor this library promises.

`coverage: pcov` is dropped: the job runs no coverage gate, and instrumentation would only slow it.

### 6. Packagist **pulls**; this workflow does not push

Packagist mirrors the repository through its own GitHub integration, so a tag push updates the
package with no Packagist token held here. That is one fewer publishing credential in one fewer
place.

An explicit API call would add nothing the integration does not already do, and would cross
AGENTS.md §11's line: the agent drafts, the maintainer publishes. So the workflow prints the package
URL to confirm after publishing, and the one-time integration setup is documented as a maintainer
task.

## Alternatives Considered

- **Draft first, verify after** — rejected in §1: a draft is publishable, and a human reading a
  green-looking draft is unlikely to go hunting for a failed job.
- **Warn on an unsigned tag instead of failing** — rejected in §2. It would make the supply-chain
  half of this item decorative.
- **Import public keys and `git verify-tag` on the runner** — rejected in §3: key material in CI and
  a keyring that goes stale on rotation, in exchange for no independence from a trust root the
  workflow already depends on.
- **Hardcoding `php-version: '8.3'`** — rejected in §5: truthful, and it would leave the release
  untested on PHP 8.1.
- **Publishing to Packagist from CI with an API token** — rejected in §6 on both credential-surface
  and boundary grounds.
- **Letting the consistency lint own the tag check** — impossible, and worth stating: it has no tag
  to check. §4.
- **Signing the tag as part of a workflow** (a CI-held key) — rejected: it would make CI, not the
  maintainer, the identity asserted by the release, which inverts what a signature is for.

## Consequences

- `release.yml` becomes three jobs — `verify-tag`, `test-tagged-tree` (matrix), `draft-release` — and
  the item 1.9 defect is corrected in the second place it occurred.
- New `tools/release_gate.py`. **Proven to fail before being trusted** (lesson L-0008), six cases
  with verified exit codes: a complete fixture passes; a tag disagreeing with the constant, missing
  release notes, an unindexed changelog split, a malformed tag, and a missing version file all exit
  1. It also correctly reports four problems against the repository's current pre-release state.
- `docs/workflow/release.md` gains a *Release-time gates* table and a **one-time maintainer
  prerequisites** section. Both prerequisites — a signing key on the GitHub account, and the
  Packagist integration — are the maintainer's, and **the first release cannot succeed without
  them**. That is stated where the release steps are, not only here.
- 32 action pins across three workflows verified upstream.
- **Untested end to end, and unavoidably so.** No tag exists, so the signature check, the API shape
  it depends on, and the draft step have not run for real. The same limitation ADR-0031 recorded for
  the BC gate, and for the same reason: the first release exercises both. The pieces that *can* be
  tested away from a tag — `release_gate.py`, the YAML, the pins — are.

## References

- Spec §8 and NFR-07 — release engineering and the gate set
- AGENTS.md §11 — the agent tags and drafts; the maintainer publishes
- ROADMAP 1.9 — the `matrix.toolchain`-without-a-matrix defect, first found in `ci.yml`
- GitHub REST: `GET /repos/{owner}/{repo}/git/tags/{tag_sha}` and its `verification` object
