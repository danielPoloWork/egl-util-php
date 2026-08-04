# ADR-0014: Pin PDO's safe defaults on a consumer-owned connection, and refuse one that will not take them

- **Status:** Accepted
- **Date:** 2026-08-04
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 4.1 (opens Milestone 4) · spec FR-06 · items 4.2 (`QueryBuilder`),
  4.3 (`Transaction`), 4.4 (T-02 injection suite) · [RFC-0001](../rfc/0001-egl-utils-library.md)
  §"Data & schema" · [ADR-0004](0004-root-the-exception-hierarchy-on-an-interface.md)

## Context

Spec FR-06 asks for *"a PDO wrapper with pinned safe defaults — ERRMODE_EXCEPTION, utf8mb4 on
MySQL, real prepares (EMULATE_PREPARES=false), FETCH_ASSOC"*. Two things about that sentence
needed settling before any of it could be written.

**Who owns the connection.** RFC-0001 answers this directly in its "Data & schema" section: *"The
library owns no persistent state: `Database` components wrap **consumer-owned** PDO connections."*
So this is not a connection factory. It takes a `PDO` the caller already built, and the "pinning"
is something done *to* an object someone else owns — which makes the failure modes below matter
more, not less.

**What PDO actually does when a driver disagrees.** Probed before designing, because the answer
turned out to be the crux:

| call | SQLite result |
|---|---|
| `setAttribute(ATTR_EMULATE_PREPARES, false)` | returns **`false`** — no exception |
| `getAttribute(ATTR_EMULATE_PREPARES)` | **throws** `SQLSTATE[IM001] Driver does not support this function` |

`setAttribute()` signalling refusal by return value rather than by throwing is the whole problem.
The natural way to write this code — call `setAttribute()` four times and move on — would let a
**security-relevant default fail silently**, which is precisely the outcome FR-06 exists to
prevent. And the `false` is ambiguous on its own: SQLite returns it because it has no emulation
mode at all (it prepares natively — nothing is wrong), while a driver that *has* the concept and
declined would return the same `false` with client-side interpolation left on.

## Decision

**Pin the defaults in the constructor, verify each one took, and refuse the connection when a
guarantee cannot be made.**

- **Order is load-bearing, not cosmetic.** `ERRMODE_EXCEPTION` is applied **first**, so everything
  after it fails loudly instead of returning `false`. Emulation is turned off **before any SQL is
  sent**. `SET NAMES utf8mb4` (MySQL only) is applied **last**.

  That last ordering is the non-obvious one. `SET NAMES` is the classic *wrong* answer to charset
  configuration **when the client is also escaping**, because the client library's notion of the
  connection charset does not change with it, and a multibyte charset can then be used to smuggle
  a quote past client-side escaping. With emulation already off there is no client-side escaping
  left to fool — values travel to the server as bound parameters, out of band from the SQL text.
  **The two pinned defaults are not independent hardening measures: real prepares is what licenses
  `SET NAMES`.** Reordering them would reintroduce a real vulnerability, so the order is documented
  in the class rather than left to look arbitrary.

- **`false` from `setAttribute()` is disambiguated, not ignored.** For `ATTR_EMULATE_PREPARES`,
  a `false` return is followed by reading the attribute back: if the read **throws**, the driver
  has no emulation concept and all is well; if it **succeeds and reports emulation still on**, the
  connection is refused with a `DatabaseException`. For the other attributes a `false` return is
  refused outright.

- **Refuse rather than degrade.** A connection that will not take a pinned default cannot offer
  the guarantees this class exists to make, so it raises instead of being handed back in a weaker
  state. A silently-weakened security default is worse than a loud failure precisely because
  nothing downstream can detect it.

- **Values are always bound; identifiers are out of scope.** No method here accepts an interpolated
  value, on the reasoning that the method that did would be the one that got used. Identifiers
  cannot be bound at all and are `QueryBuilder`'s problem (item 4.2), where they are allowlisted.

- **`pdo()` is a deliberate escape hatch.** This library does not intend to re-wrap all of PDO;
  `lastInsertId()`, driver-specific attributes and `Transaction`'s savepoints (item 4.3) reach the
  real object, which already carries the pinned defaults.

## Alternatives Considered

- **Open the connection from a DSN** — rejected: RFC-0001 explicitly scopes these components to
  consumer-owned connections, and a factory would drag credential handling into a utility library.
  It remains addable later without a BC break; the reverse would not be true.
- **Set the charset via the DSN (`charset=utf8mb4`) instead of `SET NAMES`** — genuinely the
  better mechanism, and **not available here**: the connection already exists by the time this
  class sees it. Recorded because a reader who knows the DSN form is better deserves to know it
  was considered and why it is unreachable, rather than assuming the weaker form was chosen
  carelessly. It is also the reason the emulation ordering above is documented so heavily.
- **Ignore `setAttribute()`'s return value** (the common idiom) — rejected on the probe: it makes
  a security default fail silently on any driver that declines it.
- **Treat every `false` return as fatal** — rejected because it is wrong in the other direction:
  SQLite would be refused for having nothing to disable. Probed — it fails every SQLite test in the
  suite.
- **Verify emulation by inspecting driver behaviour** (e.g. issuing a statement and checking the
  server's query log) — rejected as far too invasive for a constructor, and impossible without a
  server round-trip.
- **Skip pinning and merely document the recommended attributes** — rejected: FR-06 asks for a
  guarantee, and a guarantee nothing enforces is a README paragraph.

## Consequences

- A `DatabaseConnection` either carries all four guarantees or does not exist. Callers do not have
  to check.
- **The most security-relevant branch is testable and tested.** No driver reachable from this suite
  behaves like a stubborn emulator — SQLite has no emulation mode, MySQL honours the attribute — so
  the refusal path would otherwise have been present, plausible and never executed.
  `StubbornlyEmulatingPdo`, a real `PDO` subclass overriding exactly two attribute calls, exists to
  run it.
- **`SET NAMES utf8mb4` remains unexecuted by the suite**, because it is MySQL-only and no MySQL
  server is available in CI. What *is* tested is the driver dispatch around it. This is stated
  plainly rather than papered over; T-02 (item 4.4) is where a real driver matrix belongs.
- The constructor mutates a `PDO` the caller owns. That is inherent — PDO attributes are connection
  state, not wrapper state — and is documented, but it does mean a caller sharing one `PDO` between
  a `DatabaseConnection` and other code will see these settings there too. That is the intent.
- Pinning happens once per wrapper construction, not per query, so it costs nothing on the hot path.
- Writing the failing SQL into the exception message was deliberately **not** done: a failing
  statement's text is the most likely place for data that should not reach a log. The driver's own
  message and SQLSTATE survive as `getPrevious()`.

## References

- Spec FR-06 (the four pinned defaults), FR-07 (identifiers, `QueryBuilder`'s problem)
- RFC-0001 §"Data & schema" — components wrap consumer-owned connections
- ADR-0004 — why a driver failure surfaces as `DatabaseException` rather than a bare `PDOException`
- Probed directly: `PDO::setAttribute()` return-value semantics and `getAttribute()`'s
  `SQLSTATE[IM001]` on an unsupported attribute (PHP 8.3.1, `pdo_sqlite`)
