# 2026-08-06 — The verb that was never there, and a defect campaign that proved nothing

Roadmap item **10.4**. Route `frontier-reasoning / extra`; session model Opus 5, so a **route
mismatch**, flagged to the maintainer before any code was written and accepted.

## One grep changed the item

The item, and FR-35 behind it, say the gateway composes *"select/insert/update/delete exclusively
through `QueryBuilder`"*. `QueryBuilder` has no write verb. Not a missing method — there is no
`INSERT`, `UPDATE` or `DELETE` anywhere in the `Database` group, and there never was. The read
half of the sentence was implementable; the write half described a class that does not exist.

That reframed the item from *how to write a gateway* to **where write-side SQL composition
lives**, with two properties to preserve. One allowlist, because identifiers cannot be bound and a
second copy of the pattern is one edit from two rules that disagree — and unlike the deliberately
duplicated `LIKE_ESCAPE`, whose drift produces a wrong query, an allowlist's drift produces a
vulnerability. And a countable set of doors into `SqlStatement`, because ADR-0041's whole value
rests on `composed()` having zero in-library uses.

Composing the SQL inside `TableGateway` would have failed both: a second generator in the group
whose job is to call one, and its text entering through the escape hatch — spending the review
list on machine-generated SQL that has a checker. So `MutationBuilder` sits in `Database` beside
the read builder, `Identifier` is shared by both, and `fromMutation()` is a fourth named door that
takes the builder object rather than its text. `composed()` still has zero in-library uses.

**Spec FR-35 is corrected in this PR** (r11) rather than left describing something absent. The
alternative — implement the truth, leave the spec saying otherwise — is the drift §7 exists to
prevent.

## Two things found by tests failing for the wrong reason

**SQLite does not reject an unknown column.** A DTO declaring a column the table lacks was
expected to raise a `DatabaseException` at the driver. It does on MySQL, PostgreSQL and SQL
Server. On SQLite the double-quoted-string misfeature accepts `"nickname"` as a *string literal*,
so the statement succeeds and every row arrives carrying `'"nickname"' => 'nickname'` — quotes
included in the key. Probed directly. What refuses it there is strict hydration, on the first row,
which leaves one genuine blind spot: **against an empty table the mismatch is invisible.** Both
behaviours are now asserted, and the fix that would close it (a schema round trip per gateway) is
declined in the ADR rather than left unmentioned.

**Extracting `Identifier` made `QueryBuilder::toSql()` pure**, because the table is now quoted in
the constructor. PHPStan noticed before I did: two untouched tests ended a fluent chain with a
bare `->toSql();` and became `method.resultUnused`. They keep the result and assert it now. Worth
recording as a class of event — a refactor can change what static analysis can *prove* about code
the diff never touched.

## The campaign that proved nothing, the first time

Ten defects planted one at a time, each expected to turn its own suite red. Ten `CAUGHT`. The
script also printed ten `error: pathspec … did not match any file(s) known to git`.

The new files were **untracked**, so `git checkout -- <file>` restored nothing. Defects
accumulated across the run — by the end, `Identifier` had both a weakened anchor *and* a bypassed
allowlist — and any given `CAUGHT` might have been caused by an earlier mutation rather than the
one under test. Worse, the working tree was left corrupted, and the summary line looked exactly
like a successful campaign.

Repaired the seven mutated sites, re-verified green, staged the files so `git checkout` had an
index to restore from, and re-ran: ten planted, ten caught, tree clean afterwards. **A verification
step whose restore silently fails produces output indistinguishable from one that worked** — the
tell was in a stream I nearly did not read.

## What landed

`Identifier`, `MutationBuilder`, `SqlStatement::fromMutation()`, `TableGateway`; ADR-0044; spec
r11; the patterns catalogue's **first** entry (Table Data Gateway) with three recorded rejections
beside it (Active Record, Unit of Work, Data Mapper) and a new taxonomy row, since Fowler's
pattern was not in this repo's canonical list. 1770 tests (+119), PHPStan max clean, deptrac 0
violations / 0 uncovered / 253 allowed, no new edge — `QueryBuilder` and `MutationBuilder` are
both in `Database`, already granted at item 10.3.

## Lesson

Read the failure stream of your own verification tooling. A green summary above ten error lines is
not a green run — and a restore step that fails silently turns a defect campaign into theatre that
looks identical to the real thing.
