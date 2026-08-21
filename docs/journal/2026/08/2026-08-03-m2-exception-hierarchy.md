# 2026-08-03 — Milestone 2 opens: the exception hierarchy

First item of Milestone 2, and the first code in this repository with behaviour rather than
configuration. Roadmap item **2.1**, flagged `sets-pattern`: it is the first of its class, so
the shape it lands is the shape the five remaining component groups copy.

## Route

`os/routing` resolves `sets-pattern` to **frontier-reasoning / high**;
`route_advice.py --check` reported the session model (`opus-5`, standard tier) below that route.
Surfaced to the maintainer before starting — they hold model authority (ADR-0017) — and work
proceeded on their standing decision.

## What landed

Ten files under `src/main/php/d4np/utils/Support/`, and **[ADR-0004](../../../adr/0004-root-the-exception-hierarchy-on-an-interface.md)**
for the four mechanics RFC-0001 deliberately left open:

- **An interface root, `UtilsThrowable extends \Throwable`**, alongside the concrete
  `UtilsException extends \RuntimeException`. The RFC's arrow notation literally implies a class
  root; that is a one-way door. An exception that later must extend some other base for
  interoperability can still join the family by implementing the interface, so the "one thing to
  catch" contract survives a change no class hierarchy could absorb. The cost is one file with
  an empty body.
- **`RuntimeException`, not `Exception` or `LogicException`** — everything here is detected at
  run time from data the library did not control.
- **Leaves `final`, `UtilsException` and `HydrationException` open.** The hierarchy's shape is a
  MAJOR surface (RFC-0001); a subclass of a leaf would widen it silently, so the extension points
  are explicit instead of incidental.
- **`HydrationException` takes `$path` where SPL takes `$code`.** No consumer will branch on an
  integer code, while the path (`address.postcode`) is the one fact that makes a nested-DTO
  failure diagnosable. PHP exempts constructors from signature compatibility; recorded in the
  ADR because it is a trade, not an oversight.

## The test that carries the weight

`ExceptionHierarchyTest` discovers the exception classes **from disk**, not from a hand-written
list, and asserts each implements `UtilsThrowable`. A member added later that forgets the
contract fails here rather than reaching a consumer's `catch` block and being silently missed.
Proved non-vacuous: planted a `RogueException extends \RuntimeException`, watched both the
contract test and the pinned-set test fail with actionable messages naming the ADR, removed it,
watched them pass. 14 tests, 61 assertions.

The suite also pins the *negative* direction — a `catch (HydrationException)` must not swallow a
database or HTTP failure — and derives that set from disk too, deliberately: a hard-coded list of
class literals is something PHPStan can decide statically, which would make the assertion a
tautology dressed as a test.

## PHPStan max earned its keep

Four findings, all in the test file, all fixed at the cause rather than silenced:

1. `is_a()` over hard-coded literals — statically decidable, so the assertion proved nothing.
   Rewritten to derive the class set at runtime.
2. and 3. `forKey()` declares `class-string` and the test passed `'App\Dto\UserDto'`, which
   resolves to no class. The production type is right (the hydrator passes `$target::class`); the
   test now passes a real one.
4. `assertNotInstanceOf(NativeJsonException::class, $wrapped)` — already-narrowed. Dropped, with
   a comment recording why: PHPStan at max decides that disjointness statically for *every* call
   site, permanently, which is a strictly stronger guarantee than one runtime assertion.

## No patterns-catalogue row, on purpose

The static named constructors (`UnknownKeyException::forKey()`, `TypeMismatchException::at()`)
are the **named-constructor idiom**, not GoF **Factory Method** — which is about deferring
instantiation so a subclass picks the concrete type. `docs/patterns/design-patterns.md` lists
only the latter, and filing this under it to have something to file is exactly the force-fit
`AGENTS.md` §8 forbids. Recorded in ADR-0004 so the empty catalogue is a decision, not a gap.

## Filed: the coverage floor is stated but ungated (item 2.7)

`AGENTS.md` §10 and spec NFR-07 both require **≥ 90% line coverage**. Nothing checks it: the
`build` job sets up `pcov` but runs `vendor/bin/phpunit` with no `--coverage` flag and no
threshold, so the number is neither produced nor compared. Found because this item could not
verify its own coverage claim — no driver locally, no gate in CI. PHPUnit 10 has no fail-under
option, so it needs a clover report plus a threshold check; that is real work, not a flag, and it
is filed as **2.7** rather than bolted onto this PR.

What *is* verifiable and stated instead: every method with a body in this change is exercised by
a test — the three named constructors, `HydrationException::path()` including its empty default,
and `JsonException::wrap()`. The empty leaf classes have no executable lines.

## Next

- **2.2 `Str`** (`slug()` with ext-intl fallback, `uuid()` v4, `random()`) — first real
  algorithms, and the first place CSPRNG discipline matters.
- **2.7** whenever convenient; it turns a written quality bar into a checked one, the same shape
  as items 1.10 → 1.11.
- One-time admin still open: branch protection and the label import
  ([`github-setup.md`](../../../workflow/github-setup.md)).
