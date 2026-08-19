# 2026-08-19 — The bug that has no symptom, and a guard the analyser proved was theatre

Roadmap item **14.3** (spec r19 **FR-47**, **ADR-0064**, closes issue #95) — M14's third unit.
Route `frontier-reasoning / extra`; session model Opus 5, one tier below, recorded rather than
glossed.

## The item was surface; the work was correctness

FR-47 reads like a value-object exercise: a window, a result, some derived arithmetic. Two things
it did not name are what the ADR is about.

**An unordered paginated read is wrong, silently.** SQL guarantees no row order without
`ORDER BY`, so page 2 of an unordered query may repeat a row from page 1 and skip another — while
every individual page returns the right *number* of rows and passes any assertion a caller would
think to write. Worse for this repository specifically: on SQLite over an `INTEGER PRIMARY KEY`
the rows usually come back in rowid order, so **the bug does not reproduce where the tests run**
and appears later under a different plan. That is the shape this library exists to refuse, so
`fetchPage()` refuses it.

The gateway does not push that onto callers: it orders by its own key column, which `find()`
already treats as addressing a single row and is therefore exactly the unique column that makes
the split total. Worth noticing that this is the **opposite** answer to `all()`'s — which
deliberately adds no ordering — and that both follow one rule rather than contradicting each
other: never invent an ordering the caller does not need. An unpaginated read does not need one; a
paginated read is incorrect without one.

**The count could not be written where it was needed.** `COUNT(*)` cannot pass `Identifier`'s
allowlist, so a paginating layer in `Persistence` would have had to reach for
`SqlStatement::composed()` — and spending that costs the property ADR-0041 depends on: zero
in-library uses, so `grep composed(` *is* the review list. One use and the grep means nothing. So
the count became a builder clause, `QueryBuilder::asRowCount()`, which is ADR-0044's precedent
reused rather than a new idea.

## PHPStan proved my overflow guard was theatre

The first version read:

```php
$offset = ($page - 1) * $size;
if (!\is_int($offset)) { throw ... }
```

PHPStan refused it: *"Call to function is_int() with int<0, max> will always evaluate to true."*

The analyser is right about its own model and wrong about PHP — `int * int` overflows to a
**float** at runtime, so the check does fire. Which makes it the worst kind of guard: real, but
unprovable, and indistinguishable from a line someone added out of superstition. The repository's
own rule says fix the cause rather than suppress, and the fix was to ask the question *before*
multiplying:

```php
if ($page - 1 > \intdiv(\PHP_INT_MAX, $size)) { throw ... }
```

Exact integer arithmetic, nothing to narrow away, and it now says what it means. **Lesson worth
carrying: a runtime-only guard that a static analyser can prove vacuous is a guard nobody will
trust in six months** — reformulate it so the analyser can see the same thing the runtime does.

## A plant that told me the code it broke was defence, not path

Six plants, six caught. Five were ordinary. The interesting one removed the `LIMIT`/`OFFSET`
clearing from `asRowCount()` — and failed **only** the direct builder test, never a behavioural
one.

The reason is that `fetchPage()` counts *before* applying the window, so within this library the
clearing is never load-bearing. It stays, because `asRowCount()` is public API and
`$builder->limit(10)->asRowCount()` is a call a consumer will write — but the honest description
changed from "this is what makes the count correct" to "this is defence for a path the library
does not take", and the ADR says so. Third variant in this project's record of *what can this line
actually prevent?*: 12.1 deleted dead code, 12.3 kept code and deleted its justification, 12.4
deleted an unreachable check — here the code is reachable and tested, just not from the path that
motivated writing it.

## A defect of mine from the previous item

Writing FR-47's spec entry, I went to place it after FR-46 and found **FR-46 has no §2 entry at
all.** Item 14.2 added `NFR-15` (which references FR-46) and r18's revision row (which announces
FR-46) while never writing the requirement itself. The spec pointed at something it did not
contain — precisely the defect class ADR-0060 named when `SECURITY.md` deferred to a
`maintenance.md` section that did not exist, and which survived the entire pre-1.0 line for the
same reason: nobody follows a cross-reference to a document they are already holding.

Both entries are in at r19. Neither `consistency_lint` nor any gate could have caught it — the
lint checks the ADR index, version lockstep, pattern rows and the coverage map, and issue #116's
Markdown link checker would not see a missing *list item*. It is the second instance of this
class in ten days, which is an argument for whatever generalizes #116.

## Also, twice: the heredoc

Two PHP source files were corrupted mid-session by `python - <<'PY'` mangling `\a` into a bell
character (0x07) — once needing a byte-level repair. The project's own memory says, in as many
words, *use the Edit tool for PHP-source surgery, not a Python heredoc*. I did it anyway, twice,
before switching. Recorded because the note existed and was not enough; the operational version is
narrower: **never pass PHP source through a Python string literal at all**, since PHP's leading
backslashes on internal calls are exactly Python's escape characters.

## Numbers

2 978 tests (+110 — 31 in the new suite, 79 T-13 legs the paginated read paths inherit from the
hostile-identifier matrix), 6 363 assertions; CS-Fixer 0/259; PHPStan max clean; deptrac **377
allowed / 0 violations / 0 uncovered** (up from 347), no new edge — `Persistence → Database` was
already granted by name at ADR-0043.
