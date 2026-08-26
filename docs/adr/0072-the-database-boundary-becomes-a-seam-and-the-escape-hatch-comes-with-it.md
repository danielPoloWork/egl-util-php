# ADR-0072: The database boundary becomes a seam, and the escape hatch comes with it

- **Status:** Accepted
- **Date:** 2026-08-25
- **Deciders:** project architect (agent), maintainer
- **Related:** issue [#113](https://github.com/danielPoloWork/egl-util-php/issues/113) ·
  [ADR-0006](0006-shared-reflection-metadata-cache.md) (the deferral this cashes in) ·
  [ADR-0004](0004-root-the-exception-hierarchy-on-an-interface.md) (the asymmetry ADR-0006 drew) ·
  [ADR-0014](0014-pin-pdo-defaults-on-a-consumer-owned-connection.md) (what the concrete class
  guarantees, and the interface does not) ·
  [ADR-0016](0016-closure-scoped-transactions-with-savepoint-nesting.md) (why `pdo()` is on the
  interface) · [ADR-0026](0026-session-hardening-as-a-value-and-csrf-through-a-seam.md)
  (the seam precedent) · [ADR-0059](0059-freeze-the-api-at-1-0-0-with-internal-symbols-outside-the-frozen-surface.md)
  (the freeze this lands inside) · spec FR-06, FR-34–36

## Context

`DatabaseConnection` was `final` with no interface, and `Repository`, `TableGateway`,
`QueryBuilder`, `MutationBuilder` and `Transaction` all named the concrete class in their
signatures. Every other I/O boundary in the library had a seam — `Transport` for the network,
`SessionApi` for PHP's session functions, `MailApi` for `mail()`, `RateLimitStore` for shared
limiter state — and each was justified the same way (ADR-0026): a unit test cannot observe the
guarantee by performing the real effect.

The database was the exception, and it is the boundary consumers write the most code against. A
consumer unit-testing their own `Repository` subclass had two options, both bad: run a real SQLite
database inside a unit test, or reach into the object with reflection. The review board's point was
about timing rather than purity — those workarounds get written into consumer suites and get harder
to walk back every month.

**This repository had already decided how to think about it, and deferred.** ADR-0006 declined to
give the reflection cache an interface, and the argument it gave was not that interfaces are bad:

> Extracting an interface from a concrete collaborator is a *non-breaking, additive* refactor —
> existing consumers keep working unchanged. There is no one-way door, so there is nothing to buy
> insurance against, **and no consumer exists yet to tell us what the interface should say**.

Issue #113 is a consumer saying what it should say. Nothing in ADR-0006 is contradicted; the
condition it named has been met.

## Decision

**Extract `D4np\Utils\Database\Connection` from `DatabaseConnection`'s entire public surface —
`pdo()`, `driver()`, `select()`, `selectOne()`, `execute()` — have the class implement it, and
widen every parameter in the library that named the class to name the interface instead.**

Three details carry the weight.

### 1. `pdo()` is on the interface, and that is the decision, not an oversight

Its own docblock calls it *"an escape hatch, and deliberately one"*, and an escape hatch is by
definition the door out of an abstraction rather than part of it. Excluding it is the tidier
instinct. It was rejected on a mechanical fact: `Repository::__construct()` builds a
`Transaction`, `Transaction` is built on PDO's own verbs — `inTransaction()`, `beginTransaction()`,
`commit()`, `rollBack()`, and ADR-0016's `SAVEPOINT` statements — so **whatever type a repository
accepts must be transaction-capable**. A `Connection` a `Repository` could not open a transaction
on would be a seam that does not fit the class it was extracted from.

The cost is bounded, and it was measured rather than assumed: **nothing on a read or write path
calls `pdo()`.** `QueryBuilder` and `MutationBuilder` need only `driver()`; `Repository` and
`TableGateway` need only `select()`, `selectOne()`, `execute()` and `driver()`.
`Transaction::__construct()` merely stores its argument, so constructing a repository with a fake
touches nothing PDO-shaped. A fake may therefore implement `pdo()` by throwing —
`ConnectionSeamTest` drives the whole library through one that does, precisely so the claim is a
test rather than a paragraph — and `createMock(Connection::class)` needs no help at all, because
`PDO` is not `final`.

### 2. `Transaction` becomes generic over its connection, to keep the change additive in *static* analysis too

Widening `Transaction::__construct()` to `Connection` would leave `run()` advertising
`callable(Connection): T`. A consumer whose closure declares `function (DatabaseConnection $db)`
would then fail *their* PHPStan run — contravariance — on a change whose entire premise is that
nothing broke. Runtime is unaffected, and Roave cannot see docblocks, so **the BC gate would have
reported green on a real incompatibility.**

This was not predicted and then assumed; it was observed. Widening the type broke this repository's
own `TransactionTest`, whose closure is typed exactly the way a consumer's would be. A class-level
`@template TConnection of Connection` carries the caller's own type through, so
`new Transaction($databaseConnection)` still hands the closure a `DatabaseConnection`.

### 3. Named `Connection`, not `ConnectionInterface`

Issue #113's title says `ConnectionInterface`. The repository has nine interfaces and not one
carries the suffix: `Transport`, `MailApi`, `Mailer`, `SessionApi`, `SessionStore`,
`RateLimitStore`, `Sleeper`, `CsvSerializable`, `UtilsThrowable`. Following the issue's wording
literally would make this the only one, so the house convention wins over the shorthand in a
sentence. `final class DatabaseConnection implements Connection` reads as the relationship it is.

### What the BC report says, and why it is not the last word

Issue #113's second criterion is *"BC gate proves zero breaks"*. It does not, and the reason is
worth recording because it is a limitation of the tool rather than a property of this change.

The per-PR report added by issue #112 ran on this pull request against the frozen `v1.0.0` surface
and returned **11 findings** (run 32829039455). Ten of them are one sentence repeated:

> The parameter `$connection` of `…#__construct()` changed from
> `D4np\Utils\Database\DatabaseConnection` to a **non-contravariant**
> `D4np\Utils\Database\Connection`

That claim is false, and Roave is not being careless — it is being literal. It compares the v1.0.0
tree against this one, and **in the v1.0.0 tree `DatabaseConnection` implements nothing**. Widening
a parameter to a supertype is contravariant and safe; Roave cannot see that the supertype is one
the same release gives the class. There is no way to arrange the change so it can: an extracted
interface is always new in the version that extracts it.

So the safety is proved the only way it can be — by call sites.
`ConnectionSeamTest::testEveryCallShapeWrittenAgainstTheConcreteClassStillWorks()` constructs all
five widened surfaces exactly the way code written against v1.0.0 does, and the entire pre-existing
suite (3 100-odd tests, unmodified in this PR) is a corpus of v1.0.0-shaped call sites that still
pass.

**The eleventh finding is real, and narrow.** `Repository::$connection` is `protected readonly`,
and its declared type widened. `readonly` means no subclass can assign to it, and a subclass
reading it and calling any of the five methods is unaffected — but a subclass that *re-exposes* it,
`function connection(): DatabaseConnection { return $this->connection; }`, now returns a value the
declaration no longer promises. That is a real incompatibility for a real, if unusual, subclass, and
it is named here rather than absorbed into the ten.

What this leaves for the reviewer is a judgement, not a rubber stamp: ship an additive seam whose
one true break is a subclass re-exposing a protected property, or hold it for a MAJOR. ADR-0059's
freeze makes that the maintainer's call.

## Alternatives Considered

- **Two interfaces — a narrow `Connection` (statements only) and a `PdoConnection extends
  Connection` adding `pdo()`.** The purist answer, and genuinely attractive: it would let a fake
  omit the escape hatch entirely. Rejected because `Repository` builds its `Transaction` in the
  constructor, so a repository would have to require the wider one anyway — leaving today's
  situation with an extra name. Making the transaction lazy would fix that, at the price of
  converting a *type* error ("this connection cannot transact") into a *runtime* one thrown from
  inside `withTransaction()`, which is the worst moment to discover it. The split remains available
  later and additively: a narrower interface that `Connection` extends breaks nothing.
- **Leave `Transaction` on the concrete class.** Rejected: `Repository` constructs one from
  whatever it was handed, so this simply relocates the constraint to a place where it reads as an
  accident.
- **`ConnectionInterface`, per the issue's wording.** Rejected in §3 — nine to nothing on house
  convention.
- **Un-`final` `DatabaseConnection` instead of extracting an interface.** Rejected outright:
  subclassing an implementation to double it inherits the pinning logic, the constructor, and every
  future change to both. `final` plus an interface is the substitutable arrangement; `final` is
  what stops a consumer depending on internals ADR-0014 needs to be able to change.
- **A `NullConnection` or an in-memory implementation shipped in the library.** Rejected for now:
  the useful fake is the one shaped like the caller's own fixtures, and shipping one invites it to
  grow into a second, untested persistence engine inside a utility library. The interface is the
  deliverable; `PdolessConnection` lives in the test tree where a fixture belongs.

## Consequences

- **Purely additive.** Every existing signature still accepts everything it accepted before; a
  widened parameter refuses nothing it used to take. `DatabaseConnection` stays `final`, keeps its
  constructor, and pins the same four defaults.
- **The one thing that moved and is worth naming:** `Repository::$connection` is `protected` and its
  declared type widened from `DatabaseConnection` to `Connection`. `readonly` means no subclass can
  assign to it, and a subclass reading it and calling any of the five methods is unaffected — but a
  subclass that *re-exposes* it under a `DatabaseConnection` declaration would need to narrow. This
  is the single real finding out of the eleven the BC report returned; see *What the BC report says*
  above for the other ten and why they are modelling artefacts.
- **A guarantee does not travel through the interface, and the docblocks say so.** Real prepares,
  `ERRMODE_EXCEPTION`, `SET NAMES utf8mb4` (ADR-0014) are properties of `DatabaseConnection` pinning
  them on a PDO. An arbitrary `Connection` promises none of them, which is exactly why spec §7's
  T-02 and T-13 injection suites run against a real engine and always will (ADR-0017, ADR-0071):
  a proof about binding cannot be written against a double that returns what it was told to.
- **`ConnectionSeamTest` is the acceptance evidence**, not a coverage top-up: it drives `Repository`
  and `TableGateway` through a connection with no database behind it, renders the same SQL through
  the interface and through a real connection to prove nothing generated moved, and pins the
  boundary of the claim — `withTransaction()` on a PDO-less fake fails, loudly, from the fake.
- **No patterns-catalogue entry.** The three existing seams — `Transport`, `MailApi`, `SessionApi` —
  are not catalogued either, and the taxonomy's nearest name, *Hexagonal (Ports & Adapters)*, would
  be force-fitted onto a single interface extraction. AGENTS.md §8 names that failure mode
  explicitly.

## References

- Issue [#113](https://github.com/danielPoloWork/egl-util-php/issues/113) — the acceptance criteria
  this implements, from the 2026-08-09 release review board (seat: Staff Software Engineer, API
  Review Board).
- [ADR-0006](0006-shared-reflection-metadata-cache.md) §*No interface* — the deferral, and the test
  it set for when to revisit.
- [ADR-0026](0026-session-hardening-as-a-value-and-csrf-through-a-seam.md)
  — the seam argument this repeats for the database.
- `src/test/php/d4np/utils/Database/ConnectionSeamTest.php` and its `PdolessConnection` fixture.
