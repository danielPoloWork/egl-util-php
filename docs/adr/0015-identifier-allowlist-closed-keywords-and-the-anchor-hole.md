# ADR-0015: Refuse identifiers rather than escape them, close every keyword, and anchor the allowlist with `\z`

- **Status:** Accepted
- **Date:** 2026-08-04
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 4.2 · spec FR-07, FR-10 · item 4.4 (T-02) ·
  [RFC-0001](../rfc/0001-egl-utils-library.md) §Context (security mechanism 2) ·
  [ADR-0014](0014-pin-pdo-defaults-on-a-consumer-owned-connection.md) (mechanism 1) ·
  [ADR-0004](0004-root-the-exception-hierarchy-on-an-interface.md)

## Context

RFC-0001's security model has four mechanisms. ADR-0014 implemented the first (parameterised
values, via pinned PDO defaults). This is the second, and it exists because of a fact the first
cannot cover:

> **Persistence (identifiers): allowlist + strict driver quoting (prepared statements do not cover
> table/column names).**

A placeholder is not legal SQL where an identifier goes. There is no `?` for a column name. So an
identifier arriving from user input has nothing to hide behind, and the allowlist is not one
defence among several — it is the whole of it.

Spec FR-07 specifies the allowlist as `^[A-Za-z_][A-Za-z0-9_]*$`.

## Decision

**Refuse anything that is not a bare identifier; close every keyword that reaches the SQL text;
anchor the allowlist with `\z`.**

### The allowlist is anchored with `\z`, not `$` — and that is not a transcription liberty

Transcribed into PHP literally, FR-07's pattern **has a hole**. PCRE's `$` also matches
immediately before a trailing newline, so `"id\n"` satisfies `^[A-Za-z_][A-Za-z0-9_]*$`.

This was not reasoned about in the abstract — it was verified against the built class, which
happily produced:

```
SELECT "id\n" FROM "users"
```

past an allowlist that is supposed to be the only thing between an identifier and the statement.
The suite did not catch it either: the hostile-identifier matrix had a newline payload, but with
content *after* the newline, which fails the pattern for an unrelated reason.

`\z` admits nothing after the final character. **The decision is to implement FR-07's intent
rather than copy its notation**, and to record that here loudly enough that nobody later
"corrects" the pattern back to `$` for fidelity to the spec's wording. The pattern constant in
`QueryBuilder` carries the same warning at the point of temptation.

Practical severity of the original hole was low — the smuggled newline landed *inside* a quoted
identifier, so it produced an unresolvable column rather than an injection — but that is an
argument for the second layer having done its job, not for the first layer's hole being
acceptable.

### Identifiers are refused, not sanitised

A failing identifier raises `DatabaseException`. It is not stripped, truncated, or escaped-and-
accepted. Sanitising here would mean silently querying a *different* column than asked for, and an
identifier that came from user input is a vulnerability report, not an input to clean up — the
message says so, and points at mapping input to a known column at the call site.

### Every keyword that reaches the SQL text is a closed type

FR-07 asks for the `ORDER BY` direction to be an enum, for one reason: it is concatenated into
the SQL text and cannot be bound. **A comparison operator is concatenated into the SQL text for
exactly the same reason**, and FR-07 does not mention it. So `Operator` exists alongside `Sort`.

A `where(string $column, string $operator, mixed $value)` signature would bind the value safely,
allowlist the column safely, and leave an unchecked string spliced between them — the more
dangerous for looking harmless next to two carefully-handled parameters. Applying the spec's own
stated pattern to the case it missed is a smaller decision than inventing an operator allowlist,
which is why it is this and not that. Recorded as an **extension of FR-07**, not a silent addition.

`IN`, `IS NULL` and `IS NOT NULL` are separate builder methods rather than enum arms, because each
needs a different number of placeholders (many, and none) and an enum arm would silently bind the
wrong count.

### Quoting is kept, and is not merely decorative

Identifiers are wrapped in the driver's quoting characters — backticks (MySQL), brackets (SQL
Server family), double quotes (everyone else) — with the quote character doubled inside.

This class's first draft documented that escaping as *unreachable by construction*, since an
allowlisted identifier holds no quote character. The `\z` incident above is the counter-example:
for as long as the allowlist had a hole, the quoting is what kept the smuggled character contained.
The claim was corrected in place rather than deleted, because "the allowlist makes this
unreachable" is a claim about a regex being perfect, and the second layer costs nothing.

Quoting is also what makes a legal-but-reserved word (`order`, `select`) usable as a column name.

`PDO::quote()` is **not** used and cannot be: it produces a string *literal* (`'id'`), so quoting
an identifier with it yields a constant rather than a column reference — a silent wrong answer
rather than an error. Verified rather than assumed.

### `LIMIT`/`OFFSET` are refused when negative, not clamped

FR-07 says *"cast to non-negative int"*. `DatabaseException`'s own docblock, written back at item
2.1, already said the stricter thing: *"a `LIMIT`/`OFFSET` that is not a non-negative integer"* is
a refusal. That is followed. A negative value here means the caller computed it wrongly, and
silently returning a different page than the one asked for would hide the bug rather than surface
it. `int` by signature already excludes the string path.

They are rendered as literals rather than bound, which is safe *because* of that refusal, and is
in any case forced: several drivers reject a bound parameter in `LIMIT` once prepares are real —
which ADR-0014 pins.

### Qualified names are refused for now

`users.id` does not match, deliberately. FR-07's allowlist has no `.`, this builder has no `JOIN`,
and a single-table query never needs the qualification. Accepting it later — by validating each
dot-separated part — widens the allowlist and stays backward compatible; the reverse would not.

## Alternatives Considered

- **Escape identifiers instead of refusing them** — rejected. Quoting is not a substitute for the
  allowlist: it makes a hostile name *inert*, not *correct*, and the caller still gets a query
  against a column nobody meant. RFC-0001 says allowlist **and** quoting, in that order.
- **Copy FR-07's `$` verbatim for spec fidelity** — rejected on the evidence: it is a real bypass
  in PCRE. Fidelity to a specification's intent beats fidelity to its notation when the two
  disagree and the notation is the one that is wrong.
- **`where()` taking a string operator with a runtime allowlist** — rejected in favour of the enum:
  same protection, but checked by the type system instead of at run time, and consistent with the
  `Sort` decision FR-07 already made.
- **Mutable builder** — rejected: an immutable builder can be held, shared and reused as a base
  query without a later call mutating it under its holder.
- **Rendering `IN ()` or silently dropping an empty `whereIn()`** — rejected, and the reasoning is
  in the exception message: matching nothing would be an accident rather than a decision, and
  dropping the condition would silently **widen** the result set. A filter that quietly stops
  filtering is the worse failure.
- **Binding `LIMIT`/`OFFSET`** — not available; real prepares reject it on several drivers.

## Consequences

- Identifier injection is a refusal with a message that names the actual problem. 17 hostile
  payloads × 4 identifier surfaces (table, `select`, `where`, `orderBy`) are asserted, including
  the trailing-newline case that this ADR exists partly to record.
- **The suite is verified non-vacuous.** Four planted defects, each caught and reverted: weakening
  the allowlist to `/.*/ ` (76 failures), interpolating the value instead of binding it (3),
  allowing a negative `LIMIT` (2), and dropping MySQL's backtick arm (1).
- Driver-specific quoting is asserted on rendered SQL rather than by execution, because SQLite —
  the only driver available in CI — accepts double quotes, backticks *and* brackets, so a query
  built with entirely the wrong style still runs. `PretendDriverPdo` reports a chosen driver name
  over a real SQLite connection to make the choice observable.
- That same fixture closes half of the gap item 4.1 declared: `SET NAMES utf8mb4` is now asserted
  to be issued for MySQL and *not* issued for other drivers. It still does not prove a real MySQL
  server accepts it — the driver matrix remains T-02's (item 4.4).
- PHPStan at max level surfaced that a variadic is not a `list`: PHP 8.1 permits named arguments
  into one (`select(first: 'id')`), producing string keys. Fixed with `array_values()`.
- No `JOIN`, `GROUP BY`, `HAVING` or aggregate support. FR-07 does not ask for them, and each adds
  identifier surfaces that would need the same treatment.

## References

- Spec FR-07 (the allowlist, the enum, `LIMIT`/`OFFSET`), FR-10 (`LIKE` wildcards — `Operator::Like`
  binds the value but wildcard-injection is `Sanitizer::sqlLikePattern()`'s problem, milestone 5)
- RFC-0001 §Context, security mechanism 2 — allowlist + strict driver quoting
- ADR-0014 — mechanism 1, and the pinned real prepares that make `LIMIT` un-bindable
- Verified directly: PCRE `$` vs `\z` on `"id\n"`; `PDO::quote()` producing a string literal
  (PHP 8.3.1)
