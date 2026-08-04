# ADR-0009: Withers rebuild through the constructor rather than cloning

- **Status:** Accepted
- **Date:** 2026-08-04
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 3.2 · spec FR-02 · [RFC-0001](../rfc/0001-egl-utils-library.md) ·
  [ADR-0008](0008-dto-hydration-strictness-and-shared-hydrator.md)

## Context

Spec FR-02 asks for `with(...)` wither semantics on `readonly` DTOs, *"absorbing the PHP
8.1→8.3 readonly-clone difference per version"*. The phrasing implies the trait should branch on
`PHP_VERSION_ID` and do one thing on 8.1/8.2 and another on 8.3.

What that difference actually is, measured rather than recalled:

| Operation | 8.1 / 8.2 | 8.3 |
|---|---|---|
| assign a `readonly` property after `clone`, **outside** `__clone()` | `Error` | **`Error`** |
| assign a `readonly` property **inside** `__clone()` | `Error` | **allowed** |
| build a new instance through the constructor | allowed | allowed |

The first row is the surprising one and was verified on 8.3 directly: PHP 8.3's readonly
amendment did **not** make readonly properties writable after cloning in general. It relaxed one
narrow case — reassignment inside `__clone()`. Everywhere else the property stays sealed.

So a clone-based wither needs a `__clone()` hook *and* is still an error on 8.1 and 8.2, which
this project supports. Meanwhile the third row works everywhere.

There is a second consideration that has nothing to do with versions. A clone copies an object
into existence **without running its constructor**. A DTO that validates in its constructor —
a plausible and useful thing — would have that validation bypassed by a clone-based wither,
which could then produce an object the class itself would refuse to build.

## Decision

**`with(...)` rebuilds the object through its constructor: read the current value of every
constructor parameter, apply the named changes, and construct a new instance. The trait carries
no version branch at all.**

The requirement to "absorb the 8.1→8.3 difference" is met by **not depending on the
difference**. Writing `if (PHP_VERSION_ID >= 80300)` would add a branch that is dead on two of
the three supported versions and exercised on one — the shape item 3.1 had just been caught
producing, where `match` arms existed for types undeclarable below the 8.1 floor.

Supporting decisions:

- **`with()` takes named arguments** (`$user->with(name: 'Grace')`), collected by a variadic into
  a string-keyed array. The call site names what it changes.
- **It routes through the same hydrator as `fromArray()`**, so an undeclared name raises
  `UnknownKeyException` and a bad value raises `TypeMismatchException`, each with a path.
  `with()` is not a way around the type system, and none of that logic is duplicated.
- **The trait declares `abstract protected static function hydrator(): Hydrator`.** A trait's
  abstract method is satisfied by an *inherited* one (verified), so `DataTransferObject`'s
  already-shared instance fulfils it and using the trait elsewhere is a **compile-time** error
  rather than a surprise at the first `with()` call. It also keeps imported ADR-001's *one*
  metadata cache true: a trait building its own would quietly make two.
- **Current values are read through `ReflectionProperty::getValue()`**, not `$source->{$name}`.
  A promoted property may be `private`, and the hydrator is not in its scope; since PHP 8.1 that
  call needs no `setAccessible()`. It costs a reflection lookup per property, which is acceptable
  because withers are not the path NFR-01 measures.
- **A constructor parameter with no property of the same name is refused with an explanation.**
  Rebuilding requires every parameter to be recoverable; promoted properties always are, a
  non-promoted parameter stored under a different name is not.

## Alternatives Considered

- **`clone` plus a `__clone()` hook, branching on `PHP_VERSION_ID`** — the literal reading of
  FR-02. Rejected: the branch is dead on 8.1 and 8.2, it bypasses constructor validation, and it
  buys nothing the rebuild does not already provide.
- **`clone` plus reflection to write the readonly property** — rejected: `ReflectionProperty::setValue()`
  does not lift the readonly seal on an initialised property, and defeating a language guarantee
  from inside a library that exists to make guarantees is the wrong trade even where possible.
- **Per-property withers** (`withName()`, `withEmail()`) — rejected: spec FR-02 says `with(...)`,
  and generating or hand-writing one method per property is a maintenance cost with no gain over
  named arguments.
- **`with(array $changes)`** instead of named variadic arguments — rejected on readability;
  `->with(['name' => 'Grace'])` says the same thing with more punctuation and no type checking at
  the call site.
- **Skipping validation on the rebuild** (construct without running checks) — rejected: it would
  reintroduce, deliberately, the exact hole the clone approach has by accident.

## Consequences

- **Withers work identically on 8.1, 8.2 and 8.3**, and will keep working on 8.4 without a new
  branch, because nothing in the trait knows which version it is on.
- **Constructor validation applies to wither results.** A DTO that guards its invariants in the
  constructor keeps them under `with()` — the property a clone-based implementation silently
  loses, asserted directly by a fixture that validates and a test that expects the refusal.
- **The receiver is never mutated**, which is the point; `with()` on a DTO with no changes
  returns an equal but distinct object.
- **A nested DTO is carried across by reference**, not rebuilt: withering the parent does not
  recreate children that did not change.
- **Cost:** a rebuild is more work than a clone — reflection per property, plus the constructor
  and the type checks. For an immutable DTO that is the right trade; if a hot path ever needs
  otherwise, that is a measurement to bring, not an assumption to design around now.
- **Not tested, and stated rather than left implicit:** that assigning to a `readonly` property
  throws. PHPStan at max rejects such an assignment wherever it appears, for every call site —
  stronger than one runtime assertion, and testing it needs the linter suppressed or dodged.

## References

- Spec FR-02; RFC-0001 (the `readonly`, promoted-constructor DTO shape)
- PHP RFC *Readonly amendments* (8.3) — the change measured above, and its narrow scope
- ADR-0008 — the hydrator this trait routes through, and the shared instance it must not duplicate
