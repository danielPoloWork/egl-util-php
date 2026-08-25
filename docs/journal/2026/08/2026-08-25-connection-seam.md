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

## The BC report, which is the reason yesterday's item was worth doing

Issue #112's per-PR report — merged yesterday — ran on this pull request against the frozen
`v1.0.0` surface and returned **11 findings**. The acceptance criterion said "BC gate proves zero
breaks". It does not, and working out why was the most useful hour of the item.

Ten of the eleven are the same sentence:

> The parameter `$connection` … changed from `DatabaseConnection` to a **non-contravariant**
> `Connection`

That is false — widening to a supertype is contravariant, which is the safe direction. Roave is
not being careless, it is being **literal**: it compares the v1.0.0 tree against this one, and in
the v1.0.0 tree `DatabaseConnection` implements nothing. It cannot see that the supertype each
parameter widened to is one the same release gives the class. **No arrangement of the change fixes
this** — an extracted interface is always new in the version that extracts it.

So the safety is proved the only way it can be, by call sites: a named test constructing all five
widened surfaces the way v1.0.0 code does, plus 3 100-odd untouched tests that are already a corpus
of exactly those call shapes.

**The eleventh is real and narrow.** `Repository::$connection` is `protected readonly`. No subclass
can assign to it; reading it to call the five methods is unaffected; a subclass that *re-exposes*
it — `function connection(): DatabaseConnection` — must widen that return type.

What I am handing the maintainer is therefore a judgement rather than a green tick: ship an
additive seam whose one true break is a subclass re-exposing a protected property, or hold it for a
MAJOR. That is the right shape for ADR-0059's freeze to be decided under, and it only exists as a
question because the report landed yesterday. Two issues in two days, and the second is already
doing work for the first.
