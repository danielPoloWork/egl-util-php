# ADR-0010: Declare collection element types with an attribute, not a docblock parser

- **Status:** Accepted
- **Date:** 2026-08-04
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 3.3 · spec FR-01, FR-03 · [RFC-0001](../rfc/0001-egl-utils-library.md) ·
  [ADR-0006](0006-shared-reflection-metadata-cache.md) (which deferred this, expecting a parser) ·
  [ADR-0008](0008-dto-hydration-strictness-and-shared-hydrator.md)

## Context

Spec FR-03 asks for a `Collection<T>` whose genericity is *"static-analysis-level only
(`@template` + PHPStan max); optional runtime `instanceof` guard flag"*. Spec FR-01 additionally
requires that `Collection<T>` **properties hydrate recursively** — a `Collection<AddressDto>`
declared on a DTO must turn an array of arrays into a collection of `AddressDto`s.

Those two pull in opposite directions. PHP has no runtime generics, so `Collection<AddressDto>`
exists only in an annotation — and the hydrator, at run time, has to learn `AddressDto` from
somewhere.

ADR-0006 deferred this question to here and named the expected answer: *"that parser belongs
with `Collection` itself (roadmap 3.3)"* — i.e. read the docblock. Before writing one, what a
docblock actually hands back was measured:

```php
namespace App\Model;
use App\Dto\AddressDto as Addr;

/** @param Collection<Addr> $stops */
```

The regex yields the token **`'Addr'`**. Turning that into `App\Dto\AddressDto` requires knowing
the file's namespace, its `use` statements, **and** that `Addr` is an alias — none of which is in
the docblock, and none of which reflection exposes. Grouped `use` declarations, function/const
imports and relative names make it worse. Nothing short of a real PHP parser resolves it
correctly, and a regex that resolves it *incorrectly* fails silently, hydrating into the wrong
class or none at all.

An attribute argument has none of that problem: `#[CollectionOf(AddressDto::class)]` is resolved
**by PHP itself** at compile time and arrives as a class-string — which PHPStan also type-checks
at the declaration site.

## Decision

**The element type of a hydratable `Collection` parameter is declared with
`#[CollectionOf(Foo::class)]`. No docblock parser is written.** The `@param Collection<Foo>`
annotation stays and remains the item's `@template` discipline — it is what PHPStan reads. The
two say the same thing to two different audiences: the annotation to the static analyser, the
attribute to the run time.

Supporting decisions:

- **`Collection` is immutable and its template is `@template-covariant`.** That is a statement
  about the design, not a way to quiet the analyser: covariance is sound precisely because there
  is no `add()`, no `set()`, no mutation at all. An appendable collection could not safely
  declare it.
- **`filter()` carries the guard across; `map()` drops it.** Filtering cannot change the element
  type; mapping is *for* changing it, and carrying the old `instanceof` check would reject the
  transformations `map()` exists to perform.
- **`reduce()` requires an initial value.** A fold with no starting value is undefined on an
  empty collection, and the usual workaround — returning `null` — hands the caller a type the
  callback never produces.
- **`ParameterMetadata` gained an `attributes` field typed `list<object>`, uninterpreted.**
  `Support` caches the instances and never learns what any of them mean; `Dto` reads
  `CollectionOf` out of them. Naming the attribute in `Support` would import a group *upward*
  and break RFC-0001's layering rule, and re-reflecting per hydration would undo the caching
  NFR-01 depends on. Generic-and-cached is the only option that does neither.
- **Without the attribute, elements pass through untouched.** That is what a `Collection<string>`
  wants, and it is the honest response when the element type is genuinely unknown: guessing that
  an array of arrays means an array of DTOs would invent a mapping the declaration never made.

## Alternatives Considered

- **A docblock generic parser**, as ADR-0006 anticipated — rejected on the evidence above: the
  token is unresolvable without a full parser, and a partial one fails silently and wrongly.
  Recorded as a *changed* decision rather than a quiet substitution, since ADR-0006 named the
  parser explicitly.
- **Requiring fully-qualified names in the docblock** (`Collection<\App\Dto\AddressDto>`) — this
  makes a regex sufficient, and is rejected because it puts a rule in a comment that nothing
  enforces: a short name still analyses cleanly under PHPStan and would silently not hydrate.
- **A generic runtime `Collection` that records its element type on construction** — does not
  help. The hydrator's problem is knowing what to build *before* anything exists.
- **Naming the `CollectionOf` attribute inside `Support`** so the cache could interpret it —
  rejected: it inverts the dependency RFC-0001 fixes, for the convenience of one field.
- **Rejecting a `Collection` parameter with no attribute** — rejected as heavy-handed: it would
  outlaw `Collection<string>`, which needs no element hydration at all.

## Consequences

- **`Collection<T>` properties hydrate**, closing the gap item 3.1 named in its own roadmap
  entry. Paths compose through the index, so a failure says `stops.1.postcode` rather than
  "something in the collection".
- **A hydrated collection carries the declared guard**, so the attribute's claim is checked
  rather than trusted to the hydration loop.
- **The element type is declared twice** — once in the docblock for PHPStan, once in the
  attribute for run time — and they could drift. That is the real cost of this decision and it is
  named rather than glossed: a mechanical check that the two agree is possible and is *not* built
  here, because the failure it guards against is visible (a collection of raw arrays) rather than
  silent.
- **Genericity remains unenforced at run time unless asked for**, which is what spec FR-03 says
  and what the optional guard is for. A test asserts an unguarded collection accepts anything, so
  the honesty of that claim is mechanical rather than prose.

## References

- Spec FR-01 (recursive hydration of `Collection<T>` properties), FR-03 (`Collection` itself)
- ADR-0006 — the deferral, and the parser it expected; superseded on this point by measurement
- PHP manual: attributes on promoted constructor parameters (verified readable via
  `ReflectionParameter::getAttributes()`)
