# 2026-08-24 — One arm of the `match` had ever run

Issue **#110**, all three criteria. Route `standard / high`; session model Opus 5 — matched.
**ADR-0071** added, **ADR-0044** annotated.

The release review board recorded this as the SDET Lead's single highest-value recommendation:
every database and persistence proof in the repository ran on SQLite and nothing else. The obvious
reading of that is "add a second engine to CI". The more useful reading is what the audit turned
up when I went looking for *which claims* were untested.

## What was actually unproven

`Identifier::forDriver()` is a four-arm `match` over the PDO driver name, and it is a **security
control** — identifiers cannot be bound, so the allowlist and the quoting are the entire defence
(ADR-0015). One arm had ever reached a server. The `sqlsrv`/`dblib`/`mssql` arm still has not, and
that is now written down as a limitation instead of being implied to be covered.

`Sanitizer::LIKE_ESCAPE` picks `!` over `\` on a claim about three engines: that MySQL and
PostgreSQL treat a backslash as an implicit `LIKE` escape while SQLite does not, and that
`ESCAPE '\'` is a *parse error* on SQLite. Written from documentation, and correct — but the
repository had no way to know that.

`Transaction`'s nesting is three statements — `SAVEPOINT`, `ROLLBACK TO SAVEPOINT`,
`RELEASE SAVEPOINT` — that only SQLite had ever parsed.

And the one with teeth: **driver type coercion decides whether the `Persistence` group works at
all.** A DTO declares `public readonly ?int $age`, the hydrator calls its constructor under
`strict_types=1`, and a driver that returns `'36'` makes every gateway read a `HydrationException`.
`Repository::countRowsOf()` already hedges — "an `int` on some, a string on others" — which is the
codebase saying in prose that it did not know. `DialectTest` now asks.

## The reason it had never been done

Sixteen `setUp()` methods each wrote `new PDO('sqlite::memory:')` under
`#[RequiresPhpExtension('pdo_sqlite')]`. That is not one decision to use SQLite; it is sixteen. So
"point the suite at MySQL" was sixteen edits and a schema problem, not a configuration change —
which is a much better explanation for the gap than anyone having decided it did not matter.

One environment variable now does it. `EGL_TEST_DB_DSN` unset is `sqlite::memory:` and the default
developer run is byte-for-byte the run it was.

## Three decisions worth the argument

**Fail, never skip.** The third criterion, and the one that decides whether the leg is worth
having: a job that goes green because it could not reach a database certifies coverage nobody has,
which is worse than no job at all. Four independent things now make that impossible — the service
health check, a preflight that authenticates with the suite's own credentials, a harness that
raises rather than skipping for a *configured* engine, and `--fail-on-skipped`. The unconfigured
run keeps its skip, because a bare checkout without `pdo_sqlite` behaved that way long before this
issue and nothing here is a claim about it.

**A `try`/`catch` in an injection suite, justified in writing.** Three corpus members are values a
real server will not store: a NUL byte, the GBK quote sequence that is not valid UTF-8, and any
hostile string reaching an integer column, which PostgreSQL rejects where SQLite and MySQL coerce.
In each, the driver was handed a placeholder-only statement and a bound parameter *and then said
no* — the refusal happens strictly after the property under test. `attempt()` tolerates it and
lets the boundary assertion run. What keeps it honest: **on the default engine it rethrows**, so
SQLite's proof is exactly as strong as it was, and the assertion that follows still fails if the
log is empty or the payload is not among the bound values. Where the test is about consequences
rather than syntax, the refusal branch asserts the refusal was *clean* — no row, neighbouring
table untouched — rather than skipping.

**One pinned collation, and one test for the collation.** MySQL 8's default
`utf8mb4_0900_ai_ci` is case- and accent-insensitive, so `WHERE name = 'ada'` finds `'Ada'` there
and on neither of the others. Left alone, that would have surfaced as dozens of unexplained
failures in suites whose subject is injection binding and DTO projection. Pinning `utf8mb4_bin` in
the fixture schema and asserting the *default* behaviour once, on its own table, is the version
where the difference is answered rather than absorbed.

## What the leg turned from prose into a result

`TableGatewayTest` had a test literally named
`testOnSqliteTheSameMismatchIsInvisibleWhileTheTableIsEmpty`, and a docblock explaining that every
other driver rejects the unknown identifier at prepare time. Both halves are now executed. So is
ADR-0044's cost argument, which turns out to apply to exactly one engine — annotated there rather
than edited, per ADR-0041's precedent.

Worth noting for the next reader: MySQL would *also* have accepted `"nickname"` as a string
literal — its default `sql_mode` reads double quotes the way SQLite does. It is refused there only
because the library quotes MySQL identifiers with backticks. The divergence is a property of the
library and the engine together, not of the engine alone, and `DialectTest` says so.

## What the first run found

952/954 on PostgreSQL 16.15, 954/954 on MySQL 8.4.11. Most of the audit came back *confirming* the
code, which is a result and not a disappointment: both engines return native `int` for a declared
integer column and for a `COUNT(*)` on PHP 8.1, so strict hydration works on all three and
`countRowsOf()`'s cast is defensive rather than load-bearing. Quoting, savepoints, `ESCAPE '!'` —
all fine.

The two failures were one finding, and it is the one that justifies the whole issue:

> **PDO_PGSQL silently truncates a bound parameter at its first NUL byte.**

The corpus payload `admin\0' OR 1=1` **inserts successfully** and reads back as `admin`. No
exception, correct row count, tail gone. I expected the opposite failure — PostgreSQL refusing
`0x00` in a `text` column, which is what the *server* does. It never gets the chance: libpq sends a
bound parameter as a NUL-terminated C string, so nothing past the first NUL leaves the client.
MySQL and SQLite store the whole value.

Two things about how that got handled. First, there is nothing in this library to fix — the value
binds correctly and PDO shortens it below us — so it is **recorded, not worked around**:
`Engine::storedForm()` names it, both round-trip suites compare against it, and `DialectTest`
asserts it standalone on all three engines. Second, it is exactly the shape of defect a
single-engine suite cannot find *by construction*, because it produces a passing test and a correct
row count on the engine you happen to run.

## Where this leaves the project

955 tests in the `database-engine` group, run three times over — once in the ordinary matrix on
SQLite and once per engine in the new `database` job, on PHP **8.1** rather than 8.3, because the
floor is where a type-coercion difference would show. Local suite: 3 182 tests green.

Open, and deliberately: the fourth arm of `Identifier`'s `match`, and any claim about server
versions other than the two images pinned in the workflow.
