# ADR-0008: Strict-by-default hydration, and how a static entry point reaches shared state

- **Status:** Accepted
- **Date:** 2026-08-04
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 3.1 · [RFC-0001](../rfc/0001-egl-utils-library.md) R-4 · spec FR-01 ·
  [ADR-0004](0004-root-the-exception-hierarchy-on-an-interface.md) ·
  [ADR-0006](0006-shared-reflection-metadata-cache.md) (whose deferred question this answers)

## Context

Spec FR-01 and RFC-0001 fix the *behaviour*: strict hydration by default with an unknown key
rejected, `lenient()` as the opt-out, `MissingKeyException` when a key that is neither nullable
nor defaulted is absent, recursion into nested DTOs, and a `TypeMismatchException` that names the
path. Roadmap item 3.1 is flagged `sets-pattern` — this is the first component group to consume
`Support`, so its shape is the one Milestones 4–6 copy.

Three things had to be decided, and one of them is a question a previous ADR deliberately left
open until this moment.

**1. How does a static entry point reach shared state?** The spec's own example is
`UserDto::fromArray($data)` — a static call. It needs the one `ReflectionCache` imported ADR-001
commits to, and a static method has no instance to be injected into. ADR-0006 named this
explicitly and declined to guess: *"Building it now means guessing at reset semantics,
thread/process assumptions, and testability for a caller that has not been written. Milestone 3
will have the real constraint in hand."* This is Milestone 3.

**2. What shape does the opt-out take?** `lenient()` has to return something, and the obvious
alternative — `fromArray($data, lenient: true)` — reads differently at the call site.

**3. What PHP actually does with absent arguments**, which turned out not to match the intuition
the requirement is written in. Verified directly before writing a line:

| Construct | Result |
|---|---|
| named-argument spread from a string-keyed array, key omitted | PHP applies the declared default |
| `?string $x` with **no** default, argument omitted | **`ArgumentCountError`** — nullable is *not* optional in PHP |
| `int` passed where `float` is declared, under `strict_types=1` | **accepted**, widened to float |

The middle row is the one that matters: RFC-0001 R-4 says a nullable property absent from the
payload "hydrates to `null`", and that only holds if `null` is passed **explicitly**. An
implementation that treats nullable and defaulted the same way — omitting both — raises
`ArgumentCountError` instead, which is neither the documented behaviour nor a library exception.

## Decision

**Strict hydration is the default; `lenient()` returns a `Hydration<T>` bound to the class; and
`DataTransferObject` holds one lazily-created process-wide `Hydrator` in a private static, which
needs no reset hook because the state it holds is immutable.**

### Absence is resolved in three cases, in this order

1. **Has a declared default** → the argument is *omitted*, and PHP applies the default. Nothing
   is replicated here.
2. **Nullable without a default** → `null` is passed **explicitly**, because PHP would otherwise
   raise `ArgumentCountError` (evidence above). This is what makes R-4 true rather than aspirational.
3. **Neither** → `MissingKeyException`, naming the path.

The order is load-bearing: a parameter that is both nullable *and* defaulted takes its default,
not `null`, which is what a reader of the declaration would expect.

### The shared hydrator needs no reset, and that is why it is safe

ADR-0006's hesitation was about reset semantics and testability. Both dissolve on inspection:
the cache memoises **immutable facts** — a class's constructor cannot change while the process
runs — so there is no stale state a test could observe, and nothing to invalidate. What would
have been a mutable global is a pure memo table. The *mode* (strict or lenient), by contrast, is
per-call and never stored, so a lenient call cannot leak into a later strict one.

### `lenient()` returns a type, not a boolean

`Hydration<T>` is a two-line object bound to one class. The alternative,
`fromArray($data, lenient: true)`, costs nothing to write and something to read: a bare `true`
at a call site says nothing about which policy it selects, whereas `UserDto::lenient()->fromArray(…)`
says it in the method name. It is also generic, so PHPStan keeps the concrete DTO type through
the call.

### Leniency relaxes what may arrive, not what must be present

A lenient hydration still raises `MissingKeyException` for an absent required key and still
type-checks every value. Without that, `lenient()` quietly becomes "skip validation", which is
not what the spec offers it for, and the suite asserts it directly.

## Alternatives Considered

- **A trait instead of a base class** — rejected: the spec's example writes `extends
  DataTransferObject`, `static::class` is needed for the metadata lookup either way, and a trait
  cannot express the `Hydration<static>` return type as cleanly.
- **A boolean flag on `fromArray()`** — rejected on readability, as above.
- **Injecting the cache/hydrator per call** (`UserDto::fromArray($data, $cache)`) — rejected: it
  pushes the shared-instance problem onto every call site to solve the same problem once, and the
  spec's example shows a bare static call.
- **Treating nullable and defaulted identically** (omit both) — rejected on evidence: it raises
  `ArgumentCountError` for the nullable-without-default case, contradicting R-4.
- **Rejecting `int` where `float` is declared** — rejected: PHP performs that widening itself
  under `strict_types=1`, so refusing it would make this library stricter than the language and
  reject payloads the constructor would have accepted.
- **Hydrating arbitrary classes from arrays**, not just DTOs — rejected: there is no general way
  to know an arbitrary constructor's intent. A non-DTO class parameter accepts an instance and
  refuses an array, which is honest about what the library can and cannot build.

## Consequences

- **Mass assignment is refused by default.** An undeclared key is an error, not a silent drop,
  which is the property the strict default exists for.
- **A failure in a graph says where it happened.** Paths compose through recursion
  (`customer.address.postcode`), so a nested `TypeMismatchException` is actionable. Asserted for
  all three failure kinds at depth.
- **No bare `TypeError` escapes.** Construction is wrapped and converted to a
  `HydrationException`, so ADR-0004's "one thing to catch" contract holds even for a type the
  checks did not anticipate — an exotic builtin, or a union arm the metadata deliberately does
  not reduce (ADR-0006).
- **A variadic constructor is refused, not guessed at.** A keyed payload cannot express "an
  arbitrary number of positional arguments", so it raises rather than inventing a mapping.
- **`Collection<T>` hydration is not here.** Spec FR-01 names it, and it needs the docblock
  generic parser ADR-0006 deliberately placed with `Collection` itself — roadmap item 3.3. The
  gap is named rather than left for a reader to discover.
- **Cost:** three classes (`DataTransferObject`, `Hydrator`, `Hydration`) where a single one with
  a boolean would do. The split buys a readable call site, a testable engine, and a recursion
  that carries its own path.

## References

- Spec FR-01; RFC-0001 *Error model* and R-4 (the missing-key addition made at review)
- ADR-0006 — the deferred static-entry-point question this ADR answers, with the constraint in hand
- ADR-0004 — the exception contract construction must not break
- PHP manual: named arguments, `strict_types` widening rules — both verified directly rather than
  taken from the documentation
