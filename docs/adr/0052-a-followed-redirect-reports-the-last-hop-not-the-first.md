# ADR-0052: A followed redirect reports the last hop, not the first

- **Status:** Accepted
- **Date:** 2026-08-07
- **Deciders:** tech-lead (agent-drafted), maintainer (merge)
- **Related:** ROADMAP item **11.4** (security) · spec **FR-37** (r15) · suite **T-07** ·
  [ADR-0049](0049-state-the-transport-policy-explicitly-and-bound-the-whole-request.md)
  (the transport this corrects) ·
  [ADR-0025](0025-typed-http-wrappers-that-refuse-rather-than-coerce.md) (whose suite tag this
  item reclaims)

## Context

Item 11.4 built T-07: {@see D4np\Utils\Http\HttpClient} driven against a real origin instead of
a fake transport. The first thing it measured was a defect in `StreamTransport`, invisible to
every test written before it.

`fopen()` over the HTTP wrapper exposes the response headers through
`stream_get_meta_data($handle)['wrapper_data']`. For a single exchange that array is a status
line followed by its headers, and the transport read it as exactly that: `array_shift()` for the
status, everything else as the headers.

**When the wrapper follows a redirect, that array holds the entire chain.** Measured against a
live `php -S` origin:

```
[0]  HTTP/1.1 302 Found
[1]  Host: 127.0.0.1:49461
…
[5]  Location: /?mode=target
[6]  Content-type: text/html; charset=UTF-8
[7]  HTTP/1.1 200 OK
…
[12] Content-type: text/html; charset=UTF-8
body: TARGET-REACHED
```

So the object handed to the caller described a response that no longer existed:

| What the caller saw | What was true |
|---|---|
| `status` `302`, `isSuccessful()` `false` | the fetch succeeded; the body is the target's |
| a chain ending in `404` reported as `302` | the request failed, and the failure was invisible |
| `header('Location')` = the redirect target | that redirect had already been followed |
| `header('Set-Cookie')` = the **intermediate** hop's cookie | the response's own cookie sat behind it |

The last row is the one with teeth. A login flow's `302` sets a session cookie, and a caller
reading `header('Set-Cookie')` after a followed redirect would have read the *hop's* cookie while
believing it read the response's — with the two hops' headers merged under one name, first value
wins, and the first value belongs to the wrong response.

Redirect-following is opt-in and off by default (ADR-0049), which is why item 11.1's unit suite
never saw this: with `follow_location => 0` there is only ever one status line, and the fake
transport is handed a synthetic array shaped like the happy case.

## Decision

**The response reported is the last exchange in `wrapper_data`, carrying only its own headers.**

`StreamTransport` walks the array; every status line begins a new response and discards the
headers accumulated for the previous one. The status returned is the last one seen, the headers
are the lines after it.

Two properties are kept deliberately:

1. **The first line must still be a status line.** That strictness is what recognises a stream
   that is not an HTTP response at all, and dropping it in favour of "find any status line
   anywhere" would accept a body that merely contains one. Unchanged from ADR-0049.
2. **Nothing is added to the public surface.** The intermediate hops are dropped, not exposed. A
   caller who needs the chain has a better instrument available: turn following off, which is the
   default, and follow it themselves.

## Alternatives Considered

1. **Leave it and document the behaviour in T-07.** Rejected: it would make a test the record of
   a defect rather than a guard against one, and `isSuccessful()` returning `false` for a
   successful fetch is not a quirk a docblock can make safe. §10 does not permit "fix it in the
   next PR" either.
2. **Expose the whole chain** (`HttpResponse::hops()` or similar). Rejected for now: it is a new
   public surface with no caller asking for it, and the redirect-off default already gives anyone
   who wants the chain a way to walk it deliberately. The door stays open — this decision does not
   foreclose it.
3. **Refuse to follow redirects at all**, deleting the option. Rejected: 11.1 shipped it as an
   opt-in with a stated reason, and removing a public constructor argument is a break that buys
   nothing this fix does not already buy.
4. **Keep the first status but the last headers** (or any other mix). Rejected as incoherent:
   status, headers and body are one response or they are three unrelated facts. The body is
   unavoidably the last hop's — the wrapper gives no other — so status and headers follow it.

## Consequences

**Easier:** a followed redirect now behaves the way a caller reading `status`, `header()` and
`body` would assume; a chain that ends in a failure reports the failure.

**Changed behaviour** (user-visible, and the reason this carries a CHANGELOG entry): code that
had adapted to the old shape — reading `302` and treating the body as the target's payload —
will now read the target's real status. Only requests made with `followRedirects: true` can
observe any difference; the default path produces one status line and is byte-identical.

**Harder / accepted:** the intermediate hops' headers are no longer reachable at all. That is a
deliberate loss of information nobody had asked for, in exchange for a response object that is
internally consistent.

**Verification:** `HttpClientLiveTest::testAFollowedRedirectReportsTheFinalHopNotTheFirst` asserts
both directions — a chain to a `200` reports `200` with no leaked `Location`, and a chain to a
`404` reports `404` — because only the pair distinguishes "reports the last hop" from "reports
whatever status happens to be final". Proved non-vacuous: reverting this decision fails that test
(status), and keeping the status fix while retaining the hops' headers fails it too (the leaked
`Location`).

## References

- ROADMAP item **11.4** (security)
- spec **FR-37** (r15)
- suite **T-07**
- [ADR-0049](0049-state-the-transport-policy-explicitly-and-bound-the-whole-request.md) (the transport this corrects)
- [ADR-0025](0025-typed-http-wrappers-that-refuse-rather-than-coerce.md) (whose suite tag this item reclaims)

## Footnote — the suite name this item had to reclaim

T-07 was already in use as a PHPUnit group on `RequestTest` and `ResponseTest`, tagged by item
6.1 and recorded in ADR-0025 — at a time when the spec defined only T-01…T-05, so the name
belonged to nothing. Spec r3 (RFC-0002) then defined **T-07 = the HttpClient live suite**, and
`--group T-07` began returning 86 tests across three unrelated classes: the spec's named suite
was no longer a countable unit, which is the property item 2.6 established for these groups in
the first place.

The spec owns the suite vocabulary, so the tag was removed from those two classes rather than
this suite renamed. They keep their coverage and are still selectable by name or path; ADR-0025's
verification note is annotated rather than rewritten, since it was true when it was written.
