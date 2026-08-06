# 2026-08-06 — A requirement satisfied by not writing code, and the rule's first exception

Roadmap item **10.3**, the heart of Milestone 10. Route `frontier-reasoning / extra` — matched.
Two things here are unusual enough to be worth the entry: a requirement met by omission, and the
first exception to a rule this repository has enforced mechanically since item 3.6.

## The exception, and why it is not a wedge

RFC-0001's rule is *"groups depend downward on Support only; no cross-group imports"*, and
`Repository` cannot obey it. Its purpose is to sit between two siblings — a `SqlStatement` and a
`DatabaseConnection` in from `Database`, a hydrated `DataTransferObject` out from `Dto`. There is
no version of it that touches neither.

RFC-0002 saw this coming (placement note P-1) and named the two escapes: return raw arrays, or
take a caller-supplied hydration closure. Both keep the rule pristine by handing the
normalize-then-hydrate loop back to every caller — which is the seventeen-copies problem this
milestone exists to end, restated as a design principle.

So the edges are granted. What keeps that from being the thin end of a wedge is that they are
**named**, on ADR-0021's precedent (the `HtmlSanitizer` layer only `Security` may reach), and
that the grant was **proved to discriminate** rather than merely to permit:

| planted | result |
|---|---|
| the two granted edges, in real code | 0 violations, 192 allowed |
| `Persistence → Errors` — not granted | `Repository must not depend on Result` |
| `Dto → Persistence` — the inversion | `Hydrator must not depend on RowNormalizer` |

The middle row is the one that matters. Without it, "we granted two edges" and "we opened the
group" look identical from the outside. And all three needed item 8.1's lesson to be worth
anything: deptrac resolves **type dependencies**, not `use` statements, so a planted import
proves nothing and a planted return type proves everything.

## The requirement that asks for less code

FR-34: *"every failure throws `DatabaseException`; there is no silent `[]`/`false`/`-1` path — the
intake's 74 swallowed catches are the anti-requirement."*

I went looking for what to implement and found there was nothing to write. `DatabaseConnection`
raises on a failed statement (ADR-0014). The hydrator raises with the property path (ADR-0008).
`RowNormalizer` raises naming the column (ADR-0042). Satisfying FR-34 meant **writing no
`try`/`catch`** — the requirement is an absence.

Which is a problem, because an absence is exactly what a test suite loses without anyone
noticing. Add a `catch` back tomorrow and every happy-path test here stays green; the three
"it propagates" tests would too, if the catch rethrew. So the absence is asserted directly —
the class's own source, docblocks stripped, must contain no `catch (`.

Then I planted the estate's exact sentinel to check the assertion was worth having:

```php
try { return $this->connection->execute($statement); }
catch (DatabaseException) { return -1; }
```

Red. Good. That is the shape ADR-0027 established for `hash_equals` and item 10.7 reused for
`literal-string`: when the property is not behavioural, assert the mechanism.

## Two smaller decisions, both inherited questions

**Strict hydration stays**, which means `SELECT *` into a typed DTO *fails*. That is not a
limitation I worked around — a projection that outgrows its DTO is the shape that breaks the day
someone adds a column, and `QueryBuilder::select()` exists for the alternative. `hydrate()` is
protected and non-final so a subclass wanting lenient has a seam, rather than this class carrying
a boolean.

**Normalization is opt-in**, which is the question ADR-0042 explicitly left open. Worth being
precise about why it is a separate question: ADR-0042 answers *"if you asked for a normalizer,
what does it do?"* — and answered it with trim-only defaults. This answers *"did you ask for
one?"*. Defaulting to yes would make `Repository` quietly rewrite values with nothing at its own
call site saying so.

## Lesson

Some requirements are satisfied by deleting nothing and adding nothing — and those are the ones
that need a mechanism assertion, because there is no code whose removal a normal test would
notice.
