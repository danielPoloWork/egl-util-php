# 2026-08-21 — The tool said "All done", exited 0, and had five errors

Roadmap item **13.7**, issue **#107**. Route `standard / medium`; session model Opus 5 — matched.
**ADR-0070.** M13 closes with this.

`AGENTS.md` §10 has listed *"API docs — phpDocumentor builds without warnings"* as a mandatory
per-PR gate since this repository was generated. There was no config, no dependency, no CI job
and no published output anywhere in the tree. Two documents repeated the claim.

## The fork was decided by measurement, not preference

13.7 offered: wire it, or strike the claim. Striking it is the cheapest true state, and I was
inclined that way — a gate nobody executes is worse than an absent one. But deciding without
knowing the cost of the alternative is guessing, so I ran phpDocumentor against `src/main` first.

**20 seconds. 109 files. 236 HTML pages.** The claim was three annotation fixes from being true.
That settled it: keeping a claim you can make true beats deleting it. Had it produced hundreds of
findings the other route would have won, and the ADR records that.

## The finding that shaped everything

```
All done in 20 seconds!
$ echo $?
0
```

And `build/api/reports/errors.html`:

```
ERROR  131  Tag "@return self<U>"  has error self is not a collection
ERROR  106  Tag "@return self<U>"  ...
ERROR   75  ...   (five in total)
```

**phpDocumentor exits 0 while reporting errors.** A CI job of the obvious shape — run the tool,
trust `$?` — would have reported green having verified nothing.

That is the **fourth** instance of this exact class on this project: item 2.7 (a gate wired to
nothing), item 10.8 (a mutation gate passing in ~7s against an absent config, for months), item
13.2 (my own harness printing a PHP block as text, exit 0, `PASS`). Four tools, same shape. It is
no longer a lesson; it is the default assumption. **Ask what a tool's success output looks like when
it has verified nothing, before wiring its exit code to anything.**

So `tools/api_docs_gate.py` takes its verdict from the report. And a **missing** report exits 2
rather than passing, because "no failures found" and "nothing was looked at" are the same bytes to
any check that only greps for `ERROR` — closing that is the entire reason the gate exists instead of
a one-line `run:` step.

## The gate's own first run beat my probe

The probe had said five errors, all in `Errors/Result.php`. Fixed those, rebuilt, ran the gate —
and it found **three more in `Dto/Collection.php`**, which the probe had never surfaced because I
read only the tail of that first report.

A useful ordering: **the probe told me the item was feasible; the gate told me what was actually
wrong.** Those are different jobs and the second one is not optional.

It also over-counted on its first run: 5 where there were 3, because it treated the report's own
`Type | Line | Description` header as a finding. **A gate that cannot count is only marginally
better than one that cannot see** — a wrong number in a red build sends someone hunting for defects
that do not exist.

## Fixed at the source, nothing suppressed

The eight errors were real. `@return self<U>` is PHPStan's generics syntax; phpDocumentor's type
parser does not implement it, so it **drops the tag from the published reference** — the annotation
was not merely unparsed, it was invisible to consumers.

Rewritten to name the class: `Result<U>`, `Collection<TItem>`. PHPStan accepts both forms
identically — **max level still reports `No errors`**, CS-Fixer clean across 282 files, 51
`Collection`/`Result` tests green. Nothing traded.

The tempting alternative was `<ignore-tags>` on `@template`: instant clean build. It buys that by
hiding the generics from the reference — spending a consumer-facing guarantee to spare one tool's
parser. `phpdoc.dist.xml` carries no `ignore-tags` block, and the file says why.

## `latest` is a version that crashes

phpDocumentor **v3.10.0**, which the `latest` release URL resolves to, dies on startup —
`Finder::getInstalledPackagesByType(): Return value must be of type array, null returned` — before
printing its own `--version`. **v3.7.1** runs.

So the PHAR is pinned by version *and* SHA-256. That is not ceremony here: a floating reference
would have been broken on the very first run.

## A lint trap worth knowing

Two ADR cross-references in ADR-0070 pointed at filenames that do not exist — I wrote
`0003-pin-every-ci-action-to-a-commit-sha.md` for `0003-pin-ci-actions-by-commit-sha.md`, and
similarly for 0040. `consistency_lint.py` said **OK**.

Not a defect in the lint: its `links` check reads `git ls-files`, so **a brand-new file's links are
unchecked until it is staged.** CI would have caught it, having committed the file. Locally it is
invisible.

**Run the lint after `git add`, not before.** The one time it matters most — a document you just
wrote, full of fresh references — is exactly the case where an unstaged file gets a free pass.

## Publishing re-asserts the gate

`.github/workflows/api-docs.yml`'s `publish` job is `master`-only, deploys to Pages via
`configure-pages` with `enablement: true` (so no manual settings change), and **runs the gate
again** rather than trusting the pull request's green check. That check ran on the PR's merge
commit; this runs on master's, and a green result on a different tree is not this tree's proof.

That is ADR-0069's amendment — *a checker's green run somewhere else is not evidence about here* —
applied in advance for once, instead of after a red `master`.

## M13 closes

Zero unchecked items. `README.md`'s milestone row flips to done, and `consistency_lint`'s
`milestones` check confirms it structurally rather than on my word.
