# 2026-08-25 — The consumer ADR-0006 was waiting for

Issue **#113**, both criteria. Route `frontier-reasoning / extra`; session model Opus 5 — one tier
below the advisory route, recorded rather than hidden. **ADR-0072** added.

The framing that made this item easy to get right is that the project had already decided how to
think about it. **ADR-0006** declined to give the reflection cache an interface, and its argument
was not "interfaces are bad":

> Extracting an interface from a concrete collaborator is a *non-breaking, additive* refactor …
> There is no one-way door, so there is nothing to buy insurance against, **and no consumer exists
> yet to tell us what the interface should say.**

Issue #113 is a consumer saying what it should say. So this is not a reversal; it is the deferred
half of a decision arriving through the door ADR-0006 predicted.

## The one real design question

`pdo()` describes itself as *"an escape hatch, and deliberately one"*, and an escape hatch is the
door **out** of an abstraction, not part of it. Excluding it from the interface is the tidier
instinct, and I spent most of the design time trying to.

It does not survive contact with one line: `Repository::__construct()` builds a `Transaction`, and
`Transaction` is PDO's own verbs plus ADR-0016's savepoints. So **whatever type a repository
accepts must be transaction-capable**, and a narrow `Connection` would leave `Repository` requiring
the wide one anyway — today's situation with an extra name.

The escape route exists (`PdoConnection extends Connection`, plus a lazily-built `Transaction`) and
I rejected it deliberately: it converts a *type* error — "this connection cannot transact" — into a
*runtime* one thrown from inside `withTransaction()`, which is the worst place to find out. And the
split stays available later, additively, because a narrower interface that `Connection` extends
breaks nothing.

What made including `pdo()` acceptable rather than merely necessary was **measuring the cost
instead of assuming it**: nothing on a read or write path calls it. `QueryBuilder` and
`MutationBuilder` need `driver()`; `Repository` and `TableGateway` need the three statement methods
plus `driver()`. `Transaction::__construct()` only stores. So a read-oriented fake can implement
`pdo()` by throwing — and `ConnectionSeamTest` drives the entire library through exactly such a
fake, so that sentence is a test rather than a paragraph.

## The break Roave could not have seen

Widening `Transaction::__construct()` to the interface left `run()` advertising
`callable(Connection): T`. **That broke this repository's own `TransactionTest`**, whose closure is
typed `static fn (DatabaseConnection $db) => $db` — exactly the way a consumer's would be.
Contravariance, on a change whose entire premise is that nothing broke.

The part worth carrying forward: **runtime was fine, and Roave cannot see docblocks.** The BC gate
would have reported green on a real incompatibility, and the only thing that caught it was PHPStan
running over our own test suite. A class-level `@template TConnection of Connection` carries the
caller's own type through, so `new Transaction($databaseConnection)` still hands the closure a
`DatabaseConnection`. Cost: three annotations.

I did not predict this and then check. I widened the type, PHPStan failed, and the failure taught
me something about the shape of "additive" that the BC gate is structurally unable to.

## Naming, decided against the issue's own words

Issue #113 says `ConnectionInterface`. The repository has nine interfaces and **not one** carries
the suffix — `Transport`, `MailApi`, `Mailer`, `SessionApi`, `SessionStore`, `RateLimitStore`,
`Sleeper`, `CsvSerializable`, `UtilsThrowable`. Following the issue literally would make this the
only one. House convention wins over the shorthand in a sentence, and it is recorded in the ADR so
the divergence is a decision rather than a slip.

## Where this leaves the project

3 190 tests (+8), PHPStan max clean, deptrac 0 violations, cs-fixer clean. No behaviour changed
anywhere: every existing signature accepts everything it accepted before, `DatabaseConnection` is
still `final` with the same constructor and the same four pinned defaults.

One thing to watch on review, and it is why the ADR names it rather than burying it:
`Repository::$connection` is **protected**, and its declared type widened. A subclass reading it
and calling the five methods is unaffected; a subclass that re-stores it under a
`DatabaseConnection` declaration would need to narrow. Issue #112's per-PR BC report — merged
yesterday — runs on this pull request against the frozen `v1.0.0` surface, which is where that
judgement gets checked instead of asserted. Two issues in two days, and the second one is already
doing work for the first.
