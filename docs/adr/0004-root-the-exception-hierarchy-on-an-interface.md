# ADR-0004: Root the exception hierarchy on an interface, and close its leaves

- **Status:** Accepted
- **Date:** 2026-08-03
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 2.1 · [RFC-0001](../rfc/0001-egl-utils-library.md) (*API contract →
  Error model*) · [spec §2 item 26](../specs/01_spec_utils.md) · `AGENTS.md` §8

## Context

RFC-0001 fixes *which* exceptions exist and *what nests under what*:

```
UtilsException ← DatabaseException, HttpException, FileException, JsonException,
                 HydrationException ← UnknownKeyException, MissingKeyException,
                                      TypeMismatchException
```

and pins that shape as a **MAJOR-version surface** — changing it breaks every consumer `catch`.

What the RFC deliberately did not settle is the *mechanics*, and roadmap item 2.1 is flagged
`sets-pattern`: it is the first of its class, so whatever shape it lands becomes the shape the
five remaining component groups copy. Four questions had to be answered before writing the
first class, and answering them by reflex would have been the expensive kind of cheap:

1. Is the root a **class** or an **interface**? A class root is simpler and is what the RFC's
   arrow notation literally implies. It also permanently forecloses one thing: an exception that
   must extend some *other* base for interoperability — an SPL type a framework already catches,
   or a PSR interface's required parent — cannot then also be part of this family.
2. Which **SPL base** does the concrete root extend?
3. Are the leaves **`final`**? Closing them protects the pinned shape; opening them lets
   consumers specialise.
4. Hydration failures must "name the path" (spec §2 item 1). `RuntimeException`'s constructor
   offers `$message, $code, $previous` — and **no consumer of this library will ever branch on
   an integer code**, while the path is the one fact that makes a nested-DTO failure
   diagnosable.

## Decision

**The family is defined by an interface, `UtilsThrowable extends \Throwable`, which every
exception this package raises implements.** `UtilsException extends \RuntimeException implements
UtilsThrowable` is the concrete base and the one consumers normally extend; the interface is
what `catch` clauses and downstream contracts should name.

Alongside it:

- **`\RuntimeException`, not `\Exception` or `\LogicException`.** Everything this package raises
  is detected at run time from data it did not control — a malformed payload, a rejected
  identifier, an unreadable file. None of it is a `LogicException`-class programming-contract
  violation, and `\Exception` is too unspecific to carry meaning.
- **Concrete leaves are `final`; `UtilsException` and `HydrationException` are not.** The two
  non-final classes are the *documented* extension points. A subclass of a leaf would widen a
  contract RFC-0001 committed not to widen without a MAJOR bump, so the leaves are closed and
  the extension points are explicit rather than incidental.
- **`HydrationException::__construct(string $message, string $path = '', ?Throwable $previous)`**
  — `$path` occupies the position SPL gives `$code`, exposed through `path()`. PHP exempts
  constructors from signature compatibility, so this is legal; it is recorded here because it is
  a deliberate trade and not an oversight. `getCode()` still exists and returns `0`.
- **The three hydration leaves carry static named constructors** (`UnknownKeyException::forKey()`,
  `MissingKeyException::forKey()`, `TypeMismatchException::at()`) so the message and the path are
  composed in one place instead of at every throw site.

## Alternatives Considered

- **A class root only, no interface** — what the RFC's notation literally suggests, and rejected
  for the reason in Context 1: it is a one-way door. The cost of the interface is one file with
  no body; the cost of not having it is discovered only when some exception must extend
  elsewhere, at which point the "one thing to catch" contract is already broken for consumers.
- **An interface root only, no concrete base** — maximally flexible, rejected: every leaf would
  then re-declare the same `extends \RuntimeException implements UtilsThrowable`, and there would
  be nowhere to put shared behaviour such as `HydrationException`'s path.
- **`\Exception` as the SPL base** — rejected as unspecific; `RuntimeException` states the
  category correctly and costs nothing.
- **Leaves left extensible** — rejected: it silently widens a MAJOR-pinned surface. A consumer
  who genuinely needs a new member of the family extends `UtilsException`, which is the point of
  leaving *that* open.
- **Keeping SPL's `$code` and passing the path some other way** (a setter, a `withPath()` clone,
  a separate context array) — rejected: a setter makes an exception mutable after construction,
  a clone-wither is ceremony for one string, and a context array gives up the type. The path is
  not optional metadata; it is what the failure *is about*.

## Consequences

- **Consumers get one `catch` that means "this library failed"**: `catch (UtilsThrowable $e)`.
  Nothing about that contract depends on the class hierarchy staying as it is today, which is
  the durable property the interface buys.
- **`JsonException` can shadow PHP's native `\JsonException` safely.** It extends
  `UtilsException` (so it is catchable with everything else) and *wraps* the native one via
  `JsonException::wrap()`, keeping the original as `getPrevious()`. RFC-0001 R-7's decision,
  now mechanically expressible.
- **The hierarchy's shape is asserted, not assumed.**
  `src/test/php/d4np/utils/Support/ExceptionHierarchyTest.php` discovers the exception classes
  **from disk** and fails when one does not implement `UtilsThrowable`, when the member set
  changes, or when a leaf stops being `final`. A member added later without joining the family
  fails the suite instead of reaching a consumer's `catch` and being missed there.
- **No patterns-catalogue row.** The static named constructors are the *named-constructor
  idiom*, not GoF **Factory Method**, which is about deferring instantiation so a subclass
  chooses the concrete type. `docs/patterns/design-patterns.md` lists only the latter, and
  filing this under it to have something to file would be exactly the force-fit `AGENTS.md` §8
  forbids. Recorded here so the omission is a decision rather than a gap.
- **Cost:** one extra file (`UtilsThrowable.php`) with an empty body, and a small amount of
  explaining — this ADR — that a bare class root would not have needed.

## References

- RFC-0001, *Decision → API contract → Error model* (the pinned hierarchy, R-4 and R-7)
- [spec §2 item 26](../specs/01_spec_utils.md) — the hierarchy as a functional requirement
- `AGENTS.md` §8 (patterns policy: justify or reject, never force-fit), §10 (quality bar)
- PHP manual, *Predefined Exceptions* — `RuntimeException` vs `LogicException` semantics
