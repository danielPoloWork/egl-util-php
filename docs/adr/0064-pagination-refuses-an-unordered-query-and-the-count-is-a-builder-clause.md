# ADR-0064: Pagination refuses an unordered query, and the count is a builder clause

- **Status:** Accepted
- **Date:** 2026-08-19
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item **14.3** · issue [#95](https://github.com/danielPoloWork/egl-util-php/issues/95) ·
  spec **r19 FR-47** · [RFC-0003](../rfc/0003-post-1-0-functional-scope.md) (the design this
  realizes) · [ADR-0041](0041-constrain-sql-text-by-type-and-name-the-one-escape-hatch.md)
  (`composed()`'s zero-in-library-uses property §2 protects) ·
  [ADR-0044](0044-the-write-builder-querybuilder-never-had-and-one-allowlist-for-both.md)
  (the precedent for putting SQL composition in `Database`, not `Persistence`) ·
  [ADR-0043](0043-two-named-edges-out-of-persistence-and-no-catch-at-all.md) (FR-34's
  no-silent-sentinel rule §4 applies) · [ADR-0008](0008-dto-hydration-strictness-and-shared-hydrator.md)
  (strict hydration, which the projection keeps satisfiable) · issue
  [#110](https://github.com/danielPoloWork/egl-util-php/issues/110) (the SQLite-only proof §3 rests on)

## Context

Every consumer listing rows pages them, and every estate hand-rolls the same three things
differently: the offset arithmetic, the total-count query, and — usually not at all — the ordering
that makes consecutive pages mean anything. RFC-0003 accepted FR-47 to remove the duplication.

The RFC settled the surface (`PageRequest`, `Page<T>`, gateway/repository reads, `withTotal`
defaulting to true, window-function totals rejected on portability). Implementing it surfaced two
questions it did not answer, and both are the kind that decide whether the feature is correct or
merely present.

## Decision

### 1. An unordered query is refused, because unstable pagination has no symptom

SQL guarantees no row order without `ORDER BY`. Two windows over the same unordered query may
therefore **repeat one row on page 2 that already appeared on page 1, and silently skip
another** — while every individual page looks perfectly valid, returns the right number of rows,
and passes any assertion a caller would think to write. On SQLite over an `INTEGER PRIMARY KEY`
the rows usually come back in rowid order, which is worse than useless: it means the bug does not
reproduce in development and appears under a different plan in production.

So `Repository::fetchPage()` **refuses a builder with no ordering**, on the strength of a new
`QueryBuilder::isOrdered()`. This converts a silent data-correctness bug into a message at the
seam, which is the trade this library makes everywhere — the same shape as `TableGateway`'s
refusal of empty criteria.

`TableGateway::paginate()`/`paginateBy()` do not push that decision onto the caller: they order by
**the gateway's own key column**, which is the column `find()` already treats as addressing a
single row, and therefore exactly the unique column that makes the split total. A caller who wants
a different order writes the query and goes through the `fetchPage()` seam.

**This is the opposite answer to `all()`'s, for the same reason.** `all()` deliberately adds no
ordering, because an unpaginated read would pay for a promise nobody asked for. A paginated read
is *incorrect* without one. The rule is not "never invent an ordering" but "never invent one the
caller does not need" — and the two reads differ on whether they need it.

### 2. `COUNT(*)` becomes a builder clause, not a hand-written statement

The total needs `SELECT COUNT(*)`, which cannot pass `Identifier`'s allowlist — it is not a bare
identifier. A paginating layer that had to produce it would need `SqlStatement::composed()`, and
spending that would cost the property ADR-0041 relies on: **zero in-library uses, so `grep
composed(` is the review list**. One use inside the library and the grep stops being a review
list at all.

So `QueryBuilder::asRowCount()` renders it, exactly as ADR-0044 put write composition in
`Database` rather than `Persistence` for the same reason. `ORDER BY`, `LIMIT` and `OFFSET` are
dropped from the count: a `LIMIT` would count the window rather than the population, and ordering
rows nobody looks at is work with no result. The `WHERE` and its bindings carry over, so the count
answers for exactly the rows the unwindowed query would return.

**An honest note on that clearing.** `fetchPage()` counts *before* applying the window, so within
this library the drop is never load-bearing — a plant that removed it failed only the direct
builder test, not any behavioural one. It stays because `asRowCount()` is public API and
`$builder->limit(10)->asRowCount()` is a call a consumer will write; it is tested directly rather
than inferred from the paginated path. Recorded rather than quietly kept, following the standing
question this project asks of every guard (ADR-0022's dead-defensive-code precedent): here the
answer is that the code is reachable and tested, just not from the path that motivated it.

### 3. The total costs a second statement, and window functions were not an option

`withTotal` defaults to **true**, because "page 3 of 47" is the reason to paginate and a `Page`
that could not answer it would send every consumer to write the count query this exists to remove.
It costs a second statement, stated plainly rather than hidden, and `PageRequest::withoutTotal()`
is the opt-out for callers who only need rows.

`COUNT(*) OVER ()` would collapse the two statements into one. **Rejected on portability, and the
reason is a fact about this repository rather than about the construct**: every database proof
here runs on SQLite alone (issue #110 exists to fix that), so a construct whose support is
version-dependent across three engines cannot be *claimed* to work — it could only be asserted
against the one engine CI runs. Revisit when #110 lands a real-engine leg.

Two statements also mean the count and the rows are read a moment apart, so a table under
concurrent writes can return a total that does not match the window to the row. Inherent to
counting separately; documented at the seam.

### 4. A missing total throws; a non-numeric count throws

`Page::total()` on a page requested without one **refuses**, rather than answering `0` or `null`.
A zero that means "not counted" renders as "0 results" in a template and is indistinguishable from
an empty table — the silent-sentinel failure FR-34 was written against, arriving in a new place.
`hasTotal()` and `totalOr()` are the tolerant forms: `Lookup`'s exact three-way missing-value
policy, reused rather than reinvented.

The same rule applies one layer down. `COUNT(*)` arrives as an `int` on some drivers and a numeric
string on others, so it is checked rather than cast blind — and a missing or non-numeric answer
throws instead of defaulting to `0`, for the identical reason.

`pageCount()` returns **1** for an empty result set rather than `0`: page 1 of an empty table is
the page a consumer is looking at when they are told there are no results, and "page 1 of 0" is a
string no interface wants to render. `hasNextPage()` is derived from the total and is deliberately
**not** guessed from a full window — "this page is full, so there is probably more" is wrong
exactly on the last page of every evenly divisible table.

### 5. Nonsense is refused rather than clamped, including the arithmetic

A page below 1 or a size below 1 throws. Clamping is how an unvalidated `?page=0` becomes page 1
and nobody learns the request was malformed — `findBy([])`'s reasoning, applied to a different
unvalidated parameter.

The overflow guard is worth its own line, because **PHPStan proved the first version of it was
theatre**. `is_int(($page - 1) * $size)` after the multiplication is always true to the analyser,
which models `int * int` as `int`; PHP actually yields a *float* on overflow, so the check was
real at runtime and unprovable statically — a guard that reads as one while the analyser is
certain it never fires. Reformulated as a division asked *before* multiplying
(`$page - 1 > intdiv(PHP_INT_MAX, $size)`), it is exact integer arithmetic the analyser cannot
narrow away, and it says what it means.

## Alternatives Considered

1. **Let an unordered query through, and document the risk.** Rejected in §1: the failure is
   silent, does not reproduce on the engine CI runs, and corrupts a *set* of results rather than
   one of them. A documented footgun in a library whose stated stance is refusal would be the
   wrong entry in that ledger.
2. **Require the caller to pass an ordering to `TableGateway::paginate()`.** Rejected: the gateway
   already knows the unique column that makes the split total, and asking every caller to name it
   is asking them to think about a problem the gateway can answer. The `fetchPage()` seam remains
   for a different order.
3. **`SqlStatement::composed()` for the count.** Rejected in §2 — it spends ADR-0041's
   zero-in-library-uses property, which is the whole mechanism that makes `grep composed(` a
   review list.
4. **A `count()` method on `TableGateway` instead of a builder clause.** Rejected: it answers only
   the gateway's own shapes, so a `Repository` subclass paginating its own query would still have
   nowhere to get a count — and would reach for `composed()`.
5. **`COUNT(*) OVER ()`** — one statement instead of two. Rejected in §3 on this repository's
   SQLite-only proof, not on the construct's merits. Revisit with issue #110.
6. **`Page::total()` returning `?int`.** Rejected in §4: `null` is exactly as renderable as `0`,
   and a template that prints an empty total is the same silent wrong answer one type further out.
7. **Clamping page and size into range.** Rejected in §5.
8. **A built-in maximum page size.** Rejected as invented policy: any ceiling would be arbitrary,
   and the library has no basis to decide how many rows a consumer's page may hold. The overflow
   guard covers the arithmetic; what is a *reasonable* size is the caller's to bound, at the point
   where the request parameter is validated.

## Consequences

- Spec **r19** (FR-47); **2 978 tests** (+110: 31 in the new suite, 79 T-13 legs the paginated
  paths inherit from the hostile-identifier matrix); **six planted defects, six caught**, each
  verified *landed* by absence of the original first. deptrac 377 allowed / 0 violations / 0
  uncovered.
- `QueryBuilder` gains two additive methods (`asRowCount()`, `isOrdered()`); `Repository` gains
  `fetchPage()`; `TableGateway` gains `paginate()`/`paginateBy()`. All additive — ADR-0059's
  freeze respected, no existing signature moves. No new deptrac edge: `Persistence → Database` is
  already granted by name (ADR-0043).
- **T-13 extends to the new read paths** rather than a new suite being claimed: `paginateBy` joins
  the hostile-identifier surface matrix — inheriting the empty-query-log assertion, which is the
  part a round-trip test cannot make — and the `COUNT` statement gets its own binding leg, because
  a count that inlined its filter would still return a plausible number nobody would question.
- **What this does not settle**: keyset ("seek") pagination, which is the right answer for deep
  offsets and a different API rather than an option on this one; window-function totals (see #110);
  and any maximum page size.

## References

- Issue [#95](https://github.com/danielPoloWork/egl-util-php/issues/95) · ROADMAP item 14.3 ·
  spec r19 FR-47
- [RFC-0003](../rfc/0003-post-1-0-functional-scope.md) § New components (FR-47) and § Alternatives
  5–6 (the window-function and no-total rejections this ADR carries out)
- [ADR-0041](0041-constrain-sql-text-by-type-and-name-the-one-escape-hatch.md) ·
  [ADR-0044](0044-the-write-builder-querybuilder-never-had-and-one-allowlist-for-both.md) —
  why the count is a builder clause
- [ADR-0043](0043-two-named-edges-out-of-persistence-and-no-catch-at-all.md) — FR-34's
  no-silent-sentinel rule, which §4 applies twice
