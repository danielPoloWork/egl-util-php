# 2026-08-04 — Measuring what the old tests would have missed

Roadmap item **4.4**. Route `frontier-reasoning / extra`; session is standard tier. Flagged when
closing 4.3, maintainer said proceed — accepted and recorded via `record_run.py`.

## The clause I nearly treated as already-done

Spec §7's T-02 has three legs:

> fuzzed value payloads reach the driver only as bound parameters **via query-log assertion**;
> identifier injection throws `DatabaseException`; **LIKE-wildcard escapes**

Items 4.1–4.3 already had tests that *looked* like leg 1: a hostile value round-trips intact, the
table survives. It would have been easy to call that done and tick the box.

It isn't done, and the reason is precise: **round-tripping intact is equally consistent with
correct client-side escaping.** An escaped value also survives and also leaves the schema alone.
The test passes either way, so it cannot tell the mechanism the security model depends on from a
different mechanism that merely resembles it.

So rather than argue that, I measured it. Planted a *correctly quote-escaping* interpolation in
`DatabaseConnection::run()` — a real vulnerability that behaves well on every visible axis — and
ran the two kinds of assertion against it:

| assertion | under the planted vulnerability |
|---|---|
| round-trip + schema survives (4.1–4.3) | **28 of 29 passed** — misses it |
| query-log assertion (this item) | **28 of 29 failed** — catches it |

That's why the spec says *query-log* and not "assert the value came back". It's the difference
between testing an outcome and testing the mechanism.

## Why the PDO boundary is enough

`PDO::ATTR_STATEMENT_CLASS` lets a custom `PDOStatement` record the exact SQL text and the exact
bound array while still executing for real. Every statement the library prepares passes through it
without `DatabaseConnection`, `QueryBuilder` or `Transaction` knowing.

The obvious objection: that's PDO's *input*, not the bytes on the wire. The answer that actually
carries weight isn't "it's the best available" — it's **ADR-0014's pinned
`ATTR_EMULATE_PREPARES=false`**. With real prepares PDO performs no interpolation at all; statement
and parameters travel separately by construction. So placeholder-only text at that boundary *is*
placeholder-only text on the wire. And if emulation were ever silently re-enabled,
`DatabaseConnectionTest` fails first — which makes the chain checkable rather than merely
plausible.

Covered every value-accepting path, because a binding guarantee is worth exactly what its leakiest
entry point is worth: three `DatabaseConnection` methods, `where`, `whereIn`, and the same inside a
transaction.

## The leg I could not deliver, and did not paper over

T-02's third leg is LIKE-wildcard escaping. The mechanism is `Sanitizer::sqlLikePattern()` — spec
FR-10, **roadmap item 5.2**, Milestone 5. It does not exist. Building it here would jump a
milestone and design a security helper under the wrong item's review.

What ships instead is a test that states the truth:
`testLikePatternsStillBindButWildcardsAreNotYetEscaped()`. It asserts what *is* true — a `LIKE`
value binds and cannot inject SQL — and then asserts the gap: a user-supplied `%` still matches
everything. The message names 5.2 as owner and says which assertion should flip when it lands.

An untested gap and a gap with a test documenting it look identical in a coverage report and
completely different to whoever reads the suite next. **T-02 is not complete at the end of 4.4**,
and the roadmap entry says so instead of ticking a box the spec hasn't earned.

## T-04 needed nothing, so I added nothing

Spec T-04 is "exception → rollback → rethrow; savepoint nesting". Item 4.3 delivered exactly that,
grouped, 17 tests. I checked it against the spec text and left it alone. Padding the item with
redundant tests would have made it look busier and covered nothing new — the same judgement item
2.6 and 3.4 made when their named suites turned out to be mostly already-satisfied.

## Corpus notes

Aimed at mechanisms, not scary strings: quote break-out, comment truncation, stacked statements,
UNION exfiltration, backslash tricks, null bytes, CRLF, Unicode lookalikes, a 5.5 KB payload — and
the **GBK multibyte quote** (`0xBF 0x27`), a valid GBK character whose second byte is a quote,
which defeats a charset-unaware escaper. That's the exact attack ADR-0014's ordering removes, so it
belongs in the corpus that proves the removal.

One honest wrinkle: the empty-string payload skips the containment half of the assertion, because
every string contains the empty string and the check can never hold. It stays for the binding half,
and the skip is explained where it happens rather than the payload being quietly dropped from the
provider.

## Bar

596 tests / 1253 assertions (up from 391). `--group T-02` runs 321, `--group T-04` 17. PHPStan max
clean, deptrac 0/0, consistency lint OK.

## State of Milestone 4

4.1–4.4 done. Remaining: **4.5** (phpbench NFR-03 build-time benchmark, `fast / medium`).

Carried forward, both named in ADRs rather than lost: T-02's LIKE leg awaits item **5.2**, and the
MySQL-only behaviours (`SET NAMES utf8mb4` against a real server, real-prepares honoured by a real
driver) still have no real driver behind them — a CI service container would close both, and that
is a build-infrastructure decision rather than a test one.
