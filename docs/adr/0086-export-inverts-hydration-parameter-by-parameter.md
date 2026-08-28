# ADR-0086: Export inverts hydration parameter by parameter, and a pure enum is the one refusal

- **Status:** Accepted
- **Date:** 2026-08-28
- **Deciders:** project architect (agent), maintainer
- **Related:** issue [#187](https://github.com/danielPoloWork/egl-util-php/issues/187) ·
  [RFC-0004](../rfc/0004-batteries-included-utility-surface.md) (FR-51, roadmap item 15.3) ·
  [ADR-0008](0008-dto-hydration-strictness-and-shared-hydrator.md) (the hydration semantics this
  inverts) ·
  [ADR-0013](0013-compile-a-hydration-closure-for-the-scalar-shape.md) (the compiled path export
  deliberately does not get) ·
  [ADR-0010](0010-collection-generics-by-attribute.md) (`#[CollectionOf]`, the attribute that
  drives collection-element conversion in both directions) ·
  [ADR-0009](0009-withers-rebuild-rather-than-clone.md) (`withChanges()`, whose property
  read-back this shares) ·
  [ADR-0059](0059-freeze-the-api-at-1-0-0-with-internal-symbols-outside-the-frozen-surface.md)
  (why everything here is additive, and the one BC caveat named below) ·
  [ADR-0040](0040-install-the-mutation-tester-outside-the-graph-and-keep-nfr07s-own-number.md)
  (why the new benchmark subject carries no budget) · spec **FR-51**, revision **r32**

## Context

`fromArray()` has had no inverse since M3. The `Dto` group can turn an array into a typed graph —
recursively, strictly, with every refusal naming its path — and cannot turn the graph back into an
array: `grep -rn "toArray\|jsonSerialize" src/main/php/d4np/utils/Dto/` finds only
`Collection::toArray()`, which returns the DTO *instances*. Every consumer that renders a DTO
through `ApiEnvelope`, logs one, or hands one to a codec writes the export by hand, and each copy
decides differently what a nested DTO, an enum, or a `Collection` becomes — the per-project drift
this library exists to retire, in the one group whose entire contract is that conversions are not
guessed at.

The design question is not "walk the properties and build an array" — that is the easy 80%. It is
**what the array contains at each position**, because the answer determines whether
`X::fromArray($x->toArray())` reconstructs `$x` or silently produces a sibling. RFC-0004 fixed the
outer contract (recursive; `Collection<T>` as a list; backed enums to their backing value; pure
enums refused with the path; the round-trip property over the T-01 matrix). What it left open, and
what this ADR decides, is the *mechanism* that makes those clauses hold together rather than
individually: what drives the conversion — the value's runtime type, or the declaration hydration
itself reads.

## Decision

### 1. Export is metadata-driven: it inverts `coerce()` branch for branch

`Hydrator` gains `extract(object $dto): array`. It walks the same `ClassMetadata::$parameters`
hydration walks, reads each promoted property back (the read-back loop `withChanges()` already
owns, now shared), and converts each value by **the parameter's declaration**, mirroring
`coerce()`'s branches exactly:

| declaration | hydration accepts | export produces |
|---|---|---|
| builtin / untyped / `mixed` / union | the value, as-is | the value, **as-is** |
| a `DataTransferObject` subclass | an instance, or an array (recursed) | `extract()` of the instance — the array form |
| a `BackedEnum` subclass | an instance, or its backing value | the **backing value** |
| a pure `UnitEnum` | an instance only | **refused**, naming the path (§3) |
| `Collection` + `#[CollectionOf]` DTO | instances, or arrays (recursed) | a list of arrays |
| `Collection` + `#[CollectionOf]` backed enum | instances — **widened, §2** | a list of backing values |
| `Collection`, no attribute | elements as-is | a list of the elements, **as-is** |
| any other class/interface | an instance only | the instance, **as-is** |

Driving the conversion off the **declaration** rather than the value's runtime type is the
load-bearing choice, and one row shows why: a `Status` enum sitting in a `mixed` parameter. By
runtime type it would export as `'active'` — and re-hydration of a `mixed` parameter passes
`'active'` through as a plain string, so the round trip yields a *different object* with every
test that only checks "no exception" still green. By declaration it exports as the instance
hydration would accept there, and the round trip is exact. The same argument covers plain `array`
parameters (never walked — hydration never walks them inward), `iterable`, and consumer classes
like `DateTimeImmutable` (hydration only ever accepted the instance, so the instance is what comes
back). **The round-trip property `X::fromArray($x->toArray()) == $x` is therefore exact by
construction** for every `$x` built by `fromArray()`, not statistically true over the cases
someone remembered to test.

`DataTransferObject` gains `toArray(): array` delegating to the shared hydrator, and implements
`JsonSerializable` with `jsonSerialize(): array` returning `toArray()` — one conversion, two
names, so `json_encode($dto)` and `Json::encode($dto->toArray())` cannot disagree.

### 2. `#[CollectionOf]` with a backed-enum element type learns to read backing values

`coerceCollection()` accepted instances of the declared element type, and arrays only when that
type is a DTO. A backing value for an enum element type — exactly what a decoded JSON payload
contains — was a `TypeMismatchException`, while the *same value* at a top-level enum parameter
hydrated fine. That asymmetry predates this ADR; export forces it into the open, because clause 1
says an enum collection exports as a list of backing values, and a list the library itself
produced must re-hydrate.

So `coerceCollection()` gains one branch: an `int|string` element under a backed-enum
`#[CollectionOf]` resolves through the same `coerceBackedEnum()` the top-level path uses, with the
same indexed path in the refusal. Additive — it accepts strictly more than before, changes no
accepted input's meaning, and stays on the interpreter (collections were never compiled).

### 3. A position *declared* as a pure enum is refused at export — the one deliberate non-inversion

A pure-enum *instance* would round-trip: hydration accepts it as-is, so passing it through
`toArray()`'s output as-is satisfies the property. At a position **declared** as one — a
parameter typed `Direction`, a `#[CollectionOf(Direction::class)]` — it is refused anyway, and
the honest reasoning is recorded rather than implied. Enums are the one family this library
*converts*: clause 1 gives every backed enum a data form, so a declaration naming a pure enum has
put the value inside the conversion vocabulary while giving it no data form. The two non-refusal
behaviours are both worse than saying so: exporting the case *name* produces an array
`fromArray()` rejects (a round trip broken by the library's own output), and passing the
*instance* through produces an "array" whose enum leaves detonate later, inside `json_encode()`,
as PHP's native `"Enum ... could not be converted"` naming neither property nor DTO. The refusal
happens at the boundary, names the path (`stops.2`), and names the fix: back the enum, or keep it
out of exported DTOs.

The rule stays declaration-driven even here — the same principle as clause 1, applied to the
refusal. A pure-enum instance sitting in a `mixed`/untyped parameter or an attribute-less
collection is **not** refused: that position is opaque, hydration passes it through both ways,
and the round trip is exact. (First drafted as a runtime-type check that refused pure enums
everywhere; caught against clause 1's own argument before landing — a `mixed` position must
export what hydration accepts there, which is the instance.) Equally deliberate is the family
boundary: an arbitrary consumer class (`DateTimeImmutable`, a value object) passes through as an
instance even at a *declared* position, because it sits outside the conversion vocabulary
entirely — hydration never converted it in, so export does not convert it out, and inventing a
serialization for someone else's class is the guessing this group refuses. `toArray()`'s docblock
states the limit: the output is plain data exactly as far as the declarations are plain; opaque
values stay opaque. The per-element shape of the collection refusal means an **empty**
`#[CollectionOf(PureEnum)]` collection still exports as `[]` and round-trips — refusing it would
break a working round trip to make a point.

### 4. Export stays interpreter-only; the read-back is shared with withers, not duplicated

No compiled export closure. ADR-0013's compiler exists because hydration is the per-row hot path
NFR-01 budgets; export has no measured hot path yet, and a second generated-code surface is a
standing maintenance cost (`HydrationParityTest` exists precisely because two implementations of
one contract drift). One implementation, one behaviour — the new `benchToArrayWarm` subject
measures it (ADR-0011's harness), the number is recorded, and a budget waits for a demonstrated
hot path per ADR-0040. Round-trip tests over compiled-eligible DTOs (`ScalarsDto`,
`CompilableDto`) still exercise the compiled *hydration* half against interpreted export.

The property read-back (`readable properties for every constructor parameter, variadics and
non-promoted parameters refused`) is extracted from `withChanges()` and shared, with each caller's
refusal keeping its own wording — a wither failure says withers rebuild through the constructor;
an export failure says export reads back through it.

## Consequences

- **The intake pipeline closes.** `readAssoc()`/`Request` → `fromArray()` → domain → `toArray()`
  → any codec, with the round-trip property as the contract seam — the "one canonical shape, N
  codecs" architecture RFC-0004 records now has both directions.
- **`fromArray(toArray($x)) == $x` is a tested law**, asserted over the whole T-01 matrix
  (scalars, nested, collections, enums, defaults, nullables, `mixed`), including the
  compiled-hydration classes.
- **One BC caveat, named rather than discovered**: adding concrete `toArray()`/`jsonSerialize()`
  to a non-final abstract base fatals any consumer subclass that already declares an incompatible
  method of the same name. ADR-0059 permits additive members; this is the standard non-final-class
  addition hazard, visible in the per-PR Roave report, and judged acceptable because the names are
  exactly the ones such a subclass would have wanted this contract for.
- **A pure enum in an exported DTO is now a boundary refusal** — previously it merely sat there;
  a consumer hitting the refusal reads the path and backs the enum. Hydration of pure enums is
  untouched.
- **Enum collections hydrate from JSON now** (§2) — a small widening consumers get for free.
- The `Dto` group still depends on `Support` only; `JsonSerializable` is SPL. No deptrac change,
  no new exception type (`HydrationException` carries the export refusals — they are hydration's
  vocabulary read in the other direction).

## Alternatives considered

1. **Runtime-type-driven conversion** (convert whatever the value is, wherever it sits).
   Rejected: breaks the round trip for `mixed`/untyped parameters (§1's `Status`-in-`mixed` case)
   — and does it silently, which is the worst available failure mode for a method whose one
   promise is the round trip.
2. **Walk plain `array` properties and convert DTO/enum instances found inside.** Rejected for
   the same reason from the other side: hydration passes `array` parameters through untouched, so
   converted contents would come back as arrays, not instances — the round trip breaks exactly
   where the walk "helped".
3. **Export enum-collection elements as instances instead of widening `coerceCollection()`**
   (pure inversion of the old accepted set). Rejected: the common case — an enum collection bound
   for JSON — would emit PHP objects into a "plain" array, and the asymmetry with top-level enums
   (values there, objects here) is indefensible at a code review. The widening is small, additive,
   and independently useful.
4. **Pass pure enums through as instances** (exact inversion, no refusal). Rejected in §3: the
   failure just moves into `json_encode()` and loses its path on the way. Refusing at the boundary
   with the path is this group's stance everywhere else.
5. **A compiled export closure alongside ADR-0013's.** Rejected for now: no measured hot path, a
   second parity surface to hold still, and the interpreter's export cost is dominated by the same
   reflection-cache reads hydration already pays. Revisited if the recorded benchmark number ever
   matters to a consumer (ADR-0040's discipline).
6. **A `Dehydrator` sibling class instead of `Hydrator::extract()`.** Rejected: the two
   directions share the cache, the metadata, the path grammar and the read-back loop; a second
   class would share all of that through a third place or duplicate it. One engine, two verbs —
   `withChanges()` already set the precedent.
