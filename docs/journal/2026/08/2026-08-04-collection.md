# 2026-08-04 — `Collection<T>`, and changing a decision a previous ADR had already named

Roadmap item **3.3**. Route `standard / medium`; session model matched. It also closes the
`Collection<T>` hydration gap item 3.1 named in its own roadmap entry.

## ADR-0006 said "write a docblock parser". Measurement said don't.

Spec FR-01 requires `Collection<T>` properties to hydrate recursively; PHP has no runtime
generics, so the hydrator must learn the element type from somewhere. ADR-0006 deferred the
question to this item and named the expected answer — *"that parser belongs with `Collection`
itself"*.

Before writing one, what a docblock actually hands back was measured:

```php
namespace App\Model;
use App\Dto\AddressDto as Addr;
/** @param Collection<Addr> $stops */
```

The regex yields the token **`'Addr'`**. Turning that into `App\Dto\AddressDto` needs the file's
namespace, its `use` statements, **and** the knowledge that `Addr` is an alias — none of which is
in the docblock, and none of which reflection exposes. Grouped `use` declarations and relative
names make it worse. Nothing short of a real PHP parser resolves it, and a regex that resolves it
*wrongly* fails **silently**: hydrating into the wrong class, or not at all.

`#[CollectionOf(AddressDto::class)]` has none of that problem. PHP resolves the argument at
compile time and hands over a class-string; PHPStan type-checks it at the declaration site.

So the decision changed, and [ADR-0010](../../../adr/0010-collection-generics-by-attribute.md)
records it *as* a change rather than substituting quietly — ADR-0006 named the parser explicitly,
and a later reader deserves to find out why it never appeared.

The cost is named too: the element type is now declared twice — docblock for PHPStan, attribute
for run time — and they can drift. A mechanical check that they agree is possible and deliberately
not built, because that particular failure is *visible* (a collection of raw arrays) rather than
silent.

## A layering constraint shaped the plumbing

The hydrator needs the attribute; the attribute lives in `Dto`; the metadata cache lives in
`Support` — and RFC-0001 forbids `Support` depending on a group above it.

`ParameterMetadata` therefore gained `attributes` typed `list<object>`, **uninterpreted**.
`Support` caches the instances and never learns what any of them mean; `Dto` picks `CollectionOf`
out of them. Naming the attribute class in `Support` would have inverted the dependency; having
the hydrator re-reflect per call would have undone the caching NFR-01 rests on. Generic-and-cached
does neither.

## Covariance is a design statement, not an escape hatch

PHPStan rejected `Collection<object>` where `Collection<mixed>` was declared and suggested
`@template-covariant`. That suggestion happens to be *correct here*, and for a reason worth
writing down: covariance is sound only for a container that cannot be written to, and this one
cannot — no `add()`, no `set()`, no mutation anywhere. An appendable collection could not safely
declare it. Taking the analyser's hint because it is right is different from taking it because it
silences the error.

## Two smaller decisions

- **`filter()` carries the guard; `map()` drops it.** Filtering cannot change the element type;
  mapping is *for* changing it, and carrying the old `instanceof` across would reject exactly the
  transformations `map()` exists to perform. Both directions are asserted.
- **`reduce()` requires an initial value** rather than defaulting to `null`: a fold with no
  starting value is undefined on an empty collection, and returning `null` hands the caller a type
  the callback never produces.

## Proved non-vacuous

Two probes, each reverted and both files restored byte-identical:

1. **Made `filter()` drop the guard** → 1 failure.
2. **Ignored the `CollectionOf` attribute** (elements stay raw arrays) → 6 failures.

236 tests, 454 assertions (7 skipped, Windows-only). PHPStan max clean; PHP-CS-Fixer clean.

## Next

**3.4 — the T-01 hydration matrix suite.** Like item 2.6 before it, most of what it names now
exists: the `#[Group('T-01')]` tag already spans DTO hydration, withers and collection
hydration. Whether 3.4 is fresh work or bookkeeping is worth checking against the spec before
starting, rather than assuming either.
