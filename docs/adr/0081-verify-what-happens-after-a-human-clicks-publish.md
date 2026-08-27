# ADR-0081: Verify what happens after a human clicks Publish

- **Status:** Accepted
- **Date:** 2026-08-27
- **Deciders:** project architect (agent), maintainer
- **Related:** issue [#105](https://github.com/danielPoloWork/egl-util-php/issues/105) ·
  [ADR-0032](0032-verify-the-tag-before-drafting-and-let-packagist-pull.md) (the pre-publish gates
  this closes the other end of) ·
  [ADR-0071](0071-one-dsn-points-the-behavioural-suites-at-an-engine-and-an-unreachable-one-is-red.md)
  (the "absence is failure, never a pass" posture reused here) · `docs/workflow/release.md` §
  *Cutting a release* step 11, § *Boundary*

## Context

`release_gate.py` verifies a tag agrees with the tree it points at; `release.yml`'s `verify-tag`
job then checks the tag is annotated and signed before drafting a GitHub Release. Publishing that
draft is deliberately a human's click alone (AGENTS.md §11) — and nothing in the pipeline runs
after it. Issue #105 (Release Manager seat, 2026-08-09) names the gap directly: the `v1.0.0`
hand-publication (documented in issues #115, #122) produced a Release the pipeline never saw, and
the repository had no tool that could even notice such a divergence, let alone this one.

**The gap stopped being hypothetical while this issue was being worked.** Checking the repository's
actual state before writing anything: `v1.1.0` was tagged (per the tagger, 2026-08-24) and pushed
to origin, but the tag was **unsigned**. `verify-tag` correctly refused it (workflow run
`32730156021`, job "verify v1.1.0 is signed and consistent", conclusion `failure`), which meant
`draft-release` never ran. The tag sat on origin for three days: no GitHub Release, no entry on
Packagist (`repo.packagist.org/p2/egl/utils.json` listed only `v1.0.0`), while `ROADMAP.md`'s own
text — *"First MINOR under that tree: `v1.1.0` (2026-08-21)"* — read as though it had shipped.
Nothing in this repository had ever checked, because nothing existed that could.

## Decision

**`tools/post_publish_gate.py` asks, of a specific tag, whether it actually reached the world — and
a nightly CI job asks the same question of whatever the latest published Release happens to be,
because the manual step alone is the exact mechanism that just failed.**

### 1. Four checks, and why each is verified rather than assumed

- **The tag is signed and verified.** The same fact `verify-tag` checks pre-publish, re-asked here
  because a tag can be deleted and re-pushed after that job ran, or — `v1.1.0`'s case exactly —
  the job can have run, failed, and nobody circled back.
- **A GitHub Release exists and is not a draft.** A drafted-but-never-published Release is
  indistinguishable from an unpublished one to every consumer; both report as "not released" here,
  which is the honest answer.
- **The body matches the canonical notes.** Reuses `release_body.py` as a subprocess rather than
  re-implementing its link-rebasing — two renderers of the same source drifting apart is exactly
  the defect class a second implementation would risk. Checked as a **prefix match**, not equality:
  `release.yml` passes `generate_release_notes: true` alongside `body_path`, and GitHub appends its
  own auto-generated list after the given body rather than replacing it (the step's own comment:
  *"the rendered notes are the substance; GitHub's generated list is appended context"*). A
  mismatch means either a hand-edit — `release.md` already forbids this — or the mechanism itself
  drifted; the gate does not distinguish which, because both are worth a human's attention.
- **The version resolves on Packagist**, queried at `repo.packagist.org` rather than
  `packagist.org` — the mirror is what Composer actually reads and is the origin's own recommended
  read endpoint, needing no auth.

### 2. Exit codes distinguish "verified fine" from "could not check"

`dist_gate.py` and `release_gate.py` both already refuse to let an incomplete check pass as a
green result. This gate follows the same shape with a third state, because a network-dependent gate
has a failure mode the tree-only gates do not: exit **0** all four checks ran and passed; **1** a
check ran and found a real problem; **2** a check could not complete at all (the tag or Release does
not exist, the network is unreachable, `release_body.py` itself failed). Collapsing 1 and 2 into one
"non-zero" would let "the network was down" and "the release is broken" print the same colour, and
this artifact — a published release — is the one thing in this project that cannot be corrected in
place, so that distinction is worth the extra branch.

### 3. Nightly, not only manual — because the manual step is what just failed

Issue #105's literal acceptance criterion is a documented manual step, and that is what step 11 of
`docs/workflow/release.md` now is. **It is not, on its own, what this ADR ships**, for a reason
demonstrated rather than argued: `v1.1.0` sat broken for three days precisely because verifying a
release depends on someone remembering to, and the agent that tagged it had no reason to look back.
A manual step closes the acceptance criterion; it does not close the gap the issue is actually
about.

`nightly.yml`'s `post-publish` job resolves the latest non-draft, non-prerelease GitHub Release and
runs the gate against it, on the same fixed clock as the audit and mutation jobs already there —
"nobody has to remember" is the reason every job in that workflow exists, and this one is no
different. It costs one more nightly job, cheap relative to the mutation run already in the same
file, and it converts "found by accident, three days later" into "found within a day, automatically"
for exactly the failure mode this issue names.

### 4. The `v1.1.0` tag was deleted, not left dangling

Discovered mid-investigation and confirmed with the maintainer before acting: the unsigned `v1.1.0`
tag was deleted from origin (`git push origin --delete v1.1.0`) and locally. This is not a new
authority claimed for this ADR — `docs/workflow/release.md`'s existing *Boundary* section already
states agents "only delete-and-repush an *unpublished* tag whose release run visibly failed," which
is precisely this case: unpublished (no Release, not on Packagist) and visibly failed
(`verify-tag`, workflow run `32730156021`, `conclusion: failure`). The tag was **not** re-signed and
re-pushed in this PR — this session has no GPG or SSH signing key for the maintainer's identity, and
producing one is not something an agent should ever hold. Re-tagging `v1.1.0` correctly signed is
the maintainer's own next step, outside this PR.

## Alternatives Considered

- **A single equality check on the Release body.** Rejected in §1: `generate_release_notes: true`
  means GitHub appends its own content, so an exact match would fail on every correctly-published
  release, defeating the check's own purpose.
- **Re-implement the notes rendering instead of shelling out to `release_body.py`.** Rejected: it
  is exactly the two-renderers-drift risk `release_body.py`'s own docstring exists to avoid, applied
  to a second consumer instead of a first.
- **Query `packagist.org` directly instead of the `repo.packagist.org` mirror.** Rejected: the
  mirror is what Composer itself resolves against and is documented as the intended machine-readable
  endpoint; the main site is for humans and rate-limits harder.
- **A CI-only check, wired into `release.yml` itself.** Rejected: `release.yml` triggers on the tag
  push, which happens *before* a human publishes — there is no event this repository's workflows
  fire on when a draft becomes published, so nothing can run synchronously with that click. Nightly
  polling is the honest substitute, not a compromise.
- **Manual step only, no nightly job.** This is the issue's literal ask, and was seriously
  considered given the "no scope creep" instruction this project runs under. Rejected on the
  evidence gathered while writing it: the manual step is exactly the mechanism that already failed
  once, in this repository, during this issue's own investigation. Shipping only the acceptance
  criterion would have closed the issue while leaving its actual motivation open.
- **Re-sign and re-push `v1.1.0` as part of this PR.** Rejected — see §4: this session holds no
  signing key for the maintainer's identity, and should not. Deleting the broken tag is within
  documented agent authority; producing a valid signature is not something an agent can do at all,
  by design.

## Consequences

- **No production code changes.** One new tool (`tools/post_publish_gate.py`), one new nightly CI
  job, one documented release step, and this ADR.
- **Every future release gets checked twice**: once by whoever publishes it (documented as step 11),
  and once more within 24 hours regardless of whether anyone remembered.
- **The checks are read-only.** Nothing in this gate can modify a tag, a Release, or Packagist state
  — it only reports.
- **No offline unit test for the gate's network-dependent logic**, matching this repository's own
  precedent: `action_pin_lint.py`'s `--verify-upstream` path (the closest existing analogue — a
  gate whose core logic is a GitHub API call) has none either, and every test file under
  `tools/tests/` invokes its gate as a subprocess rather than importing it, which a
  network-dependent gate cannot do hermetically without a mocking layer this project has not
  otherwise adopted. Verified instead by running it against this repository's own real state —
  `v1.0.0` (documented gaps: unsigned tag, hand-written body, both already tracked by issues #115,
  #122), a nonexistent tag (correctly reports "could not verify" for the tag/body checks and "not
  on Packagist" / "no Release" as real problems), and `v1.1.0` before its tag was deleted (the same
  shape, live).
- **`v1.1.0` no longer exists as a tag.** The tree state it would have released (M13's close-out,
  M14's five additive seams) is untouched and still on `master`; only the broken, never-published
  tag is gone. Re-tagging it, signed, is the maintainer's next action and is outside this PR.
- **Known limitation, stated rather than discovered:** the nightly job checks only the *latest*
  non-draft Release. An older, still-supported line (per `maintenance.md`) that somehow regressed on
  Packagist after the fact would not be caught. Recorded rather than built around, because this
  repository has never had more than one release line and adding scope for one that has never
  existed is exactly the over-building this project's own conventions warn against.

## References

- Issue [#105](https://github.com/danielPoloWork/egl-util-php/issues/105) — 2026-08-09 Release
  Review Board, Release Manager seat.
- Workflow run `32730156021` — `v1.1.0`'s `verify-tag` failure, the live evidence for this decision.
- [`docs/workflow/release.md`](../workflow/release.md) § *Cutting a release* step 11, § *Boundary*.
- [ADR-0032](0032-verify-the-tag-before-drafting-and-let-packagist-pull.md) — the pre-publish half
  of the pipeline this ADR closes the other end of.
