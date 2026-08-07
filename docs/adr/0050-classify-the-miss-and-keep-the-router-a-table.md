# ADR-0050: Classify the miss, and keep the router a table

- **Status:** Accepted
- **Date:** 2026-08-07
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item **11.2** · spec r3 **FR-38**, suite **T-11** (RFC-0002) ·
  [ADR-0049](0049-state-the-transport-policy-explicitly-and-bound-the-whole-request.md)
  (`HttpException` unsealed — the reason these two exceptions can exist) ·
  [ADR-0025](0025-typed-http-wrappers-that-refuse-rather-than-coerce.md) (refuse rather than
  coerce, applied to path parameters) ·
  [ADR-0036](0036-refuse-the-downgrade-and-the-characters-parse-url-launders.md) (RFC 3986 case
  rules — scheme and host fold, paths do not) ·
  [ADR-0044](0044-the-write-builder-querybuilder-never-had-and-one-allowlist-for-both.md) (the
  catalogue's first entry; the same "the taxonomy was missing the pattern" finding) ·
  RFC-0001 Alternative #3 (PSR-7/PSR-15) · pattern doc
  [`endpoint-kernel.md`](../patterns/endpoint-kernel.md) · RFC 9110 §15.5.6 (`Allow`)

## Context

The surveyed estate routed with the filesystem: **37 deployed folders**, each holding an
`index.php` differing from its neighbours in one line — the controller it instantiated. Every
cross-cutting concern lived thirty-seven times, and a fix applied where a bug was found was not
applied to the other thirty-six.

FR-38 asks for the table those files collapse into: method + path matching with `{param}`
extraction, **404 versus 405 with `Allow`**, callable handlers, and four stated non-goals.

The requirement worth reading twice is the classification. "It did not match" is one outcome to
a naive implementation and two outcomes to a correct one, and the difference is not cosmetic:
RFC 9110 §15.5.6 says an origin server **MUST** generate an `Allow` header on a 405, so a
router that cannot distinguish the cases makes the application unable to comply with a header
it is required to send.

## Decision

**The router is a table, and a miss is classified.**

- `RouteNotFoundException` (404) and `MethodNotAllowedException` (405) are distinct types, both
  under `HttpException` — possible because ADR-0049 unsealed it one item earlier. The 405
  **carries the allowed methods**, uppercased and sorted, with `allowHeader()` returning the
  header value directly, so the mandatory header cannot be forgotten or recomputed wrongly by
  whoever catches it. `allowedMethodsFor()` exposes the same list without an exception, for
  `OPTIONS`.
- **Matching is by exception, not by a result object.** Consistent with RFC-0002's stated error
  model, which named both exception types; the alternative is recorded below.
- **A placeholder is one segment** (`[^/]+`, never `.+`), so `{id}` cannot swallow separators
  and route `/orders/42/lines/7` to `/orders/{id}`.
- **Percent-decoding happens after the match, never before.** A path arriving with `%2F` must
  not have it turned into a `/` while segments are being counted — that lets one parameter
  forge a segment boundary and reach a route it was not given. The routing decision is made on
  the bytes the client sent; the captured value is decoded afterwards.
- **Literal path text is `preg_quote`d**, so `/files/report.txt` matches that path and not
  `/files/reportXtxt`. A route table is not a place to write regular expressions by accident.
- **Registration refuses rather than overwrites**: a duplicate method+path, a relative path, or
  a repeated placeholder name throws. A silently overwritten route resolves by include order,
  which is the kind of defect that appears only in the environment where the order differs.
- **Paths are case-sensitive, methods are not.** RFC 3986's rule, and the same line ADR-0036
  drew for `Url`.
- **Trailing slashes are equivalent** (`/orders/` is `/orders`), at registration *and* at match
  time — so the duplicate check sees through the difference too. The root stays `/`.
- **Non-goals, stated as decisions**: no middleware pipeline (PSR-15 is defined in PSR-7 terms,
  and the bridge is the only sanctioned crossing — RFC-0001 Alternative #3); no route caching
  (NFR-11 budgets a 50-route match in microseconds, and a cache is a second source of truth to
  invalidate); no attribute discovery (it trades the explicit table for a scan, and the table is
  the value); **no implicit `HEAD`→`GET` fallback** (a router that answers a method nobody
  registered is guessing; the caller gets the `Allow` list instead).

**Front Controller is adopted into the catalogue**, and — as with Table Data Gateway at
ADR-0044 — the pattern was **missing from the taxonomy**, so `design-patterns.md` gains the row
as well. The ~20-line kernel it names is written out in
[`endpoint-kernel.md`](../patterns/endpoint-kernel.md), because the pattern's value is the file
that stops being copied thirty-seven times, and a catalogue row cannot show that.

## Alternatives Considered

- **Return a result object instead of throwing** (`match()` yields matched / not-found /
  not-allowed). Genuinely attractive: a 404 is an expected outcome of dispatch, not an
  exceptional one, and exceptions-as-control-flow is a smell. Rejected because RFC-0002's error
  model named both exception types and consistency across the library's HTTP surface is worth
  more than the purity here — every other refusal in this group throws. `allowedMethodsFor()`
  exists so the common non-exceptional question ("what may I do with this path?") does not need
  a `try`.
- **One `RoutingException` with a status code.** Rejected: it makes the caller inspect a field
  to decide which `catch` body applies, which is what distinct types are for, and it is one
  `if` away from collapsing back into an undifferentiated 404.
- **Implicit `HEAD` → `GET`.** Common, convenient, and rejected: this is a library router, not
  an HTTP server, and the server in front of it is where that equivalence belongs. Answering a
  method nobody registered would also make the `Allow` list a lie.
- **Normalizing case in paths.** Rejected: RFC 3986 makes paths case-sensitive, and folding them
  would silently merge `/Orders` and `/orders` into one route — a decision the application may
  reasonably want either way, and not one a router should take unasked.
- **Refusing trailing slashes instead of normalizing them.** Considered, because normalization
  is a form of guessing and this library refuses to guess elsewhere. Rejected on the asymmetry
  of consequences: the two spellings address the same resource in every deployment, so treating
  them as different produces a 404 nobody intended, while treating them as the same has no
  failure mode anyone has to reason about.
- **A compiled/cached route table** (the FastRoute shape). Rejected as a non-goal above; worth
  revisiting only against a measured NFR-11 miss, which item 11.5 will measure.

## An existing guard fired, and it was right to

The full suite went red on `IdentifierTest::testThePatternAppearsExactlyOnceInTheProductionTree`
— item 10.4's mechanism assertion that the **SQL** identifier allowlist exists in exactly one
class, implemented as a text scan for `A-Za-z_][A-Za-z0-9_]`. The router's placeholder-name
pattern contained that substring.

It is a false positive in intent — a route placeholder name is not a SQL identifier, and
widening one has no bearing on the other — but the resolution mattered more than the diagnosis.
**Loosening the scan was rejected.** The obvious fix, matching only the anchored form
(`…\z`), would stop seeing an *unanchored* copy of the allowlist — which is precisely
[ADR-0015](0015-identifier-allowlist-closed-keywords-and-the-anchor-hole.md)'s original bug, so
the guard would lose exactly the case it is most valuable for.

The router's own pattern is spelled `[A-Za-z_]\w*` instead, equivalent in a non-unicode pattern
and textually distinct, with the reason written at the constant. The cost is one non-obvious
spelling in this file; the alternative was a permanently weaker guard on a SQL-injection
surface. Recorded because the next unrelated file needing an identifier-shaped character class
will meet the same guard, and should reach the same conclusion rather than re-arguing it.

## Consequences

**The 37 folders become one table and one kernel**, and everything cross-cutting is written
once. The kernel doc names exactly what belongs in it and what does not, so the pattern does
not grow back into per-endpoint duplication in a new shape.

**Two more exception types in the hierarchy**, both leaves under the now-extensible
`HttpException`. `ExceptionHierarchyTest` pins the full set and the finality of every leaf, so
they were added there in the same change rather than discovered later.

**The router owns no dependency on `Container`.** Handlers are `callable`, which includes
`[$object, 'method']`, so a container-built table works without the router knowing containers
exist — no new deptrac edge.

**T-11 is the matrix, and its shape is the point.** A suite that only tested hits would pass on
a router that answered 404 to everything it did not serve, which is precisely the defect FR-38
was written against. The misses therefore get as many cases as the hits, and the `Allow` list is
asserted by value.

**What this router does not do is now written down**, which is the part most likely to be
re-litigated. Each non-goal has a reason attached rather than an omission, so the next person to
want middleware has something to argue with.

## References

- Spec r3 **FR-38**, suite **T-11**; [RFC-0002](../rfc/0002-application-layer-groups-from-legacy-intake.md)
- RFC 9110 §15.5.6 (405 responses and the mandatory `Allow` header), RFC 3986 §6.2.2.1 (case
  normalization)
- `src/main/php/d4np/utils/Http/{Router,MatchedRoute}.php`,
  `src/main/php/d4np/utils/Support/{RouteNotFoundException,MethodNotAllowedException}.php`
- [`docs/patterns/endpoint-kernel.md`](../patterns/endpoint-kernel.md) — the front controller
  these routes plug into
