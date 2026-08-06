# ADR-0043: Two named edges out of Persistence, and no catch at all

- **Status:** Accepted
- **Date:** 2026-08-06
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 10.3 · spec r3 **FR-34** (RFC-0002) ·
  [RFC-0002](../rfc/0002-application-layer-groups-from-legacy-intake.md) **P-1** (which
  anticipated these two edges and named the alternatives) ·
  [ADR-0012](0012-enforce-the-layering-rule-by-directory-over-src-main.md) (the layering gate
  this extends) ·
  [ADR-0021](0021-delegate-rich-html-and-escape-like-wildcards-with-a-portable-character.md)
  (the precedent for a *named* grant rather than a general relaxation) ·
  [ADR-0016](0016-closure-scoped-transactions-with-savepoint-nesting.md) (the transaction
  semantics this delegates to) · [ADR-0008](0008-dto-hydration-strictness-and-shared-hydrator.md)
  (strict hydration, kept) · [ADR-0042](0042-trim-is-the-only-default-and-the-transcode-runs-first.md)
  (whose deferred question about normalizer defaults this settles)

## Context

RFC-0001's dependency rule is *"groups depend downward on Support only; no cross-group
imports"*, enforced by deptrac since ADR-0012. `Repository` cannot obey it. Its whole purpose is
to sit between two sibling groups: it takes a `SqlStatement` and a `DatabaseConnection` from
`Database`, and returns a hydrated `DataTransferObject` from `Dto`. A version of it that could
touch neither would not be a repository.

RFC-0002 anticipated this as placement note **P-1** and named the two ways to avoid the edges,
both of which it rejected for the same reason:

- a gateway that returns **raw arrays**, leaving hydration to the caller;
- a gateway taking a **caller-injected hydration closure**.

Each keeps the layering rule pristine by pushing the normalize-then-hydrate loop back into every
call site — which is precisely the duplication this milestone exists to remove. The estate wrote
that loop seventeen times.

Separately, FR-34 states a requirement that is unusual because it asks for the *absence* of code:
*"Every failure throws `DatabaseException`; there is no silent `[]`/`false`/`-1` path — the
intake's 74 swallowed catches are the anti-requirement."* The surveyed estate's data-access
classes contained 74 `catch (Throwable)` blocks that returned exactly those sentinels, with the
reason accumulated into a local variable nothing ever read.

## Decision

**Grant exactly two edges — `Persistence → Database` and `Persistence → Dto` — and nothing
else.** This is the first and only exception to RFC-0001's rule in `deptrac.yaml`, and it follows
ADR-0021's shape: a *named* grant in the ruleset, not a relaxation of the rule. `Errors`, `Http`,
`Container` and `Security` stay closed to `Persistence`, and `Database` and `Dto` do **not** gain
`Persistence` in return, so the direction is one-way.

**Write no `try`/`catch` in `Repository`.** FR-34 is satisfied by omission: `DatabaseConnection`
already raises `DatabaseException` (ADR-0014), the hydrator raises `HydrationException` naming
the path (ADR-0008), and `RowNormalizer` raises `DatabaseException` naming the column (ADR-0042).
Every one propagates untouched. Because an absence is what a test suite loses without anyone
noticing — a `catch` added back would keep every happy-path test green — the absence is asserted
directly, by reading the class's own source with the docblocks stripped.

**Hydration stays strict**, so a projected column the DTO does not declare raises. That makes
`SELECT *` into a typed DTO fail by design; `hydrate()` is `protected` and non-final as the
documented seam for a subclass that wants lenient hydration, rather than this class carrying a
flag for it.

**Normalization is opt-in** — the question ADR-0042 explicitly deferred. `Repository` takes
`?RowNormalizer $normalizer = null`, and without one, rows are hydrated exactly as the driver
returned them. ADR-0042's defaults answer *"if you asked for a normalizer, what does it do?"*;
this answers *"did you ask for one at all?"*, and they are different questions. Passing one is
visible at the wiring site, which is where a decision to rewrite a consumer's values belongs.

## Alternatives Considered

- **A gateway returning raw arrays** (RFC-0002 P-1) — rejected there and here: it keeps the rule
  intact and hands every caller the loop back, which is the seventeen-copies problem restated.
- **A caller-injected hydration closure** (RFC-0002 P-1) — rejected as the default for the same
  reason; note that `hydrate()` being an overridable seam gives a subclass the same flexibility
  without making every consumer supply one.
- **Relaxing the layering rule generally** ("groups may depend on groups below them in a declared
  order") — rejected as the expensive kind of convenience. It would replace a rule deptrac can
  check with an ordering nobody maintains, and every future group would inherit permissions
  nobody argued for. Two named edges are auditable; a general ordering is a policy.
- **Putting `Repository` in `Database`**, so no new edge is needed — rejected: it would give
  `Database` a dependency on `Dto`, moving the same violation one group down while also making
  the `Database` group about mapping. RFC-0002 put persistence in its own group precisely so this
  coupling has a name and a boundary.
- **Catching and rethrowing to add context** — rejected as the beginning of the estate's slope.
  Each failure already names what a caller needs (the column, the property path, the driver
  message), and a wrapping layer that added "in Repository::fetchAll" would trade a real cause
  for a location the stack trace already has.
- **Lenient hydration by default**, so `SELECT *` works — rejected: it would undo ADR-0008's
  mass-assignment guarantee at exactly the layer where rows arrive from outside the program.

## Consequences

- **The rule now has an exception, and it is a documented one.** Anyone reading
  `deptrac.yaml` finds the two grants with the reason inline and this ADR named. That is the cost
  of the group existing at all, and it is bounded by being enumerated.
- **Proved, in all three directions** (ADR-0012's own discipline, and item 8.1's lesson that
  deptrac resolves *type* dependencies rather than `use` statements, so an unused import proves
  nothing):

  | planted | result |
  |---|---|
  | the two granted edges, in real code | **0 violations**, 192 allowed |
  | `Persistence → Errors` (not granted) | `Repository must not depend on Result` |
  | `Dto → Persistence` (the inversion) | `Hydrator must not depend on RowNormalizer` |

  The middle row is what distinguishes a grant from an opening: the list discriminates.
- **The no-catch property is asserted, and the assertion was proved non-vacuous** by planting
  `catch (DatabaseException) { return -1; }` in `execute()` — the estate's exact sentinel — and
  confirming the test fails.
- `execute()` returns the **affected-row count**, not a boolean. `0` is a fact the caller may
  legitimately expect or treat as an error, and only the caller knows which; the estate's
  boolean is why its callers could not tell "updated nothing" from "did not run".
- **Not settled here:** `TableGateway` (item 10.4) will build its own statements through
  `QueryBuilder` rather than accepting them, which needs no further edge — `QueryBuilder` is in
  `Database`, already granted.

## References

- Spec r3 FR-34; RFC-0002 P-1 and Context (the 74 swallowed catches, the seventeen copies)
- ADR-0012 (the gate), ADR-0021 (named grant precedent), ADR-0016, ADR-0008, ADR-0042
- Item 8.1's finding that deptrac resolves type dependencies, without which these proofs would
  have been vacuous
