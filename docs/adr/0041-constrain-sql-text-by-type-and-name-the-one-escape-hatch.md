# ADR-0041: Constrain SQL text by type, and name the one escape hatch

- **Status:** Accepted
- **Date:** 2026-08-06
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Amends:** [ADR-0039](0039-sql-text-and-its-parameters-become-one-value-not-two-arguments.md)
  — whose decision (SQL text and parameters are one value, and the only shape
  `DatabaseConnection` executes) **stands**; what changes is who enforces it
- **Related:** ROADMAP item 10.7, filed by the item-10.1 review · spec r3 **FR-33** (RFC-0002) ·
  [ADR-0015](0015-identifier-allowlist-closed-keywords-and-the-anchor-hole.md) (the allowlist
  that makes `QueryBuilder`'s composed text safe) ·
  [ADR-0027](0027-constant-time-comparison-is-asserted-by-mechanism-not-by-timing.md) (asserting
  a mechanism from the source, the pattern reused for the test here) ·
  [ADR-0014](0014-pin-pdo-defaults-on-a-consumer-owned-connection.md) (real prepares) ·
  roadmap items 10.3–10.4, the callers this exists for

## Context

ADR-0039 wrote, in its own Alternatives section, that *"a type-level guarantee catches what a
string never announces; a runtime heuristic cannot"* — and then shipped a `SqlStatement` whose
constructor took a plain `string`. It considered and correctly rejected a **runtime** assertion
(counting placeholders cannot distinguish a legitimately parameter-free statement from a
forgotten bind) and **never considered the static one**. That omission is the finding the
item-10.1 review filed as item 10.7.

The static option exists and this repository already runs the tool that enforces it: PHPStan's
`literal-string` type, at the `max` level `phpstan.neon` pins. Verified against this
repository's own configuration before the item was filed, and again here with four planted
mistakes and four cases that must keep working:

| probe | verdict |
|---|---|
| `literal("… name = '{$userValue}'")` — interpolation | **rejected** |
| `literal('… name = ' . $userValue)` — concatenation | **rejected** |
| `literal(sprintf('… name = %s', $userValue))` | **rejected** |
| `literal('… IN (' . implode(',', $values) . ')')` | **rejected** |
| `literal('… WHERE id = ?', [1])` | passes |
| `literal('SELECT * FROM users' . ' WHERE id = ?', [1])` — literal ‖ literal | passes |
| `literal('SELECT c FROM stock WHERE code[1, ?] = ?', [$len, $code])` — hand-written dialect SQL | **passes** |
| `composed('… IN (' . implode(',', array_fill(0, count($v), '?')) . ')', $v)` | passes |

The seventh row is the one that mattered most before committing to this: FR-33 exists *because*
hand-written dialect SQL is legitimate (the surveyed estate uses positional substring
predicates `QueryBuilder` deliberately does not model). `literal-string` permits it. Being
hand-written was never the problem; being **assembled from values** is, and that is precisely
the line the type draws.

ADR-0039 also claimed the constructor was *"exactly one place"* where text and parameters could
be mispaired. That was a half-truth the review corrected: `new SqlStatement(...)` is written at
**every** call site, so the count of risky sites never dropped — only their greppability
improved.

## Decision

Constrain the text by type, and make the constructor private so no unconstrained way in
survives. `SqlStatement` exposes exactly three named constructors, and which one a call site
uses **is** the review signal:

| entry point | promises | enforced by |
|---|---|---|
| `SqlStatement::literal(literal-string $sql, array $parameters = [])` | the text is a compile-time literal | **PHPStan**, on every PR |
| `SqlStatement::fromQueryBuilder(QueryBuilder $builder)` | the text came from the builder | `QueryBuilder`'s allowlist (ADR-0015) and its own suite |
| `SqlStatement::composed(string $sql, array $parameters = [])` | the caller asserts the assembled text holds no untrusted value | **a human, at review** |

`composed()` has **zero uses inside this library**. That is the load-bearing property, not an
accident of the current code: `grep composed(` is meant to return exactly the places where a
person had to think, and every in-library call would dilute that list. It is why
`fromQueryBuilder()` takes the *builder object* rather than its `toSql()` string — the
library's own composed SQL then needs no escape hatch at all.

The private constructor is what makes this work **with no suppression of any kind** — no
analyser-ignore comment, no inline type override, both of which this project forbids outright.
The private constructor's parameter is a plain `string`; each public entry point states in its
own signature what it promises. Had the annotated constructor stayed public, `composed()` could
only have called it by overriding the analyser, and a guarantee that has to be suppressed to
implement is not a guarantee.

## Alternatives Considered

- **Keep `__construct` public and annotated, and let `composed()` suppress the analyser** —
  rejected: forbidden by this project's own rules, and self-defeating. The suppression would
  sit permanently inside the class whose entire purpose is to not need one.
- **Ship only `literal()` and `fromQueryBuilder()`, with no escape hatch** — rejected: a bulk
  `INSERT … VALUES (?,?),(?,?)` or an `IN (?,?,?)` whose placeholder count follows the data
  cannot be a literal, and `QueryBuilder` does not model bulk inserts. Consumers would be
  pushed into suppressing the analyser on *their* side, which is the same hole one repository
  further away.
- **Name the escape hatch `unsafeRaw()` / `unsafeSql()`** — rejected. Nothing about it is unsafe
  by construction: values still bind through ADR-0014's real prepares, and the only lost
  property is PHPStan's ability to prove the text held no runtime value. A name that cries wolf
  on every legitimate bulk insert is a name that gets ignored on the one call that deserved
  attention. `composed()` says what actually happened.
- **`QueryBuilder::toStatement()` using `composed()` internally**, instead of
  `fromQueryBuilder()` — rejected on the property above: it is *truthful* (the builder's text
  genuinely is composed at runtime) and needs no new coupling, but it puts two in-library calls
  into `grep composed(` and turns the review list into something with routine entries in it.
  The small coupling inside one group buys a signal that stays clean.
- **A `#[SensitiveParameter]`-style attribute, or a custom PHPStan rule** — rejected as
  reinvention: `literal-string` is the ecosystem's existing answer, understood by both PHPStan
  and Psalm, and needs no rule of ours to maintain.

## Consequences

- **A second breaking change to this class in two PRs**, and worth naming rather than
  smoothing: item 10.1 retired `(string, array)` for `new SqlStatement(...)`, and item 10.7 now
  retires `new SqlStatement(...)` for `SqlStatement::literal(...)`. Both are pre-1.0 MINOR
  breaks SemVer §4 permits, and 51 call sites moved mechanically — but the honest reading is
  that one PR should have shipped both, and the reason it did not is that ADR-0039 never
  weighed the static alternative. That is the process finding, not just the code one.
- The guarantee now **fails a CI gate** instead of relying on a convention every future call
  site has to re-earn. Items 10.3–10.4 (`Repository`, `TableGateway`) are the callers it exists
  for, and they inherit it before they are written — which is why this item was sequenced
  ahead of them.
- **Tested as a mechanism, per ADR-0027.** No runtime test can exercise a static property, so
  `SqlStatementTest` asserts from the source that the `literal-string` annotation is present,
  that the constructor is still private, and that the public static surface is exactly the
  three named constructors — the last pinned so a fourth entry point is a deliberate act with
  a test to update. The proof that PHPStan *acts* on the annotation is the planted-probe table
  above, run here, not inferred.
- `literal-string` is a PHPDoc-only annotation: no runtime cost, no effect on the PHP 8.1
  floor, and nothing for `--prefer-lowest` to resolve.
- **Not closed by this:** a consumer who calls `composed()` carelessly is no safer than before.
  The gate moves the failure from invisible to reviewable, and says so — the same honesty
  ADR-0039 owed and this ADR keeps: `grep composed(` is a review aid, not a proof.

## References

- ADR-0039 (amended here; its Alternatives section is where the omission is visible)
- FR-33, spec r3 — *"hand-written dialect SQL always travels with its binds"*, the requirement
  the seventh probe row confirms is still satisfiable
- PHPStan `literal-string`: the ecosystem type this decision leans on rather than reinventing
- Planted-probe run, 2026-08-06: 4 mistakes rejected, 4 legitimate shapes accepted
