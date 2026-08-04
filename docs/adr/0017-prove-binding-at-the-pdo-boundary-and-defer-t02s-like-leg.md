# ADR-0017: Prove binding at the PDO boundary, and ship T-02 with its LIKE leg openly deferred

- **Status:** Accepted
- **Date:** 2026-08-04
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 4.4 · spec §7 T-02 and T-04, FR-10 · item **5.2**
  (`Sanitizer::sqlLikePattern()` — the blocker named below) ·
  [ADR-0014](0014-pin-pdo-defaults-on-a-consumer-owned-connection.md) ·
  [ADR-0015](0015-identifier-allowlist-closed-keywords-and-the-anchor-hole.md) ·
  [ADR-0016](0016-closure-scoped-transactions-with-savepoint-nesting.md)

## Context

Spec §7 defines T-02 as three things:

> fuzzed value payloads reach the driver only as bound parameters **via query-log assertion**;
> identifier injection throws `DatabaseException`; **LIKE-wildcard escapes**

Items 4.1–4.3 already asserted something in the neighbourhood of the first clause: a hostile value
round-trips intact and the table survives. **That is weaker than it looks.** It is *consistent
with* binding, but it is equally consistent with correct client-side escaping — an escaped value
also round-trips intact and also leaves the schema alone. The test passes either way, so it cannot
distinguish the mechanism the security model actually depends on from a mechanism that merely
resembles it.

That gap was measured rather than argued. With a *correctly quote-escaping* interpolation planted
in `DatabaseConnection::run()`:

| assertion | outcome under the planted vulnerability |
|---|---|
| round-trip + schema survives (items 4.1–4.3) | **28 of 29 passed** — the vulnerability is missed |
| query-log assertion (this item) | **28 of 29 failed** — the vulnerability is caught |

The spec's insistence on a *query-log* assertion is therefore not ceremony. It is the difference
between testing an outcome and testing the mechanism.

## Decision

### 1. The PDO boundary is the proof point, and the pinned real-prepares is what makes it sufficient

A `QueryLog` is installed through `PDO::ATTR_STATEMENT_CLASS`, so every statement the library
prepares — via `DatabaseConnection`, `QueryBuilder` or `Transaction` — records the exact SQL text
and the exact bound-parameter array, while still executing for real against the driver. For each
payload the suite asserts the payload appears **in the parameter array and never in the statement
text**.

The obvious objection is that this observes PDO's *input*, not the bytes on the wire. Two things
answer it, and the second is the load-bearing one:

- PDO exposes no way to see what it sends, and no database server is available in CI to read a
  real `general_log` from. This is the last point inside the process where SQL text and values are
  still separable.
- **ADR-0014 pins `ATTR_EMULATE_PREPARES=false`.** With real prepares, PDO performs no
  interpolation: the statement and the parameters travel to the server separately, by construction.
  So "the statement text at this boundary contains only placeholders" *is* "the statement text on
  the wire contains only placeholders". If emulation were ever silently re-enabled,
  `DatabaseConnectionTest`'s own assertions fail first — which is what makes this chain of
  reasoning checkable rather than merely plausible.

Every value-accepting path is covered, because a binding guarantee is worth exactly what its
leakiest entry point is worth: `select()`, `selectOne()`, `execute()`, `QueryBuilder::where()`,
`QueryBuilder::whereIn()`, and the same inside a `Transaction`.

### 2. Round-trip assertions are kept *alongside* the query-log ones, not replaced

Binding is not only a syntactic property. The value must also survive intact and the schema must
be untouched, and the query log alone would not notice a value that was bound but mangled. The two
assertions fail for different reasons, which is the point of having both.

### 3. T-02's LIKE-wildcard leg is **not** delivered, and says so in a test

`Sanitizer::sqlLikePattern()` is spec FR-10 and **roadmap item 5.2** — a Milestone 5 deliverable
that does not exist yet. Implementing it inside item 4.4 would jump a milestone and duplicate an
item the roadmap already owns.

The alternative to silence is a test that states the true state of affairs, which is what ships:
`testLikePatternsStillBindButWildcardsAreNotYetEscaped()` asserts what *is* true today — a `LIKE`
value binds like any other and cannot inject SQL — and then asserts the gap explicitly: a
user-supplied `%` still behaves as a wildcard and matches everything. Its message names item 5.2 as
the owner and says which assertion should change when it lands.

This is deliberate: an untested gap and a gap with a test that documents it look identical in a
coverage report and completely different to whoever reads the suite next. **T-02 is not complete at
the end of item 4.4**, and the roadmap entry says so rather than ticking a box the spec has not
earned.

### 4. T-04 needed no new tests, and none were invented

Spec §7's T-04 is *"exception → rollback → rethrow; savepoint nesting"*. Item 4.3 delivered exactly
that, `#[Group('T-04')]`, 17 tests. Adding redundant tests to make item 4.4 look busier would add
maintenance cost and no coverage. Verified as complete against the spec text and left alone.

## Alternatives Considered

- **Assert on a real server's query log** (MySQL `general_log`, PostgreSQL `log_statement`) —
  the strongest possible evidence, and rejected as out of scope here: it needs a service container
  in CI, which is a build-infrastructure decision affecting every job, not a test-suite one. It
  would strengthen this and the MySQL gaps ADR-0014 named; worth its own item if wanted.
- **`PDOStatement::debugDumpParams()`** — rejected: it writes to stdout in a format documented as
  unstable, and it reports what PDO *recorded*, not what a statement was asked to execute.
- **Keeping only the round-trip assertions** — rejected on the measurement above: they miss a
  correctly-escaping interpolation in 28 of 29 cases.
- **Implementing `sqlLikePattern()` here to complete T-02** — rejected: it is item 5.2's
  deliverable, and pulling a Milestone 5 security helper forward into a test item would mean
  designing it under the wrong item's review.
- **Marking the LIKE case `skipped` or `incomplete`** — rejected in favour of a passing test that
  asserts the *current* behaviour. A skipped test tells a reader nothing about what is true today;
  this one pins present behaviour so that item 5.2 changing it is a visible, deliberate edit.

## Consequences

- 205 new tests (29 payloads × 6 value paths, plus the standalone cases). `--group T-02` now runs
  **321** tests and `--group T-04` 17, so spec §7's named suites are runnable units rather than
  docblock claims.
- **The suite is verified non-vacuous against the real vulnerability**, not a synthetic one:
  planting a correctly-quote-escaping interpolation fails 142 tests and errors 6 more.
- The corpus targets mechanisms rather than scary strings — quote break-out, comment truncation,
  stacked statements, UNION exfiltration, backslash tricks, the **GBK multibyte quote** that
  defeats a charset-unaware escaper (the exact attack ADR-0014's ordering removes), null bytes,
  CRLF, Unicode lookalikes, and a 5.5 KB payload.
- The empty-string payload skips the containment half of the assertion, because every string
  contains the empty string and the check can never hold. It stays in the corpus for the binding
  half, and the skip is explained at the point it happens rather than silently dropped from the
  provider.
- **T-02 remains incomplete until item 5.2**, in one named respect, with a test asserting the
  current behaviour and the roadmap entry saying so.

## References

- Spec §7 T-02 (three legs), T-04; FR-10 (`Sanitizer::sqlLikePattern()`)
- ADR-0014 — the pinned real prepares that make the PDO boundary a sufficient proof point
- ADR-0015 — the identifier leg of T-02, already delivered
- ADR-0016 — the T-04 semantics, already delivered
- `PDO::ATTR_STATEMENT_CLASS` with constructor arguments, verified on PHP 8.3.1 / `pdo_sqlite`
