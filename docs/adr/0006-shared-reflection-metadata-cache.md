# ADR-0006: Shape the shared reflection-metadata cache as plain instances, without an interface

- **Status:** Accepted
- **Date:** 2026-08-04
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 2.5 · [RFC-0001](../rfc/0001-egl-utils-library.md) R-2 ·
  imported [ADR-001](../../.specs/d4np_php_adr_001_di_container.md) · spec FR-01, FR-04,
  NFR-01, NFR-02 · [ADR-0004](0004-root-the-exception-hierarchy-on-an-interface.md)

## Context

Imported ADR-001 commits to **one** metadata cache: *"the reflection cache used by the container
is shared infrastructure with the DTO hydrator (one metadata cache)"*. RFC-0001 R-2 places it in
`Support`, because that is the only layer both the `Dto` and `Container` groups may depend on
without breaking the no-cross-group-imports rule. NFR-01 (≤ 5 µs/DTO warm) and NFR-02 (≤ 2 µs
warm singleton resolve) both rest on it: reflection is paid once per class, everything after is
an array hit.

What neither document settles is the *shape*, and roadmap item 2.5 is flagged `sets-pattern` —
whatever lands here is what Milestone 3's hydrator and Milestone 6's Container will both build
on. Four questions had to be answered before writing the first class, at a point where **neither
consumer exists yet**. That is the real constraint: every decision risks either under-building
(a consumer blocked later) or speculating (fields and abstractions nobody ever uses).

## Decision

**A concrete `ReflectionCache` returning immutable `ClassMetadata` / `ParameterMetadata` value
objects, instance-scoped, with no interface and no static accessor.**

### Every metadata field traces to a stated requirement

Nothing is recorded because it might be useful:

| Field | Required by |
|---|---|
| `name` | FR-01 — matching a payload key to a constructor parameter |
| `type`, `isBuiltin` | FR-01 (type-check a scalar vs recurse into a nested DTO) and FR-04 (resolve a class vs refuse) |
| `allowsNull`, `hasDefault`, `default` | RFC-0001 R-4 — "neither nullable nor defaulted" is exactly what makes a key required. `hasDefault` cannot be folded into `default`: a declared default of `null` is otherwise indistinguishable from having none |
| `declaredType` | imported ADR-001 requires the Container to *fail loudly* on what it cannot autowire; a refusal that names the type it saw is what makes that useful |
| `isVariadic` | not a feature — truthfulness. Without it the metadata silently describes `...$args` as an ordinary parameter, and describing the class accurately is the cache's entire job |
| `isInstantiable` | FR-04 — an interface, abstract class, or private constructor must be refused clearly rather than failing inside `new` |

### No interface — and the asymmetry with ADR-0004 is the point

ADR-0004 rooted the exception hierarchy on an interface precisely because retrofitting one later
is impossible: an exception forced to extend a different base could no longer join the family,
and every consumer `catch` would break. **That reasoning does not transfer here.** Extracting an
interface from a concrete collaborator is a *non-breaking, additive* refactor — existing
consumers keep working unchanged. There is no one-way door, so there is nothing to buy insurance
against, and no consumer exists yet to tell us what the interface should say.

### Instance-scoped, with no static accessor

The cache is an ordinary object. Two instances are independent, which is what makes it testable
and keeps per-process state out of global scope.

The obvious objection is that the hydrator's entry point is expected to be **static**
(`DataTransferObject::fromArray()`), which cannot receive an injected instance. A `shared()`
singleton accessor would solve that — and it is **deliberately not built here**, because the
constraint that would shape it does not exist yet. Building it now means guessing at reset
semantics, thread/process assumptions, and testability for a caller that has not been written.
Milestone 3 will have the real constraint in hand.

### Failures throw `UtilsException`, not a new hierarchy member

Reflecting a name that resolves to nothing throws `UtilsException` — the concrete base — rather
than a new `ReflectionException` leaf. RFC-0001 pins the exception hierarchy's shape as a
MAJOR-version surface, so **adding a member is a decision, not an implementation detail**. No
consumer has yet needed to catch reflection failures specifically; when one does, that is a
decision to take with the driver in hand. What matters immediately is that nothing escapes the
family: a bare `\ReflectionException` would break ADR-0004's "one thing to catch" contract.

### Explicitly out of scope: `Collection<T>` element types

Spec FR-01 says `Collection<T>` properties hydrate recursively, which needs the element type —
and PHP has no runtime generics, so it lives in a `@var`/`@param` docblock and requires parsing.
That parser belongs with `Collection` itself (roadmap 3.3), not here. Recorded so its absence is
a boundary, not an oversight.

## Alternatives Considered

- **Define a `ReflectionCacheInterface` up front** — rejected per the asymmetry above: the
  retrofit is additive, so the insurance is unnecessary, and no consumer exists to specify it.
- **A static/singleton cache** (`ReflectionCache::for()` as a static call) — simplest for a
  static hydrator entry point, rejected: it makes per-process state global, forces a test-only
  `reset()` into the production API, and answers a question Milestone 3 has not yet asked.
- **Adopt PSR-6 / PSR-16 and let consumers plug in a cache backend** — rejected: this is
  in-process memoisation, not caching. There is nothing to persist (a constructor cannot change
  while the process runs), no eviction policy to express, and it would add a dependency to a
  package whose zero-implementation-dependency stance is a stated design goal.
- **Reduce union and intersection types to their first arm** — rejected: imported ADR-001 has
  the Container *refuse* what it cannot autowire rather than guess. `type` is `null` for those,
  and `declaredType` preserves what was seen.
- **Add a `ReflectionException` leaf to the hierarchy** — rejected for now, as above: it widens a
  MAJOR-pinned surface with no consumer requiring the distinction.

## Consequences

- **Both Milestone 3 and Milestone 6 can build on one cache**, satisfying imported ADR-001's
  single-cache commitment, and NFR-01/NFR-02 have the mechanism they assume.
- **Memoisation is observable, not merely timed.** `isCached()` and `count()` exist so a test can
  assert reflection happened once — a cache whose only evidence is a timing difference is one
  nobody can write a deterministic test against. The suite asserts *object identity* across
  repeated lookups: a non-memoising implementation returns an equal-but-distinct object, so
  `===` fails where `==` would not.
- **A failed reflection is not cached**, so a class that becomes loadable later is not
  permanently poisoned by an early lookup.
- **PHP's reflection semantics are recorded where they surprised us**, each verified directly
  rather than assumed: `mixed` is a *named builtin type*, not the absence of a type; a union's
  arms come back canonically ordered (`int|string` reflects as `string|int`), so tests assert
  membership rather than an exact string; and `class_exists()` returns **false** for interfaces
  and traits, which is why the existence guard checks all three.
- **Cost:** three classes where one might have done, and value objects that will look
  over-specified until their consumers land. The mitigation is the traceability table above —
  every field has a named requirement, and a field that cannot cite one does not belong.

## References

- Imported ADR-001 (the single-cache commitment); RFC-0001 R-2 (placement in `Support`)
- Spec FR-01, FR-04, NFR-01, NFR-02
- ADR-0004 — the interface decision this one deliberately does *not* mirror, and why
