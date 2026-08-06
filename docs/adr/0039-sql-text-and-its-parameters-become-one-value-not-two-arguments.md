# ADR-0039: SQL text and its parameters become one value, not two arguments

- **Status:** Accepted
- **Date:** 2026-08-06
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 10.1 (opens Milestone 10) · spec r3 FR-33 (RFC-0002) ·
  [RFC-0002](../rfc/0002-application-layer-groups-from-legacy-intake.md) §"Decision" (P-1) ·
  [ADR-0014](0014-pin-pdo-defaults-on-a-consumer-owned-connection.md) (real prepares, the
  mechanism this decision sits on top of) ·
  [ADR-0015](0015-identifier-allowlist-closed-keywords-and-the-anchor-hole.md) (the sibling
  decision for identifiers, which cannot be bound at all) ·
  [ADR-0017](0017-prove-binding-at-the-pdo-boundary-and-defer-t02s-like-leg.md) (the
  query-log proof this decision does not change) · roadmap items 10.2–10.4 (`Persistence`,
  which this decision exists for)

## Context

RFC-0002's survey found 199 sites in a legacy application's query-factory classes where a
value was string-interpolated into SQL text before the statement was ever prepared, against
zero sites where a value was bound as a parameter. `DatabaseConnection::execute(string $sql,
array $parameters = [])` — this library's own shape until this item — does not structurally
prevent the same mistake. Its type signature accepts `execute("... {$value} ...")` with an
empty `$parameters` exactly as happily as it accepts a correctly bound call; nothing in a
`(string, array)` pair says the two must have been assembled together, only that they
happen to have been passed to the same call. Values already travel to the driver as real
bound parameters here (ADR-0014's `ATTR_EMULATE_PREPARES=false`), so the wire-level safety
this decision changes is exactly zero — every existing caller was already safe. What is not
zero is the surface a future caller, or a reviewer checking one, has to get right: today
that surface is every call site in the codebase; roadmap items 10.2–10.4 are about to add
`Persistence\Repository` and `Persistence\TableGateway`, whose whole purpose is to accept
**hand-written SQL from callers less careful than this library's own tests** — the estate's
own pattern, generalized. A `(string, array)` signature offers those callers no more
protection than it offered the estate's authors.

## Decision

Introduce `D4np\Utils\Database\SqlStatement` — an immutable value pairing SQL text with the
parameters bound to it (`public readonly string $sql`, `public readonly array $parameters`)
— and make it the **only** shape `DatabaseConnection::select()`, `selectOne()` and
`execute()` accept. Each method drops its `(string $sql, array $parameters = [])` pair in
favor of a single `SqlStatement $statement` parameter. `QueryBuilder::get()`/`first()` build
one internally from `toSql()`/`bindings()`; every other caller, present (tests) and future
(`Persistence\Repository`, item 10.3), must construct or receive a `SqlStatement` to reach
the database at all.

This is a breaking change to `DatabaseConnection`'s public signature, made in a **pre-1.0
MINOR** (`v0.8.0 → v0.9.0`), which SemVer 2.0.0 §4 permits and this project's own BC policy
(ADR-0031, `tools/bc_gate.py`) treats as a bump-legal break rather than a violation.

## Alternatives Considered

- **Add a `SqlStatement`-accepting method alongside the existing `(string, array)` ones** —
  rejected: it does not close anything. A caller can still reach the old shape, which is
  exactly the shape that let the estate's mistake happen 199 times; "only" in the roadmap
  item's own wording means the old shape stops existing, not that a safer one is offered
  next to it.
- **Enforce statement-only execution starting at `Persistence\Repository` (item 10.3),
  leaving `DatabaseConnection` unchanged** — rejected: `DatabaseConnection` is public, and a
  consumer who reaches past `Repository` straight to the connection (which RFC-0001 always
  allowed — it is a thin PDO wrapper, not a sealed internal) would still have the
  two-argument door open. The choke point has to be the actual boundary to the driver, not
  a layer above it that can be routed around.
- **A runtime assertion that `$parameters` is non-empty whenever `$sql` contains a `?` or a
  named placeholder** — rejected: it cannot distinguish a legitimately parameter-free
  statement (`CREATE TABLE`, a `SELECT` with no `WHERE`) from a forgotten bind, so it would
  either reject valid calls or miss the case that matters (a placeholder-free string built
  by concatenating an already-interpolated value contains no `?` for the assertion to see).
  A type-level guarantee catches what a string never announces; a runtime heuristic cannot.
- **Name the value type `Query` or `Statement`** — rejected for `SqlStatement`: `QueryBuilder`
  already uses "query" for the fluent builder itself, and a bare `Statement` collides in
  spirit with `PDOStatement`, which this class is deliberately not — it holds no connection,
  no result cursor, and cannot be executed by itself.

## Consequences

- **API / compatibility:** `DatabaseConnection::select()`, `selectOne()` and `execute()`
  change signature; every in-repo call site is migrated in this same PR
  (`QueryBuilder`, `DatabaseConnectionTest`, `InjectionTest`, `QueryBuilderTest`,
  `TransactionTest`, `SanitizerTest`, and `Transaction`'s docblock example). No behavior
  changes for any of them — same PDO calls, same bound values, same exceptions.
- **Nothing at the wire level changes.** ADR-0014's real prepares already bound every value
  through both shapes; this decision moves where a reviewer has to look, not what the driver
  receives. Recorded explicitly so this ADR is not mistaken for closing a live
  vulnerability — none existed in this library's own code before it.
- **Testing:** `SqlStatementTest` is new and minimal — the class has no behavior beyond
  pairing two readonly values, so there is nothing more to assert. The existing T-02
  suites (`InjectionTest`, `QueryBuilderTest`'s identifier cases, `SanitizerTest`) are
  unchanged in what they prove, only in how they call it.
- **Deptrac:** no new layer edge. `SqlStatement` lives in `Database`, alongside
  `QueryBuilder` and `DatabaseConnection`; `Persistence` (item 10.2) will depend on it as
  part of the two edges that item's own ADR names.
- **Forward-looking:** `Persistence\Repository` and `TableGateway` (items 10.3–10.4) now
  have exactly one type to accept from a caller with hand-written SQL, and exactly one
  place — this class's constructor call — where text and parameters are still two separate
  variables that could be paired incorrectly.

## References

- RFC-0002, Context ("199 interpolation sites... against 0 bound parameters") and Decision
  §"Persistence" (FR-33's rationale, verbatim: *"the defect was that its values traveled
  interpolated"*)
- ADR-0014 (real prepares — the mechanism this decision's choke point sits on top of, and
  which already made every migrated call site safe at the wire before this ADR)
- ADR-0017 (the query-log proof at the PDO boundary — unaffected; it observes what reaches
  `PDOStatement::execute()`, which this change does not alter)
