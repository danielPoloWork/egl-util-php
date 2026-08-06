# 2026-08-06 — What one value type actually closes

Roadmap item **10.1**, opening Milestone 10. Route `frontier-reasoning / extra` — matched,
no mismatch. The item's own wording carries a security tag and a mandated ADR, so the first
question worth answering honestly is what, exactly, changing a method signature secures.

## The number that motivated it does not describe this codebase

RFC-0002's Context cites the survey finding this milestone exists to answer: 199
SQL-interpolation sites in a legacy query-factory, against 0 sites where a value was bound.
`DatabaseConnection::execute(string $sql, array $parameters = [])` — this library's own
shape until today — was never that vulnerable. Every value already travels to the driver as
a real bound parameter (ADR-0014's `ATTR_EMULATE_PREPARES=false`), so nothing about the
`(string, array)` signature let a value slip through unbound. Checking that before writing
anything mattered: an ADR that opened by claiming to fix an injection hole that does not
exist would be the wrong kind of overstatement — the "count first, then propose" lesson
from RFC-0002's own drafting, applied to code this time instead of a proposal.

What the old signature *does* fail to do is stop the mistake **structurally**. Nothing in
`execute(string $sql, array $parameters = [])` distinguishes "I forgot to bind" from "there
is nothing to bind" — both produce the exact same call shape, an empty or short
`$parameters` array. Every one of the estate's 199 sites would have type-checked against
this signature too. That is the real target: not this library's existing call sites (all
already safe), but `Persistence\Repository` and `TableGateway`, two items away (10.3–10.4),
whose entire purpose is to accept hand-written SQL from callers who will not all be as
careful as this suite's tests.

## The decision, and its honest cost

`SqlStatement` — one readonly value, `sql` plus `parameters` — becomes the **only** thing
`DatabaseConnection`'s three query methods accept. Not an additional convenience alongside
the old shape: the roadmap item's own wording, "accepts only statements," was read
literally, because a second door next to a bad one does not close it. That reading forced a
larger diff than the value type alone would suggest — every call site in
`QueryBuilder`, `DatabaseConnectionTest`, `InjectionTest`, `QueryBuilderTest`,
`TransactionTest`, `SanitizerTest`, and one docblock example in `Transaction`, all migrated
in this PR. Pre-1.0 permits the break (SemVer §4, `tools/bc_gate.py`); permits is not the
same as free, and the migration is the size this item actually is under "size: S."

## What the ADR says plainly, because it would be easy to imply otherwise

ADR-0039 states, in its own Consequences section, that nothing changes at the wire level:
every migrated call was already binding correctly. The value this decision buys is where a
future reviewer has to look — one type's constructor, instead of every call site that could
have assembled two separate arguments wrong. That is a real, if narrower, security property
(a choke point is easier to audit than a convention), and naming it precisely is worth more
than letting the item's own "security — protected floor" tag imply a vulnerability that
was never there.

## Lesson

A security ADR is allowed to say "this closes a mistake nothing here has made yet" — that
is what "protected floor" means for the next three items, not a confession about this one.
