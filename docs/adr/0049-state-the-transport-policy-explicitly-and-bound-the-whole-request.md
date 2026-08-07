# ADR-0049: State the transport policy explicitly, and bound the whole request

- **Status:** Accepted
- **Date:** 2026-08-07
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item **11.1** (security) · spec r3 **FR-37** (RFC-0002) · suite **T-07**
  (item 11.4, the live-origin half) ·
  [ADR-0036](0036-refuse-the-downgrade-and-the-characters-parse-url-launders.md) (`Url`, which
  makes the estate's downgrade impossible) ·
  [ADR-0026](0026-session-hardening-as-a-value-and-csrf-through-a-seam.md) (policy-as-value plus
  a seam — the shape reused here) ·
  [ADR-0025](0025-typed-http-wrappers-that-refuse-rather-than-coerce.md) (header injection refused
  at set time, applied here to outbound headers) ·
  [ADR-0004](0004-root-the-exception-hierarchy-on-an-interface.md) (**amended**: `HttpException`
  becomes an extension point) · RFC-0001 Alternative #3 (why not PSR-18)

## Context

The surveyed estate reached its backend through a helper that did two things in three lines:
it rebuilt every address as `"http://{$host}{$path}"` — discarding the scheme, so `https`
became plaintext — and read it with `file_get_contents()` and no timeout. FR-37 exists to
replace both, and `Url` (ADR-0036) already removes the first.

What the second needs is less obvious than "pass a timeout", and four probes settled it before
any code was written:

| Probe | Result |
|---|---|
| What `ssl` options does a fresh `stream_context_create()` carry? | **None at all** |
| Can a process-wide default weaken them? | Yes — `stream_context_set_default(['ssl' => ['verify_peer' => false]])` applies to any context that does not state its own |
| Does an explicit option in our context beat that default? | **Yes**, ours wins |
| Does the wrapper's `timeout` cover connect, or only read? | **Both** — a 2 s timeout cut a hanging connect at 2.01 s, with `default_socket_timeout` at 5 s |

The first three are the security case. PHP has verified certificates by default since 5.6, so
"we rely on the default" *looks* safe — but the default is process state, and a single
`stream_context_set_default()` in a host application's bootstrap silently turns verification
off for every library that inherits it. That is precisely the kind of legacy bootstrap this
library is written for.

The fourth is the honest limit: **one timeout knob, applied per phase.** It re-arms on every
read, so an origin that sends one byte inside each window holds a `file_get_contents()` call
open forever. A per-phase timeout is not a bound on the request.

## Decision

**`HttpClient` states its transport policy explicitly in every context it builds, and bounds
each request twice — per phase and in total.**

- **TLS is written out, never inherited**: `verify_peer`, `verify_peer_name`,
  `allow_self_signed => false`, `SNI_enabled`, `disable_compression`. Measured to override a
  weakened process default.
- **Two time limits.** `timeout` is the per-phase value PHP understands; `totalTimeoutSeconds`
  is a wall-clock deadline enforced by the transport's own read loop. A client cannot be
  constructed without both — a non-positive value, or a total below the per-phase value, is
  refused at construction, because a timeless client is the defect this class replaces.
- **`ignore_errors => true`, and a response is a result.** Any status the origin produced is
  returned as an {@see HttpResponse}; `HttpClientException` is raised only when no response is
  produced at all. Without the flag the wrapper returns `false` for 4xx/5xx and the body is
  lost; with it, the caller decides whether a `404` is a failure, which is the only place that
  knowledge exists.
- **Redirects are not followed by default** (`follow_location => 0`). A silently-followed
  redirect is how a request to an allow-listed host ends up elsewhere, and how a POST body is
  replayed against an origin the caller never named. Opt in per client.
- **`http` and `https` only**, refused before a socket is opened, and outbound header names and
  values are validated — CR, LF or NUL in a value is refused, which is ADR-0025's stance
  applied on the way out.
- **The policy is a pure value** (`contextOptionsFor()`) and the network sits behind a
  `Transport` seam. This is ADR-0026's shape and the reason its guarantees are testable at all:
  a request that succeeds against a cooperative server proves nothing about whether
  verification was ever switched on.

**`HttpException` becomes an extension point** (amending ADR-0004's "concrete leaves are
final") and `HttpClientException` is its first leaf. The group now has two failure kinds that
callers must be able to tell apart — a caller's shape error versus the network — while both
stay catchable as `HttpException`. The same shape `HydrationException` already had; the
hierarchy test's pinned set and finality list were updated in the same change, so the shift is
recorded rather than discovered later.

**Not PSR-18**, per RFC-0001 Alternative #3: that interface is defined in PSR-7 messages, and
requiring PSR-7 of everyone who wants a timeout is the coupling this library's HTTP stance
avoids. An adapter over this class remains available to any consumer who wants one.

## Alternatives Considered

- **Rely on PHP's default TLS verification.** Rejected on the probe: the default is process
  state a host application can change, and inheriting it means the library's security posture
  is decided by whichever bootstrap file ran first.
- **`file_get_contents()` with the `timeout` option.** Simplest, and the shape the estate used.
  Rejected once the timeout's semantics were measured: per-phase re-arming means a dripping
  origin is unbounded, so the "we have a timeout now" fix would have left the original hang
  reachable.
- **Throw on 4xx/5xx.** Rejected: it makes the common case (probing an endpoint that may
  legitimately 404) exception-driven, and it discards the response body exactly when the caller
  most needs it for diagnosis. `isSuccessful()` gives callers the shorthand without taking the
  decision.
- **Follow redirects by default**, as browsers and most clients do. Rejected for a
  server-to-server client: the convenience is small and the failure mode — a request leaving
  the host the caller allow-listed — is a security one. Available with one constructor
  argument.
- **`HttpClientException extends UtilsException` in the `Http` group**, leaving `HttpException`
  sealed (ADR-0028's placement precedent for `Container`). Rejected because nothing forces it
  here: ADR-0028 moved that class because PSR-11 required an interface `Support` may not see,
  while this exception needs nothing outside `Support`. Sealing would only cost consumers the
  ability to catch the group's failures in one clause.
- **A `HttpMethod` enum** instead of a string method. Deferred, not rejected: ADR-0015's lesson
  favours it, but the method is passed straight to PHP and never parsed or compared here, so
  the enum would add a type without removing a failure. Worth revisiting if the client grows
  method-dependent behaviour.

## Consequences

**The estate's two defects are now unreachable through this class**: the scheme cannot be
dropped (`Url` carries it, and only `http`/`https` are accepted) and a request cannot be
unbounded (both limits are mandatory).

**A read loop instead of a one-liner.** `StreamTransport` opens with `fopen()` and reads
against the deadline, which is more code than `file_get_contents()` and is the only way the
total bound exists. It also reads the status line from `stream_get_meta_data()['wrapper_data']`
rather than the `$http_response_header` variable PHP injects into scope — the same data, as a
local.

**The suite here cannot prove the network half.** Everything asserted in this item is the
policy value and the seam's contract; TLS verification actually rejecting a bad certificate, a
real timeout expiring, a real redirect not being followed — those need an origin, and they are
**T-07**'s (item 11.4). Stated so the coverage is not mistaken for completeness.

**Seven planted defects, seven caught** — verification switched off, the `verify_peer` key
removed entirely, the CRLF header guard disabled, the scheme allowlist opened, `ignore_errors`
dropped, redirects defaulted on, and the timeout guard removed. Worth recording *how* one of
them nearly passed: the first attempt at the CRLF plant did not match the file, the suite went
green, and a green campaign run looks identical to a real one. The plant was confirmed present
before the result was believed — the same failure class item 10.4 named for untracked files,
generalized: **verify the defect landed before trusting that the tests caught it.**

**One `\D4np\Utils\Http\Response` and one `HttpResponse` now coexist.** They are opposite
directions of the same domain — one is built and emitted by a server, the other is received and
read — and merging them would give each audience the other's methods. The names are close
enough to be worth this paragraph.

## References

- Probe results (this ADR's Context table) — `stream_context_create` defaults,
  `stream_context_set_default` precedence, `timeout` phase semantics, the deadline read loop
- `src/main/php/d4np/utils/Http/{HttpClient,StreamTransport,Transport,HttpResponse}.php`,
  `src/main/php/d4np/utils/Support/HttpClientException.php`
- PHP manual: *Context options for the http wrapper*, *SSL context options*,
  `stream_context_set_default()`
