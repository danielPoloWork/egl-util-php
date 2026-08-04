# 2026-08-04 — Milestone 4 opens: the security default that fails by returning false

Roadmap item **4.1**. Route: the item declares `standard / high`; Opus 5 is the standard tier —
match.

## Two things to settle before writing anything

**Who owns the connection.** RFC-0001 answers it outright in a section I nearly skipped, because
it is titled "Data & schema — omitted": *"the library owns no persistent state: `Database`
components wrap **consumer-owned** PDO connections."* So this is not a connection factory. It pins
settings on an object someone else owns — which makes every failure mode below matter more.

**What PDO does when a driver disagrees.** Probed rather than assumed, and the answer turned out
to be the whole item:

```
setAttribute(ATTR_EMULATE_PREPARES, false)  ->  returns FALSE. No exception.
getAttribute(ATTR_EMULATE_PREPARES)         ->  THROWS SQLSTATE[IM001]
```

`setAttribute()` signalling refusal **by return value** is the crux. The natural way to write this
— four `setAttribute()` calls in a row — would let a security-relevant default fail **silently**,
which is exactly what FR-06 exists to prevent. And the `false` is ambiguous on its own: SQLite
returns it because it has no emulation mode at all (nothing is wrong), while a driver that *has*
the concept and declined returns the identical `false` with client-side interpolation left on.

Reading the attribute back separates them: throws → no such concept → fine; returns true → still
emulating → refuse the connection.

## The ordering is a security property, not tidiness

`SET NAMES utf8mb4` is the long-standing *wrong* answer to charset configuration — but only when
the client is also escaping, because the client library's idea of the connection charset doesn't
change with it and a multibyte charset can smuggle a quote past client-side escaping. With
emulation already off there is no client-side escaping left to fool; values travel as bound
parameters, out of band from the SQL text.

So the two pinned defaults aren't independent hardening measures. **Real prepares is what licenses
`SET NAMES`.** Reordering them reintroduces a real vulnerability. That's now documented in the
class rather than left looking arbitrary — and it's why I recorded, in the ADR, that the *better*
mechanism (`charset=utf8mb4` in the DSN) is genuinely unreachable here: the connection already
exists by the time this class sees it. A reader who knows the DSN form is better deserves to know
it was considered, not to assume carelessness.

## Testing the branch no real driver reaches

The refusal path — "this driver is still emulating, so I will not hand you this connection" — is
the single most security-relevant line in the item. And nothing reachable from the suite executes
it: SQLite has no emulation mode, MySQL honours the attribute, and there's no MySQL in CI.

So `StubbornlyEmulatingPdo`: a real `PDO` on a real SQLite connection, subclassed to override
exactly two attribute calls. Everything else behaves like the genuine article. Without it that
branch would be present, plausible, and never once run.

## A probe that found a real hole in my own test

Standard practice here is to plant each bug a test claims to catch. Four probes:

| planted | caught by |
|---|---|
| don't pin `FETCH_ASSOC` | `testDefaultFetchModeIsPinnedToAssoc` — **but not the row-shape test** |
| don't pin `ERRMODE` | `testErrorModeIsPinnedToExceptions` |
| treat every `false` as fatal | every SQLite test (6 failures) |
| leak the SQL into the message | `testTheFailureMessageDoesNotEchoTheStatement` |

Row one is the useful one. I'd written `testRowsComeBackAsAssociativeArraysOnly` believing it
tested the pin. It doesn't — `select()` passes `FETCH_ASSOC` to `fetchAll()` **explicitly**, so it
stays green with the pin removed. The test was real, but it tested `select()`'s own contract, not
the pinned default, and its name claimed otherwise.

Fixed by renaming it to what it actually covers and adding
`testThePinnedFetchModeAppliesToStatementsRunOnTheRawPdo`, which runs a query through `pdo()` with
no fetch mode passed anywhere — so whatever comes back is the pin's doing. Re-ran the probe: now
two tests fail instead of one. That's the coverage the item was supposed to have.

Worth noting the pattern — this is the second item running where the probe found something I'd
have otherwise shipped believing it was covered.

## Deliberately not done

The failing SQL is **not** in the exception message. A failing statement's text is the likeliest
place for data nobody wants in a log line; the driver's message and SQLSTATE survive as
`getPrevious()`.

## Honest gap

`SET NAMES utf8mb4` is MySQL-only and no MySQL server exists in CI, so that line is unexecuted by
the suite. What *is* tested is the driver dispatch around it. Stated in the ADR, the roadmap note
and here rather than papered over — a real driver matrix belongs to **T-02** (item 4.4).

## Bar

274 tests / 509 assertions green (up from 258). PHPStan max clean. Deptrac 0 violations, 0
uncovered, 52 allowed — the layering gate from item 3.6 now has a second real group (`Database`)
depending downward on `Support`, which is the first time it has constrained anything beyond `Dto`.
cs-fixer flagged my new file for mixed line endings (my own Python rewrites on Windows); fixed.

## Next

**4.2** `QueryBuilder` — identifiers can't be bound, so the allowlist is the only defence there is.
Routed `frontier-reasoning / extra`, above this session's tier.
