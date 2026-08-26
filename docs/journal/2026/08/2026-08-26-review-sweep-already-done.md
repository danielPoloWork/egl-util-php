# 2026-08-26 — The issue that closed itself, mostly

Issue **#85**, verification only. Route `fast / medium`; session model Sonnet 5.

Nothing to fix. Every one of the ten checklist items was already checked against the current tree
— not assumed from the issue's wording or from the commit that claimed to fix it — and nine were
already correct, landed two weeks earlier by [PR #131](https://github.com/danielPoloWork/egl-util-php/pull/131):

- `Version.php`'s docblock names ADR-0059's post-1.0 SemVer contract, not the pre-1.0 scheme.
- `README.md`'s opening line has no orphaned fragment.
- `local-build.md`'s checklist has no duplicated item.
- `docs/patterns/README.md`'s two scaffold sections carry real, sourced content.
- `nightly.yml`'s comment states the committed-lockfile fact accurately.
- The ADR corpus has zero missing *Alternatives Considered* sections (out of 74 — the corpus grew
  since #85 was filed) and every status string normalises to `Accepted`.
- `ROADMAP.md`'s Spec Coverage Map is entirely ✅.
- `Result::orElseThrow()` carries `@throws Throwable`.
- `docs/workflow/release.md` step 10 correctly says no build artifacts are attached.
- Both `composer.json` files have `homepage` and `support` blocks.

**Five ADRs (0065–0069) do lack a References section**, but they postdate #131 — new drift, not
the defect #85 named. Noted here rather than folded into this closure, since fixing it would be
scope creep onto an issue that was never about those specific ADRs.

The one item genuinely still open — no author email in either `composer.json` — was already
correctly identified in `ISSUES.md` as **the maintainer's decision, not a defect**: a personal
address in a public Composer package is a publication choice. Asked directly rather than assumed;
the answer was to leave it out and close the issue on that basis.

## Where this leaves the project

`ISSUES.md`'s #85 row flips to closed, with the reasoning that closes it rather than a bare
checkbox. No code changed — this session's only output is the record that the sweep was already
complete, verified rather than trusted.
