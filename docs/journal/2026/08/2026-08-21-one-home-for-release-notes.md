# 2026-08-21 — Two documents, not two copies

Roadmap item **13.5**, issue **#106**. Route `standard / medium`; session model Opus 5 — matched.

The item asked me to pick one home for release notes and reduce the other to a pointer. Doing
that would have deleted a document. Almost everything below follows from measuring the two
files before choosing between them.

## The premise was wrong

13.5 reads: both `docs/releases/` and `docs/changelog/v1/` hold a `v1.0.0` file, so *"a
maintainer updating 'the' release notes has even odds of editing the copy nobody reads."* The
first clause is true. The second assumes they are copies.

They are not:

| | Lines | Shape |
|---|---|---|
| `docs/releases/v1.0.0.md` | 156 | consumer narrative — what the freeze means for you, what to know before upgrading, known limits, how it was published |
| `docs/changelog/v1/v1.0.0.md` | 1,213 | Keep-a-Changelog record — `Added`/`Changed`/`Fixed`/`Deprecated`/`Removed`/`Security` |

One answers *"should I upgrade, and what should I know first."* The other answers *"what exactly
changed."* Collapsing either into a pointer destroys an artifact.

So the canonical-home question got answered **per document** instead of between them:
`docs/releases/` is canonical for the Release body, `docs/changelog/` for the record. The actual
defect was that every pointer concealed the other document — root `CHANGELOG.md` named only the
changelog, `docs/README.md` only the releases directory, and `docs/changelog/` had **no README at
all**. Four files now state their own purpose and link to the other.

## The second criterion was already satisfied, and named the wrong file

Issue #106 asks that `release.yml`'s draft job build the Release body from
`docs/changelog/v<MAJOR>/v<X.Y.Z>.md`. Two problems. The job has always built it mechanically —
`body_path: docs/releases/${{ github.ref_name }}.md` — and the file the criterion names is the
1,213-line itemized changelog, which is not a Release body anyone wants.

The criterion's premise is also a misattribution: *"the body was hand-written, which is how the
false 'gate approved' sentence reached consumers."* It was hand-written because `verify-tag`
failed on the unsigned tag and **skipped `draft-release` entirely**. The mechanism was never the
problem. The skipped gate was — which is #115, not this.

I confirmed the direction rather than reasoning about it: the live `v1.0.0` Release body matches
`docs/releases/v1.0.0.md` opening line for opening line.

## What the automated path was hiding

Because `draft-release` had never run, nobody had looked at what it would produce. The notes
carry **five relative links**, written against `docs/releases/`:

```
](../adr/0059-….md)   ](../benchmarks/)   ](../changelog/v1/v1.0.0.md)   ](../workflow/maintenance.md)
```

A Release body is not served from that directory. Published verbatim, those do not resolve. And
the body a human published carries absolute `blob/v1.0.0/docs/…` URLs — **a conversion nothing in
this repository performed.** The manual process was quietly better than the automated one on
exactly the point nobody had checked.

`tools/release_body.py` performs it. The evidence that it is the *right* conversion is not an
argument: run against the current notes, it reproduces **4 of 4** of the published body's GitHub
URLs. The prose differs, because the notes have since been corrected by #122, #121 and #83 — which
incidentally means the published Release is now stale against its own source, and a re-render is
what fixes that.

The fourth URL is the interesting one. My first version emitted
`tree/v1.0.0/docs/benchmarks` where the published body has `docs/benchmarks/` — `normpath()` eats
a trailing slash. Both work; but a gratuitous difference from the body a human had already
reviewed is a difference you then have to explain forever, so the slash is preserved.

The tool **refuses** rather than degrading: exit 2 on a dangling relative link, because rebasing
one publishes a 404 to every consumer, and exit 2 on missing notes.

## Two portability defects this box found and CI never would

`stdout` on a Windows console encodes as cp1252. The notes carry `≥`, `µ` and em dashes, so the
first run died with `UnicodeEncodeError` before printing anything. Fixed by writing explicit UTF-8
bytes.

Then the proof script failed one case — and the failure was `stderr` doing the identical thing to
an error message containing an em dash. **One defect, two streams, and I fixed only the first one
before something else caught the second.** That is the same shape as item 13.2's harness bug
(filtering stdout and stderr together), three days apart, and I walked into it again. CI runs on
Linux and would never have shown either.

Worth naming as a rule: **when a stream-encoding bug appears on one stream, fix every stream in
the same edit.** The second occurrence is not a new bug; it is the same bug you left behind.

## The proof runs on every PR, not only at a tag

`tools/tests/verify_release_body.py`, 11 cases, following `verify_link_check.py`'s pattern:
rebasing a file link, rebasing a directory link with its slash, leaving absolute URLs and bare
anchors alone, dropping the H1, not touching images, and **both refusals**.

It is wired into `ci.yml`'s `consistency` job rather than only into `release.yml`, and the reason
is worth stating: the notes are edited between releases. A dangling link introduced today should
surface on that PR, not at the tag push, where the tool's refusal would read as a broken release
instead of prose that needs a fix.

## Journal index

29 rows against **86** files. 13.5 counted 28 against 69 — the gap grew while the item waited, which
is what an unmaintained index does. Regenerated from disk rather than hand-typed, with ordering
recovered from `git log --diff-filter=A` so that intra-day session sequence is the real one instead
of an invention. All 86 files had a recoverable add-commit and an H1 title, so nothing needed a
guess.

## Left standing

`release.md` step 10 gained the rule that follows from generating the body: **never hand-edit a
published Release**, because the next render overwrites it and the edit leaves no trace in the
record. Correct the notes file.

And the `v1.0.0` Release body is currently stale against its own notes — the corrections from #122
and #121 exist in `docs/releases/v1.0.0.md` and not on GitHub. Re-rendering it is a maintainer
action (AGENTS.md §11), and `python tools/release_body.py --tag v1.0.0` now produces exactly what
should be there.
