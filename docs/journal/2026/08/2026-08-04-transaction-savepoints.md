# 2026-08-04 — Transactions: three PDO probes that each decided a design

Roadmap item **4.3**. Route `standard / medium`; session is Opus 5, the standard tier — match, for
the first time in three items.

## Probing before designing

Three questions, each of which turned out to determine part of the shape:

| probe | result | what it decided |
|---|---|---|
| `beginTransaction()` while one is open | **throws** `There is already an active transaction` | savepoints aren't an optimisation, they're the only mechanism |
| is a savepoint still defined after `ROLLBACK TO`? | **yes** — a later `RELEASE` succeeds | the failure path must release too, or the stack grows per failed call |
| `rollBack()` with no active transaction | **throws** `There is no active transaction` | cleanup can't be fired blindly |

The first is the important one. I'd assumed PDO would either nest or quietly no-op; it does
neither. So "nested calls use savepoints" in FR-08 isn't a performance note — without it, nesting
is an immediate fatal.

## Decisions worth their own line

**`Throwable`, not `Exception`.** A `TypeError` leaves exactly the same half-written state as a
`RuntimeException`. Committing it because it descends from `Error` would be indefensible.

**A failing rollback must not replace the original exception.** If cleanup fails — dropped
connection, usually — letting that propagate hands the caller a `PDOException` about a lost
connection *instead of* the `TypeError` that actually caused the problem. Wrong end of the causal
chain, and the end they can't act on. So the rollback's failure is swallowed and the original is
rethrown.

That cost is real and I named it rather than hid it: a failed rollback leaves the connection in a
state this class didn't intend and doesn't report. PHP has no suppressed-exception mechanism, so
the choice is strictly between losing the cause and losing the cleanup failure. Losing the cleanup
failure keeps the actionable one.

**Savepoint names are generated, never accepted.** A savepoint name is an identifier spliced into
SQL — the exact thing ADR-0015 spent an item on. But the right answer here isn't an allowlist; it's
that no caller can influence it at all. Monotonic counter, not a depth index, so two savepoints
can't collide even if scopes unwind in an order I didn't anticipate.

**A caveat no wrapper can fix:** on MySQL, DDL causes an *implicit commit*, ending the transaction
underneath the closure. Later work isn't covered and a rollback won't undo what the DDL committed.
Documented in the class because the failure is silent and the natural assumption is that a closure
is atomic throughout.

## Where PHPStan's advice would have quietly cost coverage

PHPStan max flagged six issues, all in the test file. Five were `self::fail('expected the exception
to propagate')` after a closure that throws unconditionally — provably unreachable, and PHPStan was
right.

I removed them. That's the obvious fix, and it silently **weakened two tests**: the ones whose only
assertion lived inside the `catch`. With `self::fail()` gone, if `run()` ever swallowed the
exception the catch would never run, the assertion would never execute, and the test would pass.

Caught it by asking what each test would do under the regression it exists to catch, then confirmed
it — planting a swallowing `run()` and watching those tests stay green. Restructured both to
capture the exception and assert *outside* the try/catch, so the assertion always runs. Re-planted
the same defect: 2 failures now.

Worth recording as a pattern: **the safe-looking fix for a static-analysis complaint was a real
reduction in coverage.** "PHPStan is happy" and "the test still tests something" are different
properties.

## Non-vacuity

| planted | result |
|---|---|
| nesting via `beginTransaction()` instead of savepoints | 3 errors |
| catches `Exception` instead of `Throwable` | 1 failure |
| nested rollback discards the outer transaction | 3 errors |
| failing rollback masks the original exception | 1 failure |
| `run()` swallows the exception (post-restructure) | 2 failures |

The identity assertion is deliberately `assertSame`, not `assertInstanceOf`: the caller must get
back the very object the closure threw, not a wrapper that lost its type or context.

## Bar

391 tests / 644 assertions green; `--group T-04` runs 17 as a unit, which is spec §7's named suite
made runnable. PHPStan max clean, deptrac 0/0 with 73 allowed edges, consistency lint OK.

## State of Milestone 4

4.1–4.3 done. Remaining: **4.4** (T-02 injection + T-04 transaction suites — routed
`frontier-reasoning / extra`, above this session's tier) and **4.5** (NFR-03 build-time benchmark).

Much of 4.4 already exists: `--group T-02` runs 116 tests and `--group T-04` 17. What 4.4 still
owes is the part neither item could do — the fuzzed-payload query-log assertion, and a real driver
matrix instead of SQLite standing in for MySQL.
