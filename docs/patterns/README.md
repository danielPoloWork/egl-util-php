# Design Patterns Catalogue

Living index of every design pattern **adopted**, **planned**, **considered and rejected**,
or **under evaluation** for `egl-util-php`. Mandatory reading whenever a PR introduces
or removes a pattern, and updated in the same PR.

- **Rules** — [`AGENTS.md`](../../AGENTS.md) §8.
- **Canonical taxonomy** — [`design-patterns.md`](design-patterns.md). All pattern names
  used here, in ADRs, and in commit messages must match its spelling and categorisation.

## Architecture style

**Committed style:** Layered — from [`design-patterns.md`](design-patterns.md) §5.
**Pattern discipline:** `advisory` — `advisory` means the agent advises and the human
decides; `enforced` makes conformance to the committed style + adopted patterns a review expectation.


## How to use this catalogue

- **Adding a pattern** — when a PR lands one, add a row to *Implemented / Planned* as
  `Implemented`, with the ADR link and the code location (a real path under
  `src/main/php/...`); a pattern decided in an ADR but not yet in code is added as `Planned`.
- **Refining** — update the row and link the new ADR.
- **Rejecting** — add it to *Rejected* with the reason; do not silently drop it.
- **Removing** — move the row to *Superseded*, link the superseding ADR, keep the history.

Status vocabulary: `Planned` (decided in an ADR, not yet landed) · `Implemented` (present
in `src/main/...`, ADR `Accepted`) · `Considered` · `Rejected` · `Superseded`.

## Implemented / Planned

_Patterns named in the spec at intake are seeded below as **Planned**; each becomes
**Implemented** with its ADR and a real code location in the PR that introduces it._

| # | Pattern | Status | Problem it addresses | Code location | ADR / PR |
|---|---------|--------|----------------------|---------------|----------|
| 1 | Table Data Gateway | Implemented | Per-entity CRUD boilerplate: the surveyed estate wrote a `Dao` + `Query` + `CrudImpl` triple per table, each re-deriving how to build safe SQL and each disagreeing on what a failure returns | [`Persistence/TableGateway.php`](../../src/main/php/d4np/utils/Persistence/TableGateway.php) | [ADR-0044](../adr/0044-the-write-builder-querybuilder-never-had-and-one-allowlist-for-both.md) · item 10.4 |
| 2 | Front Controller | Implemented | Routing by filesystem: the surveyed estate deployed **37 folders**, each with an `index.php` differing from its neighbours in one line, so the autoloader, the response envelope and the error boundary existed 37 times and drifted apart. One entry point and one route table replace them — the kernel is written out in [`endpoint-kernel.md`](endpoint-kernel.md) | [`Http/Router.php`](../../src/main/php/d4np/utils/Http/Router.php) | [ADR-0050](../adr/0050-classify-the-miss-and-keep-the-router-a-table.md) · item 11.2 |


## Rejected

_Recorded rather than silently dropped (`AGENTS.md` §8.3). Each was a live candidate for the
`Persistence` group at items 10.3–10.4._

| # | Pattern | Considered for | Rejected because | ADR / PR |
|---|---------|----------------|------------------|----------|
| 1 | Active Record | The row shape returned by the gateway | It puts persistence on the DTO, and the `Dto` group's whole contract is that a DTO is immutable data with no collaborators. It would also invert the layering rule — `Dto` would need `Database` — which is the direction [ADR-0043](../adr/0043-two-named-edges-out-of-persistence-and-no-catch-at-all.md) keeps closed and proves closed. | [ADR-0044](../adr/0044-the-write-builder-querybuilder-never-had-and-one-allowlist-for-both.md) · item 10.4 |
| 2 | Unit of Work | Coordinating multi-statement writes | Change tracking and an identity map are ORM scope, rejected wholesale by RFC-0002 Alternative #1. Transaction scope — the part consumers actually need — is already `Transaction`/`Repository::withTransaction()` under [ADR-0016](../adr/0016-closure-scoped-transactions-with-savepoint-nesting.md)'s semantics. | [ADR-0044](../adr/0044-the-write-builder-querybuilder-never-had-and-one-allowlist-for-both.md) · item 10.4 |
| 3 | Intercepting Filter / middleware pipeline | Cross-cutting work around a routed handler | The pipeline shape everyone reaches for is PSR-15, which is defined in PSR-7 messages — and the bridge is this library's only sanctioned crossing into PSR-7 (RFC-0001 Alternative #3). Adopting it would put a PSR-7 dependency behind `Router`, which the whole HTTP stance exists to avoid. The endpoint kernel does the same work in straight-line code a reader can follow. | [ADR-0050](../adr/0050-classify-the-miss-and-keep-the-router-a-table.md) · item 11.2 |
| 3 | Data Mapper | Mapping rows to DTOs | The full pattern's value is mapping *independent* object and table models, which needs metadata, a registry and change tracking. This library maps a flat row to a readonly DTO by constructor name, which the shared hydrator already does ([ADR-0008](../adr/0008-dto-hydration-strictness-and-shared-hydrator.md)); adopting the name would promise a machinery that is deliberately absent. | [ADR-0044](../adr/0044-the-write-builder-querybuilder-never-had-and-one-allowlist-for-both.md) · item 10.4 |

## Superseded

_No superseded patterns yet._

| # | Pattern | Superseded by | When | ADR / PR |
|---|---------|---------------|------|----------|
| — | —       | —             | —    | —        |

## Candidate patterns to consider

The taxonomy in [`design-patterns.md`](design-patterns.md) lists every pattern in scope. As
the architecture takes shape, narrow that universe to the patterns plausibly applicable to
*this* artifact and list them here by category, each with a one-line "possible application".
A candidate remains a candidate until adopted (own ADR) or explicitly rejected.

## Out-of-scope categories

Record here any taxonomy category pre-classified as not applicable to this artifact (with a
one-line reason), so the policy of explicit rejection is honoured without filling the
*Rejected* table with N/A noise.
