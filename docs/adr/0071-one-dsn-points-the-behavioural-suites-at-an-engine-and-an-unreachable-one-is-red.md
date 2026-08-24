# ADR-0071: One DSN points the behavioural suites at an engine, and an unreachable one is red

- **Status:** Accepted
- **Date:** 2026-08-24
- **Deciders:** project architect (agent), maintainer
- **Related:** issue [#110](https://github.com/danielPoloWork/egl-util-php/issues/110) · [ADR-0014](0014-pin-pdo-defaults-on-a-consumer-owned-connection.md) · [ADR-0015](0015-identifier-allowlist-closed-keywords-and-the-anchor-hole.md) · [ADR-0016](0016-closure-scoped-transactions-with-savepoint-nesting.md) · [ADR-0017](0017-prove-binding-at-the-pdo-boundary-and-defer-t02s-like-leg.md) · [ADR-0044](0044-the-write-builder-querybuilder-never-had-and-one-allowlist-for-both.md) · spec §5 (T-02, T-13), §7, §8

## Context

Every behavioural proof the `Database` and `Persistence` groups carry ran against SQLite and
nothing else. Sixteen `setUp()` methods each wrote `new PDO('sqlite::memory:')` under a
`#[RequiresPhpExtension('pdo_sqlite')]` attribute, which is sixteen independent decisions to use
one engine — and the practical reason nobody had ever added a second one: pointing the suite
elsewhere was sixteen edits, not a configuration change.

The release review board (2026-08-09) recorded this as the SDET Lead's single highest-value
recommendation, and the gap is not hypothetical. Three things in the library are **dialect
behaviour**, not engine-independent SQL:

- `Identifier::forDriver()` is a four-arm `match` over the driver name. One arm had ever reached a
  server. The other three were, in the literal sense, untested code shipped as a security control.
- `Sanitizer::LIKE_ESCAPE`'s choice of `!` over `\` rests on a claim about MySQL and PostgreSQL
  treating a backslash as an implicit `LIKE` escape while SQLite does not, and on `ESCAPE '\'`
  being a parse error on SQLite. Both were written from documentation.
- `Transaction`'s nesting is `SAVEPOINT` / `ROLLBACK TO SAVEPOINT` / `RELEASE SAVEPOINT`, three
  statements only SQLite had ever parsed.

And driver **type coercion** decides whether the `Persistence` group functions at all: a DTO
declares `public readonly ?int $age`, the hydrator calls its constructor under `strict_types=1`,
and a driver that hands back `'36'` turns every gateway read into a `HydrationException`.
`Repository::countRowsOf()` already hedges — its docblock says a `COUNT(*)` arrives "an `int` on
some, a string on others" — which is the codebase admitting in prose that it did not know.

SQLite is also the engine already caught being too lenient once: its double-quoted-string
misfeature reads `"nickname"` as a string literal when no such column resolves, which is the blind
spot ADR-0044 accepted, and which it described as SQLite-only on reasoning rather than on evidence.

## Decision

**A single environment variable, `EGL_TEST_DB_DSN`, points the behavioural suites at an engine;
unset, they are `sqlite::memory:` exactly as before. A configured engine that cannot be reached
fails the run — it never skips.**

The mechanism is three small classes under `D4np\Utils\Tests\Engine`:

- `Engine` — an enum whose cases are the **PDO driver names** (`sqlite`, `mysql`, `pgsql`), so it
  agrees by construction with what `DatabaseConnection::driver()` reports and what
  `Identifier::forDriver()` dispatches on. It carries a three-shape DDL vocabulary (`key`, `text`,
  `int`) — everything the converted suites need, and no more.
- `TestDatabase` — reads the variable, opens **one connection per process**, and drops and
  recreates the fixture tables in each `setUp()`. Per-test isolation comes from replacing the
  tables rather than from a transaction, because MySQL commits DDL implicitly and there is no
  portable way to undo a fixture table.
- `RunsAgainstADatabaseEngine` — the trait the suites mix in, and the home of `attempt()` (below).

Five suites are converted: T-02 (`InjectionTest`), T-13 (`GatewayInjectionTest`),
`TableGatewayTest`, `RepositoryTest` and `PaginationTest`. A sixth, `DialectTest`, is new and
exists only for divergences.

CI runs the `database-engine` group against MySQL 8.4 and PostgreSQL 16 service containers, on PHP
**8.1** — the library's floor rather than CI's newest, because driver type coercion is the one
behaviour here that moved across the 8.x line.

### Fail, never skip

Issue #110's third criterion, and the one that decides whether the leg is worth having: a job that
goes green because it could not reach a database certifies coverage nobody has, which is strictly
worse than no job. Four independent things enforce it:

1. the service container's health check gates the job;
2. a preflight step authenticates with the same credentials the suite will use, and fails with our
   own message rather than PHPUnit reporting a connection error once per test;
3. `TestDatabase` raises — rather than skipping — when a *configured* engine has no driver or
   cannot be opened;
4. `phpunit --fail-on-skipped` turns any skip in the group, for any reason, into a red leg.

The unconfigured run keeps its skip: a bare checkout without `pdo_sqlite` behaved that way before
this ADR and nothing here claims otherwise.

### `attempt()`, and why a `try`/`catch` belongs in an injection suite

The injection corpora contain values a real server will not store — a NUL byte, the GBK quote
sequence (`\xbf\x27`), which is not valid UTF-8 — and hostile strings that reach an **integer**
column, which PostgreSQL rejects outright where SQLite and MySQL coerce. In every one of those
cases the driver was handed a placeholder-only statement and a bound parameter *and then said no*.
That refusal is not a failure of the property under test; it happens strictly after it.

`attempt()` therefore runs such an operation, tolerates a `DatabaseException`, and lets the
boundary assertion run either way. Two things keep it from being a swallow: **on the default
engine it rethrows**, so SQLite's proof is exactly as strong as it was and any new refusal there is
a regression; and the assertion that follows is not vacuous — it fails if the query log is empty or
if the payload is not among the bound values. Where the assertion is about *consequences* rather
than syntax, the refusal branch asserts the refusal was clean: no row written, the neighbouring
table untouched.

### One pinned collation, and one test for the collation

Fixture text columns ask MySQL for `utf8mb4_bin`. MySQL 8's server default,
`utf8mb4_0900_ai_ci`, compares case- and accent-insensitively, so `WHERE name = 'ada'` finds a
stored `'Ada'` there and nowhere else. Left unpinned, that difference would surface as dozens of
unexplained failures in suites whose subject is injection binding and DTO projection. It is
therefore pinned in the schema **and** asserted on its own, against a table built with the
engine's default type, in `DialectTest`.

## Alternatives Considered

- **Three more cells in the `build` matrix.** Rejected: a service container is a per-job
  construct, and the ordinary matrix has to stay fast and dependency-free — it is the job that
  gates every PR.
- **Two jobs, one per engine.** Rejected: the steps are identical, and both images ignore the
  other's environment variables, so one matrix-templated service definition serves both without
  duplicating a ninety-line job.
- **Skip the leg when the engine is unreachable.** Rejected — this is the failure the issue was
  written about. See *Fail, never skip*.
- **A general schema builder in the test tree.** Rejected: it would be a second, untested product
  living in `src/test`. Three column shapes cover every converted suite.
- **Mock the dialect differences.** Rejected on the same grounds every suite in these two groups
  already gives: the claims are about what a database *did*, and a double returns what it was told
  to.
- **Add SQL Server and Oracle too.** Rejected for now. `Identifier` has a `sqlsrv`/`dblib`/`mssql`
  arm that stays unexecuted, and that is worth recording as a known limitation rather than paying
  two more container images to close in the same PR.

## Consequences

- **No public API change.** Everything added lives under `src/test`; the library's own surface is
  untouched, and a consumer's `composer require` is unaffected.
- **The default developer experience is unchanged.** With no DSN set, `vendor/bin/phpunit` opens
  `sqlite::memory:` and the suite runs exactly as it did — same tests, same speed.
- **CI grows two jobs**, each ~2–4 minutes including container start. They do not block the
  existing matrix.
- **`Identifier::forDriver()`'s MySQL and PostgreSQL arms are now executed**, and
  `Transaction`'s savepoint statements are parsed by all three engines rather than one.
- **ADR-0044's "SQLite-only" blind spot is now a measurement.** `TableGatewayTest` asserts both
  arms: strict hydration catches the DTO/table mismatch on SQLite, and the driver rejects the
  unknown identifier at prepare time on MySQL and PostgreSQL. The cost argument ADR-0044 makes for
  not doing a schema round trip applies, it turns out, to exactly one engine.
- **Known limitation, stated rather than implied.** The leg covers three of the four arms of
  `Identifier`'s `match`. SQL Server and Oracle remain unexecuted, and no test in this repository
  should be read as evidence about them.
- **A second known limitation:** the leg proves the *library* against these engines at the
  versions pinned in the workflow. It is not a compatibility matrix across server versions, and a
  divergence introduced by, say, MySQL 9 would be found when the image is bumped and not before.

## References

- Issue [#110](https://github.com/danielPoloWork/egl-util-php/issues/110) — the acceptance criteria
  this ADR implements, from the 2026-08-09 release review board (seat: SDET Lead).
- [ADR-0017](0017-prove-binding-at-the-pdo-boundary-and-defer-t02s-like-leg.md) — why the PDO boundary is the right place
  to prove binding, which is what makes T-02 and T-13 portable across engines at all.
- [ADR-0014](0014-pin-pdo-defaults-on-a-consumer-owned-connection.md) — `ATTR_EMULATE_PREPARES`
  pinned off, without which "placeholder-only text at the boundary" would not imply placeholder-only
  text on the wire.
- [ADR-0044](0044-the-write-builder-querybuilder-never-had-and-one-allowlist-for-both.md) — the
  unknown-column blind spot this leg measured.
- `src/test/php/d4np/utils/Engine/DialectTest.php` — the divergences, each with the reason it is
  pinned.
