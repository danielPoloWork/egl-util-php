# 2026-08-27 — The objection was true when it was written, and we had already broken it

Issue **#115**, criterion 3. Route `standard / medium`; session model Opus 5. **ADR-0084**
annotated. **The issue stays open** — criterion 1 is the maintainer's.

Asked to do #115 "if it wasn't already done", and the answer was "mostly, in pieces, over three
weeks" — which made the interesting question not *what to build* but *what is actually left*.

## Reading the record before writing anything

`ISSUES.md` carried a precise account: criterion 2 (the pre-push tag guard) done 2026-08-24,
criterion 1 (register a signing key) the owner's, criterion 3 (attestations) **deliberately not
done**, with a stated reason. That last one is the kind of entry I have learned to re-read rather
than trust, not because the reasoning was careless but because reasons have dates. It said:

> artifact attestations would attest an archive built by our CI, while the artifact consumers
> install is the zip Packagist builds from the tag — so they would not cover what anyone receives,
> and attaching our own asset to make them verifiable reverses `release.md:65`'s recorded decision
> that no build artifacts are attached.

Two claims. The first is still true and is the important one. The second is not: **ADR-0076 —
issue #98, which I worked earlier in this same session — reversed that decision itself.**
`release.yml` line 184 attaches `bom.xml`, and the "no build artifacts are attached" sentence in
`release.md` was replaced in that PR by one describing the SBOM.

So criterion 3 was being declined on the strength of a constraint the project had since removed for
unrelated reasons, and nobody had gone back to notice. That left the actual state of things worse
than the objection contemplated: a shipped, trusted-looking, **unverifiable** artifact on every
Release.

## What made this worth doing rather than checkbox-clearing

I was wary here, because "a declined criterion becomes doable" is exactly the shape of rationalising
your way into work the record already said no to. The thing that decides it is not that attestation
became *possible* — it is that an unattested SBOM is *actively worse than no SBOM*. It is a
supply-chain claim ("these are the packages you install") that anyone with write access to the
Release can swap, and a consumer cannot tell. The file's presence invites trust it has not earned.
That is a real defect, introduced by my own earlier PR, and it did not exist when the objection was
written.

## Being precise about what it does not do

The original objection's first half stands entirely, and the ADR says so twice because it is the
half that matters: **this attests the SBOM, not the source.** What `composer require` installs is the
zip GitHub builds from the tag on Packagist's behalf. This workflow does not produce that zip and
cannot attest it. A consumer who verifies the attestation learns that our pipeline produced this
SBOM; they learn nothing whatsoever about the archive they installed.

Which means **criterion 1 is still the one that closes the chain**, and I left the issue's checkbox
unticked. No tooling in this repository can create a signing key for someone's identity, and none
should be able to — I hit that wall concretely while working #105, when I could not re-sign the
`v1.1.0` tag and had to hand it back. Closing #115 on the strength of an SBOM attestation would be
precisely the checkbox-satisfaction the issue was filed to prevent.

## The part I cannot verify, and what I did instead

`release.yml` has never run past `verify-tag`. All three release attempts died there on unsigned
tags — that is what #115 *is*. So this step has never executed and will first run on the
maintainer's first signed release, which is an uncomfortable thing to hand someone.

Two things instead of pretending otherwise. The action is SHA-pinned per ADR-0003 and the pin
**verified against upstream** — `action_pin_lint.py --verify-upstream` resolves the `v4.2.2` comment
to the pinned SHA rather than taking my word for it, which is the check that would have caught a
transposed digit. And the workflow parses under a real YAML load with the job's permissions and step
order asserted, which caught the thing worth catching: a job-level `permissions:` block **replaces**
the workflow-level one rather than merging, so adding `id-token`/`attestations` without restating
`contents: write` would have left the job unable to draft the Release it exists to draft. That is
commented in the workflow, not just here, because it is the trap the arrangement invites.

It is placed to fail closed — before the draft, so an unsignable SBOM yields no draft rather than a
published-but-unverifiable asset. ADR-0032's posture for everything else on that path, and the
alternative would make the attestation decorative in exactly the way an unattested SBOM already is.

## Where this leaves the project

One workflow step, one permissions block, documentation, and two stale records corrected: criterion
3's rejection reason, and the `v1.1.0` line in `ISSUES.md` (that tag was deleted while working #105,
so the repository no longer carries a dangling failed-release tag). No production code changed.

The chain is now: SHA-pinned actions (ADR-0003), a signed-tag gate that works (ADR-0032), a
pre-push guard that catches an unsigned tag before it leaves the machine (criterion 2), an attested
SBOM (here), and post-publish verification of what actually reached the world (ADR-0081). Every link
except one, and the remaining one is a key only the maintainer can register.
