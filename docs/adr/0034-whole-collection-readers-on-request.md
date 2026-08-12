# ADR-0034: Whole-collection readers on `Request`, because a key-scoped reader cannot enumerate

- **Status:** Accepted
- **Date:** 2026-08-05
- **Deciders:** maintainer (`@danielPoloWork`, who chose this option over three alternatives), agent
  acting as tech-lead
- **Related:** ROADMAP item 8.2 · spec FR-13 ·
  [`docs/specs/02_spec_psr7_bridge.md`](../specs/02_spec_psr7_bridge.md) §3–§4 (the BFR clauses this
  unblocks) · [ADR-0025](0025-typed-http-wrappers-that-refuse-rather-than-coerce.md) (the
  refuse-don't-coerce rule this does **not** retreat from) ·
  [ADR-0033](0033-bridge-source-in-the-monorepo-published-through-a-generated-split-repository.md)
  (the bridge whose implementation surfaced this) · imported
  [ADR-002](../../.specs/d4np_php_adr_002_http_psr7.md)

## Context

Item 8.2 set out to implement `Psr7Bridge` against spec 02's contract and stopped immediately:
**BFR-04, BFR-05, BFR-06 and BFR-07 are not implementable against the core's public API.**

`Request` exposes fifteen public methods, and every collection reader among them is key-scoped:
`queryString($key)`, `queryList($key)`, `postString($key)`, `postList($key)`, `cookie($key)`,
`file($key)`. Only `headers()` returns a whole collection. There is no way to *enumerate* the query,
POST, cookie or uploaded-file collections at all.

Two of the four are not recoverable by any other route:

| collection | recoverable otherwise? |
|---|---|
| query | partly — `uri()` carries the query string and could be re-parsed |
| cookies | partly — the `Cookie` header could be re-parsed |
| **POST** | **no** — `uri()` does not carry a body |
| **`$_FILES`** | **no** — `file($key)` needs a key nobody can guess |

And the two "partly"s are worse than they look: re-parsing would introduce a *second* parsing path
beside the one PHP already ran, which is exactly the hand-rolling this project refuses elsewhere
(ADR-0021 delegates rich-HTML sanitization for the same reason). A bridge that reconstructed `$_GET`
from a URI string would silently disagree with the core's own view whenever a server rewrote a
request.

The maintainer was asked, with the alternatives, and chose to widen the core.

## Decision

`Request` gains four whole-collection readers, each returning its input raw and entire:

```php
public function queryAll(): array;
public function postAll(): array;
public function cookieAll(): array;
public function uploadedFiles(): array;
```

### This is not a retreat from ADR-0025

The obvious objection is that a class whose design is "refuse rather than coerce" now hands back
`mixed` values. The two rules govern different questions.

ADR-0025 is about **scalar** reads. `queryString('email')` refuses an array because a caller asking
for *one string* has been handed something else, and `(string) ['x']` yields the literal `"Array"` —
a value nobody sent, indistinguishable from one somebody did. That is a coercion, and it is what
gets refused.

A whole-collection reader makes no such promise and cannot mislead in that way: a caller asking for
the entire collection is asking for exactly what arrived, values of every shape included. Nothing is
converted, so there is nothing to convert *wrongly*. The typed accessors keep refusing, unchanged —
a test asserts both behaviours side by side so the contrast is stated where someone would otherwise
read the raw reader as a loophole.

The shape is also not new: `headers()` already returned a whole collection and `file()` already
returned a raw `$_FILES` entry, both from the same class, both since item 6.1.

### Copies, not views

PHP arrays are values, so each reader hands back a copy and no caller receives mutable access to a
request's state. This is what makes the bridge's detachment clause (BFR-08) true by construction
rather than by discipline.

### `uploadedFiles()`, not `filesAll()`

The odd name out, deliberately: there is no `file*()` typed accessor for it to be the "all" version
*of*, because an uploaded-file abstraction is precisely what RFC-0001 declined to re-implement. The
name says what it returns rather than implying a family that does not exist.

### No `serverAll()`

Not added, though `$_SERVER` is the fifth constructor argument. Nothing in spec 02's contract needs
it: PSR-7's `getServerParams()` is not among the core-observable projections BFR-20 round-trips, and
everything the core reads out of `$_SERVER` is already exposed through `method()`, `uri()`,
`isSecure()` and `headers()`. Adding it would widen the public surface for no consumer — the API is
widened by exactly what the blocked clauses required and no further.

## Alternatives Considered

- **One export method** (`Request::toArrays(): array{query,post,server,files,cookies}`) — rejected
  by the maintainer: a core method existing *for* the bridge is a coupling smell even where it
  creates no dependency, and it is less useful to ordinary consumers than named readers. Iterating
  POST keys or logging every query parameter are things a consumer wants without a bridge in sight.
- **The bridge takes the arrays alongside the `Request`** — rejected: a caller holds a `Request`
  precisely so they need not handle superglobals, and an omitted argument would mean a silently
  empty POST. Easy to use wrongly is the failure mode this library exists to avoid.
- **Reflection over the private properties** — rejected outright: it breaks encapsulation from
  outside the package and would bind the bridge to the core's field names, which are not public API.
- **Dropping `requestToPsr7()` and shipping a one-way bridge** — rejected: imported ADR-002 promises
  bidirectional conversion, and a one-way bridge is a materially smaller product than the one
  decided.

## Consequences

- Four additive methods on `Request`. **No BC break** — nothing is removed or changed — so this
  needs no MAJOR under the policy ADR-0031 settled, and lands inside the pre-1.0 line as an
  addition.
- Spec 02 §3 gains a note that the bridge depends on these; the clauses themselves are unchanged,
  because the contract was always right about *what* should convert — only about what the core
  already exposed.
- `RequestWholeCollectionTest` pins the parts worth pinning: raw values pass through uncoerced while
  the typed accessors still refuse; the returned array is a copy; integer keys survive (`?0=zero`
  produces one, the same fact that made PHPStan reject an `array<string, mixed>` superglobal type at
  item 6.1).
- The blocked clauses are now implemented and tested: BFR-04, BFR-05, BFR-06 and BFR-07 pass against
  both `nyholm/psr7` and `guzzlehttp/psr7`.

## References

- ROADMAP item 8.2
- spec FR-13
- [`docs/specs/02_spec_psr7_bridge.md`](../specs/02_spec_psr7_bridge.md) §3–§4 (the BFR clauses this unblocks)
- [ADR-0025](0025-typed-http-wrappers-that-refuse-rather-than-coerce.md) (the refuse-don't-coerce rule this does **not** retreat from)
- [ADR-0033](0033-bridge-source-in-the-monorepo-published-through-a-generated-split-repository.md) (the bridge whose implementation surfaced this)
- imported [ADR-002](../../.specs/d4np_php_adr_002_http_psr7.md)
