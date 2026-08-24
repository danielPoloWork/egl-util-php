# ADR-0044: The write builder `QueryBuilder` never had, and one allowlist for both

- **Status:** Accepted — **the "SQLite-only" blind spot is now measured rather than reasoned**
  (2026-08-24, issue #110, [ADR-0071](0071-one-dsn-points-the-behavioural-suites-at-an-engine-and-an-unreachable-one-is-red.md)).
  This ADR accepts that a `TableGateway` cannot tell, without a schema round trip, that its DTO
  declares a column the table lacks, on the argument that the mistake is loud anyway: at the driver
  on MySQL and PostgreSQL, and one layer up in strict hydration on SQLite, whose double-quoted-string
  misfeature turns the unknown name into a string literal. **That argument had never been executed
  against MySQL or PostgreSQL** — this repository ran on SQLite exclusively. It now runs on all
  three, and `TableGatewayTest` asserts both arms by engine, so the cost argument for not doing the
  schema round trip is confirmed to apply to exactly one engine. **Nothing decided here changes.**
  Annotated rather than edited, per ADR-0041's precedent.
- **Date:** 2026-08-06
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 10.4 · spec r3 **FR-35** (RFC-0002, corrected here — see Decision) ·
  [RFC-0002](../rfc/0002-application-layer-groups-from-legacy-intake.md) ·
  [ADR-0015](0015-identifier-allowlist-closed-keywords-and-the-anchor-hole.md) (the allowlist
  this extracts, anchor hole included) ·
  [ADR-0041](0041-constrain-sql-text-by-type-and-name-the-one-escape-hatch.md) (the doors into
  `SqlStatement`, which this adds one to) ·
  [ADR-0043](0043-two-named-edges-out-of-persistence-and-no-catch-at-all.md) (the `Repository`
  this extends, and the two edges that make it legal) ·
  [ADR-0008](0008-dto-hydration-strictness-and-shared-hydrator.md) (strict hydration, which the
  projection decision follows from) · [ADR-0006](0006-shared-reflection-metadata-cache.md) (the
  cache the projection reads) ·
  [ADR-0022](0022-argon2id-by-default-with-a-fallback-decided-at-construction.md) (fail-fast at
  wiring time) · [ADR-0025](0025-typed-http-wrappers-that-refuse-rather-than-coerce.md) (the
  array-key annotation lesson) · patterns catalogue: **Table Data Gateway**, adopted

## Context

Item 10.4 is *"`TableGateway`: Table Data Gateway over `QueryBuilder` — select/insert/update/delete
with allowlisted identifiers and bound values by construction"*. RFC-0002 words FR-35 the same
way: operations *"compose exclusively through `QueryBuilder`"*.

**That sentence is not implementable, and finding out took one grep.** `QueryBuilder` builds
`SELECT` and only `SELECT`: there is no `INSERT`, no `UPDATE`, no `DELETE` anywhere in the
`Database` group, and there never was. The read half of the gateway composes through it exactly
as specified; the write half had nowhere to go.

So the item's real question was not *how to write a gateway* but **where write-side SQL
composition lives**, and the answer had to preserve two properties this repository has already
paid for:

1. **One allowlist.** Identifiers cannot be bound — no placeholder is legal where a column name
   goes — so for them the allowlist *is* the defence (ADR-0015). A second copy of that pattern is
   one edit away from two rules that disagree, and the weaker one decides.
2. **A countable set of doors into `SqlStatement`.** ADR-0041 constrained SQL text by type:
   `literal()` is checked by PHPStan, `fromQueryBuilder()` by that class's allowlist, and
   `composed()` — the escape hatch — by a human at review. Its value rests on `composed()` having
   **zero in-library uses**, which is what makes `grep composed(` a list of exactly the places
   someone had to think.

## Decision

**Write composition lives in `Database`, beside the read builder, and the allowlist becomes one
shared class.**

- **`Database\Identifier`** — the allowlist (`\z`-anchored, ADR-0015's corrected form) plus the
  per-driver quoting, extracted from `QueryBuilder`'s two private methods. Both builders resolve
  one instance per statement or per builder, so the driver lookup stays paid once (ADR-0020).
- **`Database\MutationBuilder`** — `insert()`, `update()`, `delete()` as named constructors, each
  returning a fully-formed builder, so no instance can exist half-built. Values bind; column names
  go through `Identifier`; `null` in criteria renders `IS NULL`, never `= ?`.
- **`SqlStatement::fromMutation()`** — a fourth door, taking the *builder object* rather than its
  text, exactly as `fromQueryBuilder()` does. `composed()` keeps its zero in-library uses.
- **`Persistence\TableGateway`** — the gateway itself, extending `Repository` (ADR-0043) so
  hydration, normalization, transactions and the no-catch property are inherited rather than
  re-implemented. No new deptrac edge: `QueryBuilder` and `MutationBuilder` are both in `Database`,
  already granted.

**Spec FR-35 is corrected in the same PR (r11)** rather than left to describe something that does
not exist — the §7 discipline. The correction is one clause: *composed through `QueryBuilder`* →
*through `QueryBuilder` for reads and `MutationBuilder` for writes, sharing one `Identifier`*.

### Three decisions inside the gateway worth naming

**Empty criteria are refused on every filtered operation** — `findBy`, `findOneBy`, `updateBy`,
`deleteBy` — and `all()` is the named whole-table read. An empty array is what
`$request['filters'] ?? []` collapses to, and the outcomes run from mass disclosure to an emptied
table. This is `QueryBuilder::whereIn([])`'s reasoning with the damage turned up: a filter that
silently stops filtering is worse than an error. The deliberate whole-table write stays available
as one conspicuous `SqlStatement::literal()`.

**The gateway projects the DTO, not the table.** Reads select the DTO's declared columns, never
`SELECT *`, because hydration is strict (ADR-0008): a `SELECT *` breaks the day someone adds a
column, which is the shape ADR-0043 already declined to work around. The projection comes from
`ReflectionCache` (ADR-0006), cached again on the instance.

**The table name is allowlisted at construction**, not only inside each statement. The builders
still check it — that is what makes the SQL safe — but a gateway wired to an impossible table
should fail where it was wired (ADR-0022's fail-fast line), not on whichever read runs first in
production.

## Alternatives Considered

1. **Compose the write SQL inside `TableGateway`** (in `Persistence`, using a shared
   `Identifier`) — rejected on two counts. It puts a second SQL generator in the group whose job
   is to *call* one, and its assembled text could only enter `SqlStatement` through
   `composed()` — spending ADR-0041's review-list property on machine-generated SQL that has a
   checker. The door exists for text a human vouched for.
2. **Copy the allowlist pattern into the write builder** — rejected. The repository already
   carries one deliberately duplicated security constant (`LIKE_ESCAPE`, kept in step by a test
   asserting the two agree) and that is affordable because its drift produces a *wrong query*. An
   allowlist that drifts produces a *vulnerability*; sharing costs a class.
3. **Add write verbs to `QueryBuilder`** — rejected. It is a fluent immutable `SELECT` value whose
   `toSql()` renders one shape; verbs that render three others would make "what does this builder
   represent" a runtime question, and the class documents itself as the read side of the model.
4. **A shared `SqlBuildable` interface with one `SqlStatement::fromBuilder()`** — rejected, and
   this is the one that looks cleanest until you count the doors. An interface is an *open* door:
   any consumer class could implement it and hand arbitrary text to `SqlStatement`, which is the
   unconstrained fourth constructor ADR-0041 made private to prevent. Two named methods taking two
   `final` classes keep the set closed and countable.
5. **Validate the projection against the live schema** (so a DTO/table mismatch fails at
   construction) — rejected: a schema round trip per gateway, in driver-specific SQL, to catch a
   mistake that surfaces on the first row read. See the honest limit below.
6. **`SELECT *` plus lenient hydration** — rejected: it would make the gateway the one place in
   the library where mass assignment is not the caller's explicit choice, undoing ADR-0008 by
   default rather than by decision.

## Consequences

**Easier:** the estate's per-entity `Dao` + `Query` + `CrudImpl` triples collapse into a gateway
plus, where a table needs one, a subclass with the queries it actually owns. Every CRUD path is
injection-proof by construction rather than by review — including the array *keys*, which is where
a request array most plausibly reaches a data-access class.

**Harder / accepted:**

- One more class in `Database` and one more door on `SqlStatement`. The door count is now four,
  and the table in `SqlStatement`'s docblock is the list.
- `MutationBuilder` is deliberately narrow: equality and `IS NULL` criteria, no `JOIN`, no
  `RETURNING`, no upsert, no bulk insert. Anything beyond it is `SqlStatement::literal()`, which
  is what FR-33 exists for.
- Extracting `Identifier` made `QueryBuilder::toSql()` **pure** (the table is quoted in the
  constructor now), which PHPStan noticed: two existing tests ended a fluent chain with a bare
  `->toSql();` and became `method.resultUnused` errors. They now keep the result and assert it —
  a small strengthening, and a reminder that a refactor can change what static analysis can prove
  about untouched code.

**The honest limit, found by a test that failed for the wrong reason.** A DTO declaring a column
the table lacks was expected to fail at the driver. On MySQL, PostgreSQL and SQL Server it does.
**On SQLite it does not:** its double-quoted-string misfeature accepts an unresolvable quoted name
as a *string literal*, so the statement succeeds and returns `'"nickname"' => 'nickname'` — quotes
included in the key. What refuses it there is strict hydration, on the first row. Which leaves one
real blind spot: **on SQLite, against an empty table, the mismatch is invisible.** Both behaviours
are asserted rather than described, and alternative 5 is the fix that was declined.

**Proof of the suite, not just of the code.** Ten defects were planted one at a time and each
turned its own suite red: the empty-criteria refusals removed (write side and read side), values
interpolated instead of bound, a `null` criterion bound as `= ?` on each side, the anchor weakened
from `\z` to `$`, the allowlist bypassed entirely, a second copy of the pattern planted in
`MutationBuilder` (caught by the one-allowlist mechanism test), `SELECT *` in place of the
projection, and the constructor's fail-fast removed. The first run of that campaign was itself
wrong — the new files were untracked, so `git checkout` restored nothing and the defects
accumulated; it was redone against a staged index. A campaign whose restore step silently fails
proves nothing, and it looked identical to one that worked.

## References

- Spec r3 → r11 FR-35 (corrected), FR-33; RFC-0002 Context (199 interpolation sites, 0 bound
  parameters) and Alternative #1 (why this is a gateway and not an ORM)
- ADR-0015 (allowlist + anchor), ADR-0041 (the doors), ADR-0043 (`Repository`, the two edges),
  ADR-0020 (resolve the driver once), ADR-0006, ADR-0008, ADR-0022, ADR-0025
- Fowler, *Patterns of Enterprise Application Architecture* — Table Data Gateway (the catalogue
  entry's source; the taxonomy gained the row in this PR)
