# ADR-0016: Closure-scoped transactions, savepoint nesting, and keeping the original exception

- **Status:** Accepted
- **Date:** 2026-08-04
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 4.3 · spec FR-08, §7 T-04 · item 4.4 (T-04 suite) ·
  [ADR-0014](0014-pin-pdo-defaults-on-a-consumer-owned-connection.md) ·
  [ADR-0015](0015-identifier-allowlist-closed-keywords-and-the-anchor-hole.md) ·
  [ADR-0004](0004-root-the-exception-hierarchy-on-an-interface.md)

## Context

Spec FR-08 asks for *"closure-scoped transactions — automatic rollback on any exception and
rethrow; nested calls use savepoints."*

Three facts about PDO were probed before designing, and each one determined part of the shape:

| probe | result |
|---|---|
| `beginTransaction()` while one is already open | **throws** `PDOException: There is already an active transaction` |
| `ROLLBACK TO SAVEPOINT sp` — is `sp` still defined afterwards? | **yes** — a subsequent `RELEASE` succeeds |
| `rollBack()` with no active transaction | **throws** `PDOException: There is no active transaction` |

The first is why savepoints are not an optimisation but the only mechanism available: PDO does not
nest, and does not quietly no-op either. The second determines cleanup: rolling back to a savepoint
leaves it on the stack, so a nested scope that fails must also release it or the stack grows once
per failed call.

## Decision

**One closure, one scope. Commit on return; roll back and rethrow on any `Throwable`. Nest with
savepoints.**

### The closure is the point, not a convenience

Hand-written `beginTransaction()` … `commit()` leaks an open transaction down every path that
returns or throws in between, and the leak surfaces later, elsewhere, as a lock nobody can
explain. A closure has exactly one exit, so there is one place to put the cleanup and no way for a
caller to forget it.

### `Throwable`, not `Exception`

A `TypeError` inside the closure leaves exactly the same half-written state as a
`RuntimeException`. Committing it because it descends from `Error` rather than `Exception` would be
indefensible, so the catch is `Throwable`.

### Nesting uses savepoints, and releases them on both paths

A nested `run()` opens `SAVEPOINT egl_sp_N`; failure means `ROLLBACK TO SAVEPOINT` — which undoes
the inner work and leaves the outer transaction open and intact — followed by `RELEASE`, because
the probe showed the savepoint survives the rollback.

**Savepoint names are generated, never accepted.** A savepoint name is an identifier spliced into
SQL, and this group has already decided how identifiers are handled (ADR-0015). The honest way to
make this one safe is not to allowlist it but to ensure no caller can influence it at all: a
process-wide monotonic counter, not a caller-supplied label and not a depth index — monotonic so
two savepoints cannot collide even if nesting unwinds in an order this class did not anticipate.

### A failing rollback must not replace the original exception

If cleanup fails — a dropped connection being the usual cause — letting that failure propagate
would hand the caller a `PDOException` about a lost connection *in place of* the `TypeError` that
actually caused the problem. That is the wrong end of the causal chain, and the end they cannot act
on.

So the rollback's own failure is swallowed and the closure's exception is rethrown. **The cost is
real and is named rather than hidden:** a failed rollback leaves the connection in a state this
class did not intend and does not report. PHP has no equivalent of Java's suppressed exceptions, so
the choice is strictly between losing the original cause and losing the cleanup failure. Losing the
cleanup failure keeps the actionable one.

### A caveat this class cannot fix

On MySQL, DDL (`CREATE TABLE`, `ALTER`, `DROP`, …) causes an **implicit commit**, ending the
transaction underneath the closure. Work issued after that point is not covered, and a later
rollback will not undo what the DDL already committed. No wrapper can intercept this — it is a
MySQL behaviour, not a PDO or library one. It is documented in the class because the failure is
silent and the natural assumption is that the closure is atomic throughout.

## Alternatives Considered

- **A depth counter instead of `inTransaction()`** — rejected: the connection is consumer-owned
  (ADR-0014), so a transaction may have been opened outside this class entirely. Asking PDO is the
  only answer that is true in that case; a private counter would confidently open a second
  transaction and hit the error the first probe found.
- **Emulating nested transactions by counting and committing once at depth zero** — rejected. It
  gives the *appearance* of nesting while a failed inner scope silently commits with the outer one,
  which is worse than not nesting: the caller believes work was rolled back when it was not.
- **Naming savepoints by nesting depth (`sp_1`, `sp_2`)** — rejected in favour of a monotonic
  counter. Depth names collide if scopes unwind out of order, and the failure mode is releasing or
  rolling back to the wrong scope's savepoint.
- **Letting a failing rollback propagate** — rejected above; it destroys the causal chain.
- **Wrapping the closure's exception in a `DatabaseException`** — rejected: FR-08 says *rethrow*,
  and a caller catching their own domain exception around a transaction is the normal case. The
  test asserts object **identity**, not just class, so a wrapper would fail it.
- **`try`/`finally` with a committed flag** instead of explicit catch/rethrow — equivalent for the
  happy path, but it makes "roll back only on failure" implicit and would have obscured the
  rollback-must-not-mask decision above.

## Consequences

- An open transaction cannot outlive a `run()` call on either path, which is asserted directly
  (`inTransaction()` is false after both a successful and a failed run).
- A handled inner failure undoes only the inner work — the case FR-08's savepoint clause exists
  for, and the one a plain `rollBack()` would get wrong by discarding the outer scope too.
- Repeated failed nested calls do not grow the savepoint stack, because the failure path releases
  as well as rolls back.
- **The suite is verified non-vacuous.** Four planted defects, each caught and reverted: nesting via
  `beginTransaction()` instead of savepoints (3 errors), catching `Exception` instead of `Throwable`
  (1), a nested rollback discarding the outer transaction (3 errors), and a failing rollback
  masking the original exception (1). A fifth probe — making `run()` swallow the exception — was
  used after PHPStan forced a test restructure (below), and fails 2.
- **PHPStan max rejected the defensive `self::fail()` after each throwing closure** as provably
  unreachable, which is correct: the closures throw unconditionally. Removing them, however,
  silently weakened the two tests whose only assertion lived inside the `catch` — they would have
  passed vacuously had `run()` ever swallowed. Those now capture the exception and assert
  *outside* the try/catch, so the assertion always runs. Worth recording because the safe-looking
  fix for a static-analysis complaint was a real reduction in coverage.
- No support for isolation levels, read-only transactions, or deferred constraints. FR-08 asks for
  none of them, and each is driver-specific enough to deserve its own decision.

## References

- Spec FR-08 (closure scope, rollback + rethrow, savepoint nesting), §7 T-04 (the suite)
- ADR-0014 — the consumer-owned connection this operates on, and why `inTransaction()` rather than
  private state is the right question to ask
- ADR-0015 — the identifier rule savepoint naming follows by construction rather than by check
- Probed directly (PHP 8.3.1, `pdo_sqlite`): nested `beginTransaction()`, savepoint survival after
  `ROLLBACK TO`, and `rollBack()` outside a transaction
