# 2026-08-09 — The release notes said the gate approved it, and the gate had refused it

Roadmap item **13.1**, filed by the 2026-08-09 release review board and tracked as issue **#122**.
Route `standard / medium`; session model Opus 5 — matched.

The item is one sentence long in its effect: `docs/releases/v1.0.0.md` told a consumer that the
tree they were about to install was *"the one the gate approved"*, and the gate had refused it.
Everything else in this entry is what fixing one sentence honestly turned out to require.

## The fork was the maintainer's, and it was put to them

AGENTS.md §6.1 reserves release provenance to the maintainer, and the item was filed with two
mutually exclusive remedies rather than a recommendation:

- **re-cut the tag signed**, let `release.yml` run green, and the existing sentence becomes true —
  the version that also produces the tagged-tree matrix evidence;
- **record the bypass** — keep the release as published and amend the documents to say what
  actually happened.

The maintainer chose to record the bypass. Worth naming why the choice was live at all: the signed
re-cut is still *clean* here, because `egl/utils` is not registered on Packagist (issue #121), so
nothing in the world can resolve the tag and deleting it breaks no consumer. That window closes at
registration. The decision was not a concession to difficulty.

Equally worth naming: **the signing decision itself was never the defect and was not reopened.** It
was taken twice — at `v0.11.0` and again at `v1.0.0` — with the outcome known in advance both
times. The defect was a document asserting the opposite of the repository's own audit trail. Only
that was fixed.

## What the gate's refusal actually cost

`verify-tag` failing is not one missing checkmark. Every later job in `release.yml` depends on it,
so run 31283673519 skipped:

- the **tagged-tree 8.1/8.2/8.3 matrix** — the job that re-runs the suite against the tree *the tag
  points at*, which exists precisely to catch a tag pointing at a commit CI never exercised
  (ADR-0032). Nothing else in the pipeline substitutes for it;
- the **draft-Release job** — hence the hand-published Release thirteen minutes later.

So the notes needed more than a deletion. They needed a *scope* on the verification numbers: those
2 831 tests were real, measured on the pull request for `be7f34e`, which is the commit the tag
points at — what is missing is the independent re-run at the tag. The notes now say which tree they
speak for, and tell a reader who needs that assurance to verify the tag against `be7f34e`
themselves.

## The finding that made me re-read the evidence instead of citing it

Writing "and all fifteen checks passed on the release PR" was the obvious sentence, and it is
true. I pulled the job logs anyway, because this item exists to remove a claim someone made without
pulling them.

`quality / backward compatibility` passed on PR #82 **in six seconds having compared nothing**:

```
##[notice]This repository has no v*.*.* tag, and roave/backward-compatibility-check
needs one to compare against. The gate self-enables at the first tag.
```

`v0.11.0` had already been deleted, so the job found no tag, self-skipped, and reported green. That
is correct behaviour for a first release — there was no predecessor to compare against — but
folding it into a count of fifteen would have reproduced, *inside the fix*, the exact class of claim
the item exists to delete. It is named explicitly in the notes instead.

This is item **10.8**'s lesson — *read the job, not the checks column* — with a second instance, and
the second instance is the interesting one: recognising the pattern once did not stop me from
nearly walking into it while repairing an example of it.

## One artefact that cannot be repaired

The copy of `docs/releases/v1.0.0.md` **inside the tagged tree** still ends with a section carried
over from the unpublished `v0.11.0` notes — the 1.0.0 API-freeze review *"has not happened"*, this
is *"a `0.x` release"* — and records the bridge's constraint on the core as `^0.11`. Both are false
of what shipped. PR #83 corrected them at `HEAD`; a tag is immutable, so a reader arriving via the
tag meets the stale text regardless. The only available remedy is to say so at HEAD, which the
notes now do.

## The claim had been copied twice, and the copies are the tell

`grep` found the sentence in two more tracked files, neither named by the issue:

- `docs/changelog/v1/v1.0.0.md` § *Superseded pre-release* — corrected in place, with the bypass
  recorded;
- **ADR-0059** Decision point 4 — *annotated, not rewritten*, per ADR-0041's precedent: a Status
  note correcting the fact, plus an inline marker at the point itself so a reader who lands there
  directly is not left with the falsehood. Nothing decided in that ADR changes; superseding
  `v0.11.0` and deleting its tag stand exactly as recorded.

**Why all three said the same wrong thing is the reusable part.** None of them was a mistake about
the past. All three were written *before the tag was pushed*, describing a release gate that was
expected to pass — and then the tag went up unsigned and nobody revisited the prose. A claim about
the future, recorded in the past tense, reads identically to a verified fact once the moment passes.
Release documents drafted ahead of the release should state intent as intent, or be re-read against
the run afterwards. This project has a lint for version lockstep, the ADR index, pattern rows, the
spec coverage map and milestone agreement; none of it can catch a true-when-written sentence going
false. Item 13.4's link checker will not catch this class either.

## Left deliberately undone

The GitHub Release body carries the same correction, but editing a published Release is an
outward-facing act: it was drafted here and applied only on the maintainer's explicit confirmation,
not as a side effect of the merge.
