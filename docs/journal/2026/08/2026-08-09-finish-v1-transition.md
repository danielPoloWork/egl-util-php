# 2026-08-09 — The section SECURITY.md had been pointing at for its whole life

Roadmap item **7.5**, filed and closed in the same PR. Route `fast / low`; session model Fable 5 —
several tiers above the route, because the session did not start as a roadmap item. It started as a
documentation review, and the review is what produced the item.

## Six reviewers, and the one finding they all had

The maintainer asked for a panel: six agents, each impersonating a documentation professional from
a different enterprise tradition — Google's developer-documentation style guide, an onboarding/
first-call lens, DevDiv-style claim verification against the tree, Diátaxis information
architecture, editorial coherence, and an OSPO compliance audit. Six independent reports, no
cross-talk, one shared rubric.

Mean overall score **5.0/10**. The dimension that failed was not writing quality — tone averaged
6.8 — it was **coherence, at 4.0**, and the reason is timing: `v1.0.0` merged and was tagged by a
parallel session *while the panel was running*. So the panel reviewed a repository mid-transition
and caught it in the act. That is also why I re-verified every Critical and every convergent
finding myself before reporting: three of them had already been fixed by the release commit between
the review starting and finishing, and reporting those would have been noise.

All six, independently, found the same thing first: **there is no consumer on-ramp**. No
`composer require egl/utils` on any surface a consumer reaches, and zero runnable examples. That
one is item 13.2 — too large for this PR, and it needs prose written rather than corrected.

## The find that was not a stale sentence

Most of what the panel caught was straightforward rot: the bridge README still opening *"Status:
scaffold … the converters land in 8.2"* two milestones after they landed; `docs/releases/v1.0.0.md`
announcing the API freeze and then, four sections later, still carrying the `v0.11.0` paragraph
saying *"The 1.0.0 API-freeze review has not happened. This is a `0.x` release."* Its own document
is the refutation. Delete, correct, move on.

The one worth the ADR was different in kind. `SECURITY.md` said:

> After `1.0.0`, the supported window is defined in `docs/workflow/maintenance.md`.

`maintenance.md` has never had a supported-versions section. Not stale — **never written**. A
cross-reference naming a definition that does not exist, and it survived the entire pre-1.0 line
because the clause immediately above it still applied, so no reader ever had cause to follow the
pointer. `maintenance.md` §*Security fixes* compounded it, instructing a backport "to every
supported line" using a term the document never defined.

What makes that the interesting failure is that **nothing in this repository could have caught it**.
`consistency_lint.py` checks version lockstep, the ADR index, pattern rows, the spec coverage map,
milestone agreement and bug-ledger integrity — six real invariants, and not one of them resolves a
link. The defect class is "a document promises that another document answers a question", and we
have no instrument for it. Filed as item 13.4 rather than built here: it is a tool change, and this
item was a policy decision.

Defined the window in **ADR-0060**, reusing ADR-0031's instrument rather than inventing one — count
the window in *published releases*, not calendar time, because a time-based window can elapse with
no release inside it and satisfy its own letter while giving consumers nothing to upgrade to. And
wrote the limit into the policy instead of leaving it to be discovered: the previous-MAJOR row has
**never been exercised**, `1.x` being the only line that has ever existed. It is a commitment made
in advance, and the first `2.0.0` is where it gets tested.

## What I did not fix, on purpose

The v1.0.0 release notes say the tag that shipped is *"the one the gate approved"*. Checking that
sentence was meant to take thirty seconds:

```
$ git cat-file -p v1.0.0 | grep -c 'BEGIN PGP'
0
$ gh run view 31283673519 --json jobs
failure  verify v1.0.0 is signed and consistent
skipped  test ${{ github.ref_name }} on ${{ matrix.toolchain }}
skipped  draft GitHub Release for ${{ github.ref_name }}
```

The failing step is named `The tag must be signed`. Everything downstream was skipped — including
the job that runs the tagged tree on 8.1/8.2/8.3, which is the check ADR-0032 exists to provide:
a tag can point at a commit CI never ran. The GitHub Release exists anyway, published by hand
thirteen minutes after the failed run.

So the sentence is false, and it is false about the same mechanism it credits for refusing
`v0.11.0` — that release's tag was unsigned too, which the notes say out loud. Second occurrence,
not an accident.

I left it exactly as written. The remedy is a choice between re-cutting a published tag signed
(which would make the sentence true and produce the missing matrix evidence) and amending the notes
to record the bypass — and the first of those rewrites the provenance of a published release, which
AGENTS.md §6.1 puts on the maintainer's side of the line. Correcting the prose myself would have
quietly foreclosed the better option. Filed as item **13.1**, with the run id, and raised directly
rather than left in the roadmap to be found.

## Housekeeping

Worked in an isolated `git worktree` — this checkout is shared with parallel sessions, and one of
them landed a release under me while the panel ran, which is the argument for the practice rather
than a hypothetical. The worktree tool named the branch `worktree-docs+finish-v1-transition`;
renamed to `docs/finish-v1-transition` to satisfy §6.2, which the generated name does not.

Milestone 13 opened for the rest of the panel's backlog — seven items, each carrying the evidence
that produced it, so none of them has to be re-derived by whoever takes it.
