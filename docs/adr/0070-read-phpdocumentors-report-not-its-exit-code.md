# ADR-0070: Read phpDocumentor's report, not its exit code

- **Status:** Accepted
- **Date:** 2026-08-21
- **Deciders:** maintainer (`@danielPoloWork` — chose *implement and publish to Pages* over striking
  the claim), agent acting as tech-lead
- **Related:** ROADMAP item **13.7** · issue
  [#107](https://github.com/danielPoloWork/egl-util-php/issues/107) · `AGENTS.md` §10 (the claim
  this makes true) ·
  [ADR-0031](0031-run-the-bc-checker-outside-the-dependency-graph-and-gate-breaks-by-bump.md) and
  [ADR-0040](0040-install-the-mutation-tester-outside-the-graph-and-keep-nfr07s-own-number.md) (tools
  installed outside the dependency graph) ·
  [ADR-0003](0003-pin-ci-actions-by-commit-sha.md) (pinning) ·
  [ADR-0068](0068-intersect-the-report-with-the-diff-and-ship-the-proof-it-can-fail.md) and
  [ADR-0069](0069-resolve-links-in-the-lint-and-refuse-to-guess-a-numbered-section.md) (ship the
  proof a gate can fail)

## Context

`AGENTS.md` §10 has listed **"API docs — `phpDocumentor` builds without warnings"** as a mandatory
quality gate since this repository was generated. `docs/development/local-build.md` named
phpDocumentor a prerequisite and `docs/workflow/documentation.md` said the build "must be
warning-free". **No config, no dependency, no CI job, no command and no published output existed
anywhere in the tree.** A fresh clone could not find the toolchain three documents required.

Item 13.7 offered two routes: wire it, or strike the claim. The cost of wiring it was unknown, so
it was measured before deciding — and the measurement produced the decision *and* this ADR's
subject.

**What the probe found, in this order:**

1. **`latest` is not usable.** phpDocumentor **v3.10.0**, which the `latest` release URL resolves
   to, dies on startup — `Finder::getInstalledPackagesByType(): Return value must be of type array,
   null returned` — before printing its own `--version`. **v3.7.1** runs.
2. **The build is cheap and already almost clean.** 109 files, ~20 seconds, 236 HTML pages.
3. **And it exits `0` while reporting errors.** The console printed `All done in 20 seconds!`, the
   exit status was `0`, and `build/api/reports/errors.html` held **five ERROR rows** — every
   `@return self<U>` in `Errors/Result.php`, rejected as *"self is not a collection"*.

Point 3 is the decision. A CI job of the obvious shape — run the tool, trust `$?` — would have gone
green having verified nothing. That is this project's most repeated failure: item **2.7**
(a gate wired to nothing), item **10.8** (a mutation gate that ran on an absent config for months,
passing in ~7s), item **13.2** (my own harness printing a PHP block as text, exit 0, reported
`PASS`). Three instances already, in three different tools.

## Decision

### 1. The verdict comes from `reports/errors.html`, never from the exit code

`tools/api_docs_gate.py` parses the report phpDocumentor writes and fails on any row whose severity
cell names one. phpDocumentor's own exit status is ignored.

**A missing or unparseable report fails with exit 2**, not 0. "No failures found" and "nothing was
looked at" produce identical output from any check that only greps for the word `ERROR`, and closing
that hole is the whole reason this gate exists rather than a one-line `run:` step. When the report
exists but yields no row the parser recognises, the gate says so and tells the reader to fix the
parser — **not** to relax the check.

`tools/tests/verify_api_docs_gate.py` proves all of it: a clean report passes, a report naming
errors fails, a **missing** report exits 2, an **unparseable** report exits 2, and the count excludes
the report's own `Type | Line | Description` header row — which the first implementation counted,
inflating three findings to five. A gate that cannot count is only marginally better than one that
cannot see.

### 2. The eight generic annotations were fixed at the source, not suppressed

The errors were real: `@return self<U>` / `@return self<TItem>` — PHPStan's generics syntax, which
phpDocumentor's type parser does not implement. Five in `Errors/Result.php`, then three more in
`Dto/Collection.php` that only surfaced once the first five were gone.

They are now written with the class named explicitly — `Result<U>`, `Collection<TItem>` — which
PHPStan accepts identically. **PHPStan at max level still reports `No errors`**, CS-Fixer is clean
across 282 files, and the 51 `Collection`/`Result` tests pass. Nothing was traded.

**The alternative considered and rejected** was `<ignore-tags>` on `@template`. It would have made
the build clean by hiding the generics from the published reference — spending a consumer-facing
guarantee to spare one tool's parser. The config carries **no** `ignore-tags` block, deliberately.

### 3. phpDocumentor is not a `require-dev` entry

It is fetched as a PHAR in CI, **pinned by version and by SHA-256**, exactly as ADR-0031 and
ADR-0040 handle Roave's BC checker and Infection. This library's only hard runtime dependencies are
two PSR interface packages; phpDocumentor would drag its own tree into that, and
`--prefer-lowest` would then resolve against it.

Pinning by checksum is not ceremony here: `latest` is a version known to crash (Context point 1), so
a floating reference would have failed the first time upstream cut a release.

### 4. The gate runs on every pull request; publishing runs only on `master`

Two jobs in `.github/workflows/api-docs.yml`. The gate is a PR check — a docblock is precisely what
it inspects, so a docs-only PR is not exempt. `publish` deploys to **GitHub Pages** and is gated on
`gate` passing.

`publish` **re-asserts the gate** rather than trusting the PR's green check: that check ran on the
pull request's merge commit, and a green result on a different tree is not this tree's proof. The
same reasoning ADR-0069's amendment earned the hard way — a checker's green run somewhere else is
not evidence about here.

Pages is created via `actions/configure-pages` with `enablement: true`, so no manual settings change
is needed; if the token may not create it, the step fails loudly rather than publishing a partial
reference.

## Alternatives Considered

- **Strike the claim from `AGENTS.md`, `local-build.md` and `documentation.md`.** Item 13.7's other
  route, and the cheapest true state. Rejected once measurement showed the build takes 20 seconds
  and was three annotation fixes from clean: the claim was *achievable*, and a library whose public
  surface is 117 documented classes gains a real consumer artifact by keeping it. Had phpDocumentor
  produced hundreds of findings, this is the option that would have won.
- **Trust phpDocumentor's exit code.** The obvious one line. It reports green on a build with
  errors — demonstrated on this repository before anything was written.
- **`--ignore-tags` on `@template`.** Clean build, hidden generics. See Decision 2.
- **phpDocumentor as `require-dev`.** See Decision 3.
- **Doctum instead** (issue #107 names it as an option). Not evaluated: phpDocumentor cleared the
  bar, and a second candidate would only matter if the first had failed. Recorded so nobody assumes
  a comparison happened.

## Consequences

- `AGENTS.md` §10's API-docs row is now true, and enforced by something that cannot pass vacuously.
- The published reference is a consumer artifact the README can point at — the reference need item
  13.2 met by directing readers at the source tree.
- **A new upstream release cannot silently change the build**: the version and its checksum are
  pinned, so an upgrade is a deliberate edit. It is also a *required* edit eventually, since v3.7.1
  will not receive fixes forever.
- **The report format is now load-bearing.** If phpDocumentor changes it, the gate exits 2 and says
  so, rather than degrading to a pass. That is the intended failure direction and it will cost an
  investigation someday.
- Generic annotations in this codebase name their class rather than using `self<T>`. Not a style
  preference — `self<T>` is what phpDocumentor rejects, and the gate will now catch a reintroduction
  on the PR that makes it.

## References

- ROADMAP item **13.7** · issue [#107](https://github.com/danielPoloWork/egl-util-php/issues/107)
- `AGENTS.md` §10 — the quality bar this makes true
- [ADR-0031](0031-run-the-bc-checker-outside-the-dependency-graph-and-gate-breaks-by-bump.md),
  [ADR-0040](0040-install-the-mutation-tester-outside-the-graph-and-keep-nfr07s-own-number.md) — tools
  outside the dependency graph
- [ADR-0003](0003-pin-ci-actions-by-commit-sha.md) — pinning
- [ADR-0068](0068-intersect-the-report-with-the-diff-and-ship-the-proof-it-can-fail.md),
  [ADR-0069](0069-resolve-links-in-the-lint-and-refuse-to-guess-a-numbered-section.md) — ship the
  proof a gate can fail
