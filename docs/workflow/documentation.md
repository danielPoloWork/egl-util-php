# Documentation Workflow

How documentation is maintained on `egl-util-php`. Documentation is part of the
deliverable — every PR ships its own doc updates in the same PR. The rules are in
[`AGENTS.md`](../../AGENTS.md) §7; this expands the *how*.

## Artifacts and when to touch them

| Artifact | Update it when… |
|---|---|
| `README.md` | the public surface, build/test/run flow, or milestone status changes |
| `docs/specs/` | behavior diverges from the frozen spec (update spec **or** add a superseding ADR) |
| `docs/adr/` | a non-trivial design decision is made, or a pattern is adopted/superseded |
| `docs/patterns/README.md` | a pattern is introduced, refined, rejected, or superseded |
| `ROADMAP.md` | an item completes (flip the checkbox) or new work is planned |
| `CHANGELOG.md` | a user-visible change lands (add a line to `[Unreleased]`) |
| `docs/journal/` | a work session changed the project's state (dated checkpoint) |
| `docs/bugs/` | a defect is verified, triaged, or fixed |

## Same-PR discipline

A change to code and its documentation belong to the **same** pull request. "Docs
follow-up" is not allowed (`AGENTS.md` §10). The consistency lint
(`python tools/consistency_lint.py`) mechanically enforces the parts of this that can be
checked: version lockstep, ADR index ↔ files, pattern rows ↔ ADR+code, spec coverage map,
README ↔ ROADMAP milestone agreement, and bug-ledger integrity.

## API documentation

Public symbols are documented with `phpDocumentor`-compatible comments. The API-docs build must
report no compilation errors (quality bar, `AGENTS.md` §10), and **that is asserted from
phpDocumentor's own report rather than its exit code**, which is `0` even when the report names
errors — see [ADR-0070](../adr/0070-read-phpdocumentors-report-not-its-exit-code.md).
`.github/workflows/api-docs.yml` builds the reference on every pull request and publishes it to
GitHub Pages from `master`; `phpdoc.dist.xml` is the configuration and covers `src/main` only.

**One practical constraint this imposes on annotations.** PHPStan's generic `self<T>` form is
rejected by phpDocumentor's type parser (*"self is not a collection"*), so a generic return names
its class instead — `Result<U>`, `Collection<TItem>`. PHPStan accepts both identically, and the
gate catches a reintroduction on the pull request that makes it. Nothing is suppressed to keep the
build clean: `phpdoc.dist.xml` carries no `ignore-tags` block, deliberately.

Narrative documentation lives in Markdown under `docs/`; the split between generated API docs and
hand-written narrative is recorded in an ADR if non-obvious.
