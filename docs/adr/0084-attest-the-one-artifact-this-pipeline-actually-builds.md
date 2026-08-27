# ADR-0084: Attest the one artifact this pipeline actually builds

- **Status:** Accepted
- **Date:** 2026-08-27
- **Deciders:** project architect (agent), maintainer
- **Related:** issue [#115](https://github.com/danielPoloWork/egl-util-php/issues/115) criterion 3 ·
  [ADR-0032](0032-verify-the-tag-before-drafting-and-let-packagist-pull.md) (the signed-tag gate,
  and the fail-closed posture reused here) ·
  [ADR-0076](0076-nightly-audit-a-require-checker-gate-and-an-sbom-on-every-release.md)
  (the SBOM this attests — and the decision that **invalidated criterion 3's original rejection**) ·
  [ADR-0003](0003-pin-ci-actions-by-commit-sha.md) (the SHA pin the new action takes) ·
  [ADR-0081](0081-verify-what-happens-after-a-human-clicks-publish.md) (post-publish verification)

## Context

Issue #115 has three criteria and, until now, one of them was recorded as deliberately declined:

> **Criterion 3** — `release.yml` gains GitHub artifact attestations (and optionally gitsign),
> closing the last unsigned link in an otherwise SHA-pinned supply chain.

`ISSUES.md` records why it was declined, and the reasoning was sound *when it was written*:

> artifact attestations would attest an archive built by our CI, while the artifact consumers
> install is the zip Packagist builds from the tag — so they would not cover what anyone receives,
> and attaching our own asset to make them verifiable reverses `release.md:65`'s recorded decision
> that no build artifacts are attached.

**The second half of that reason no longer holds, and this project invalidated it itself.** ADR-0076
(issue #98) added a CycloneDX SBOM as a release asset: `release.yml` line 184 attaches
`bom.xml`, and `release.md`'s "no build artifacts are attached" sentence was replaced in the same PR
by one describing the SBOM. So the objection "attaching an asset would reverse a recorded decision"
is describing a decision that has already been reversed, on purpose, for a different reason.

There is now an artifact this pipeline genuinely builds, ships, and nothing verifies.

## Decision

**Attest the SBOM with `actions/attest-build-provenance`, fail closed, and state precisely what the
attestation does not cover.**

### 1. Why attesting an SBOM is worth doing rather than checkbox-clearing

An SBOM is a supply-chain **claim** — "these are the packages a consumer of this release installs."
An unattested SBOM sitting on a GitHub Release is a claim that anyone with write access to that
Release can replace, and no consumer can tell. That is a worse position than having no SBOM, because
the file's presence invites trust it has not earned.

The attestation produces a signed SLSA provenance statement bound to this workflow, this commit and
this runner, verifiable by a consumer with no cooperation from us:

```bash
gh attestation verify bom.xml --repo danielPoloWork/egl-util-php
```

### 2. What it does **not** cover, said plainly, because overclaiming would be worse than abstaining

The artifact a consumer actually installs is **the zip Packagist builds from the tag**. This
workflow does not produce that zip — GitHub does, on demand, from the tag object (ADR-0032 §"Where
Packagist comes in") — so nothing here can attest it, and this ADR does not pretend otherwise.

That leaves the original rejection's *first* half standing and unaddressed: attestation does not
cover what consumers receive. **The assurance for the source remains the signed tag**, which is
issue #115's first criterion and the maintainer's own action — no tooling in this repository can
create a signing key for someone's identity, and none should be able to.

So criterion 3 is now met for the artifact this pipeline builds, and issue #115 **stays open** on
criterion 1. Closing it on the strength of this change would be exactly the kind of
checkbox-satisfaction the issue was filed to prevent.

### 3. Fail closed, before the draft exists

The attestation step runs *between* SBOM generation and `draft-release`. A release whose SBOM cannot
be attested produces **no draft at all**, rather than a published-but-unverifiable asset. That is
ADR-0032's posture for every other check on this path, and the alternative — attesting after the
draft, tolerating failure — would make the attestation decorative in exactly the way §1 argues an
unattested SBOM already is.

### 4. Permissions on the job, not the workflow

`id-token: write` and `attestations: write` are declared on `draft-release` alone, so `verify-tag`
and the tagged-tree matrix keep the narrower workflow default. A job-level block **replaces** the
workflow-level one rather than merging, so `contents: write` is restated there — omitting it would
leave the job unable to draft the Release it exists for, which is the trap this arrangement invites
and the reason it is commented in the workflow rather than only here.

## Alternatives Considered

- **Leave criterion 3 declined.** The status quo, and defensible until ADR-0076 changed the facts.
  Rejected now for a specific reason rather than a general appetite for closing checkboxes: there is
  a shipped, trusted-looking, unverifiable artifact on every Release, and that is a worse state than
  the one the original rejection was reasoning about.
- **Attest a source archive this workflow builds itself** (`git archive`, attached as an asset), so
  the attestation covers something resembling what consumers install. Rejected: it would ship a
  *second* source artifact that competes with Packagist's for authority, and consumers would have no
  way to know which one `composer require` actually gave them. `dist_gate.py` (issue #119) already
  asserts what Packagist's archive contains; that is the right instrument for that question.
- **`gitsign`**, as the issue offers optionally. Rejected as not addressing this gap: gitsign signs
  *commits*, and the unsigned link #115 is about is the **tag**. A signed tag is criterion 1, and
  gitsign would add a second signing mechanism alongside the one the maintainer still has to set up
  — two half-configured signing paths rather than one working one.
- **Attest, but tolerate failure** so a broken attestation cannot block a release. Rejected in §3.
- **Raise `id-token`/`attestations` to workflow level** — simpler, and grants the OIDC token to jobs
  that have no use for it. Rejected in §4.

## Consequences

- **No production code changes.** One workflow step, one job-level permissions block, documentation.
- **Every future Release carries a verifiable SBOM**, with the verify command documented in
  `release.md` beside the step that produces it.
- **Issue #115 stays open on criterion 1.** Criterion 2 (the pre-push guard) landed 2026-08-24;
  criterion 3 lands here; the signing key is the maintainer's and is the one that actually closes the
  chain.
- **Untested until a signed tag exists, and that is stated rather than papered over.** This
  repository's release workflow has never run past `verify-tag` — all three attempts (`v0.11.0`,
  `v1.0.0`, `v1.1.0`) failed there on unsigned tags, which is what issue #115 is about. So this step
  has never executed, and the maintainer's first signed release is where it will first run. Two
  things were done instead of pretending otherwise: the action is SHA-pinned and its pin **verified
  against upstream** (`action_pin_lint.py --verify-upstream`, which resolves the `v4.2.2` comment to
  the pinned SHA rather than trusting it), and the workflow parses under a YAML load with the job's
  permissions and step order asserted. Neither is a substitute for a real run, and the fail-closed
  placement means a defect here fails the release loudly rather than shipping something wrong.
- **Known limitation, restated because it is the important one:** this attests the SBOM, not the
  source. A consumer verifying the SBOM learns that this pipeline produced it; they learn nothing
  about the zip they installed. Only a signed tag does that.

## References

- Issue [#115](https://github.com/danielPoloWork/egl-util-php/issues/115) — 2026-08-09 release
  review board (Release Manager; Senior Security Engineer).
- [ADR-0076](0076-nightly-audit-a-require-checker-gate-and-an-sbom-on-every-release.md) — the SBOM,
  and the change that invalidated criterion 3's original rejection.
- SLSA provenance / in-toto attestation format; `gh attestation verify`.
