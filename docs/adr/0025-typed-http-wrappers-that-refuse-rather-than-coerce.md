# ADR-0025: HTTP wrappers that mirror PSR-7's naming, and refuse rather than coerce

- **Status:** Accepted
- **Date:** 2026-08-04
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 6.1 (opens Milestone 6) · spec FR-13, FR-14 · imported **ADR-002**
  (HTTP / PSR-7) · [RFC-0001](../rfc/0001-egl-utils-library.md) §Alternatives 3 ·
  [ADR-0004](0004-root-the-exception-hierarchy-on-an-interface.md) ·
  [ADR-0019](0019-four-escaping-contexts-and-the-unquoted-attribute-assumption.md) (why the HTML
  body is not escaped here) · item 6.2 (`Session`/`CsrfToken`, same group)

## Context

Spec FR-13 asks for a *"typed superglobal reader (`$_GET`/`$_POST`/`$_SERVER`/`$_FILES`)"* and
FR-14 for *"headers/JSON/status helpers"*, both with an *"optional PSR-7 bridge per imported
ADR-002"*.

RFC-0001 §Alternatives 3 already settled the shape and its reasoning is worth restating, because it
constrains everything below: implementing PSR-7 here was rejected (streams, immutability and
uploaded files are a solved project, and re-doing them adds maintenance without differentiation),
and exposing PSR-7 types *only* was rejected too (it forces factory wiring and stream handling on
framework-less users who want `$request->postString('email')`). The chosen shape is **native
lightweight wrappers mirroring PSR-7 naming**, with `egl/utils-psr7-bridge` as the only sanctioned
crossing point — and the RFC adds that the wrappers **never grow middleware ambitions**.

Four things were probed before writing anything:

| probe | result |
|---|---|
| `?role[]=admin` | `$_GET['role']` is an **array** — the client chooses the PHP type |
| `getallheaders()` | **not defined** outside Apache-like SAPIs |
| `?0=zero` | produces an **integer** array key |
| `filter_var('', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)` | **`false`**, not `null` |

## Decision

### 1. The typed accessors refuse rather than coerce

This is the security decision in `Request`. `?email=x` yields a string; `?email[]=x` yields an
array — **the same key, a different PHP type, chosen by whoever wrote the query string**. A
`(string)` cast on that emits *"Array to string conversion"* and produces the literal `"Array"`;
`implode()` invents a value nobody sent. Both convert attacker-controlled *shape* into a value the
application then trusts, which is the parameter-pollution family.

So a scalar accessor handed a non-scalar returns its **default**, exactly as if the key were
absent — the honest answer, because a string is not what arrived.

The same reasoning applies within scalars: `queryInt()` uses `FILTER_VALIDATE_INT`, not a cast,
because `(int) "12abc"` is `12` — a value the client never sent. `queryBool()` uses
`FILTER_VALIDATE_BOOLEAN` with `FILTER_NULL_ON_FAILURE`, the same coercion `Env::get()` uses, since
an unchecked cast makes the *string* `"false"` true.

**`queryList()`/`postList()` exist for the case where a list is genuinely expected.** A caller
asking for a list has *decided* a list is acceptable here, which is a different decision from being
handed one unexpectedly. They refuse scalars rather than wrapping them, because wrapping would
erase the distinction they exist to preserve.

### 2. Only `fromGlobals()` touches a superglobal

Everything else is a pure function of the constructor arguments. That is what makes a request
testable without a web server, and what lets the bridge construct one from a PSR-7 message.

### 3. Headers come from `$_SERVER`, not `getallheaders()`

`getallheaders()` does not exist outside Apache-like SAPIs — verified absent on this CLI build — so
`$_SERVER` is the portable path. `HTTP_X_FORWARDED_FOR` → `x-forwarded-for`; `CONTENT_TYPE` and
`CONTENT_LENGTH` are included despite carrying no `HTTP_` prefix, because CGI reports those two
without one and a prefix-only rule silently loses them.

**`isSecure()` deliberately ignores `X-Forwarded-Proto`.** That header is client-supplied unless a
trusted proxy rewrote it, and this class cannot know whether one did — trusting it would let any
client claim HTTPS. It does check for the string `'off'`, because `$_SERVER['HTTPS']` is present
and set to `'off'` on some servers, so an `isset()` check reports every such request as secure.

### 4. `Response` is immutable with `with*()`; `Request` is not

A response is *built*, usually in stages and often across layers, and the alternative to
immutability is an object a helper can change behind its caller's back. A request is only ever
read. `Request` therefore has no withers — which also keeps it clear of the middleware ambitions
RFC-0001 warned against.

### 5. Header names are case-insensitive but remember their spelling

RFC 9110 makes field names case-insensitive, so `Content-Type` and `content-type` must not become
two headers — **a duplicated `Content-Type` is how a response smuggles a second interpretation past
a proxy**. The original casing is kept for output because some clients are, in practice, less
tolerant than the specification.

### 6. CR, LF and NUL in a header value are refused, at the point they are *set*

A CR or LF ends the header line early and lets everything after it be read as further headers or as
the body — **response splitting**. Modern PHP's `header()` rejects these itself, but validating at
`withHeader()` rather than at `send()` means a response assembled and inspected in a test fails the
same way as one sent to a client. Refused rather than stripped, so the caller learns their value was
not what they thought.

### 7. `Response::html()` does not escape the body

Escaping is a render-time decision that depends on where each value lands (ADR-0019's four
contexts). A blanket `htmlspecialchars()` over an assembled document would corrupt the markup it
exists to carry. `Response::json()` *does* go through `Json::encode()`, so an unencodable value
raises rather than silently putting `false` in the body (RFC-0001 R-7).

## Alternatives Considered

- **Implementing PSR-7, or depending on an implementation** — settled by RFC-0001 §Alternatives 3
  and imported ADR-002; restated in Context because it constrains the rest.
- **Coercing arrays to strings** (`implode`, or `(string)`) — rejected in §1: it manufactures a
  value the client did not send from a shape the client controls.
- **Throwing on a type mismatch** instead of returning the default — rejected: absent input is
  normal in HTTP, and a reader that throws on ordinary traffic would be wrapped in `try` at every
  call site until someone caught `Throwable` around the lot.
- **Wrapping a scalar into a one-element list** in `queryList()` — rejected in §1.
- **Trusting `X-Forwarded-Proto`, or a `trustedProxies` option** — rejected for this item: the
  option is real and belongs with a considered proxy story, not smuggled into a superglobal reader.
- **`getallheaders()` with a `$_SERVER` fallback** — rejected: two code paths where one works
  everywhere, and the rarely-taken branch is the one that rots.
- **Escaping in `Response::html()`** — rejected in §7.
- **A `Stream` body** — rejected: that is the PSR-7 surface this library declined to re-implement.

## Consequences

- 72 tests across the two classes; `--group T-07` runs them as a unit. Total 1109.
- **Verified non-vacuous**, and one probe had to be redone: flattening arrays (6 failures), a cast
  instead of `FILTER_VALIDATE_INT` (6), case-sensitive header storage (8), and removing the CR/LF
  check (4). That last probe **initially reported a pass** because the string replacement had not
  matched the source at all — a probe that does not apply is not evidence, and it was re-run
  against the verified line before being believed.
- **PHPStan at max rejected a type annotation that was a lie.** The superglobals were declared
  `array<string, mixed>`; `?0=zero` produces an **integer** key, so the honest type is
  `array<array-key, mixed>`. Corrected rather than cast away.
- A PHP surprise is documented where it will be read: `filter_var('', FILTER_VALIDATE_BOOLEAN,
  FILTER_NULL_ON_FAILURE)` is **`false`**, not `null` — so `?flag=` reads as false rather than as
  absent. Following PHP rather than inventing a third answer keeps this consistent with `Env::get()`.
- `file()` returns the raw `$_FILES` entry rather than an object. An uploaded-file abstraction
  (moving, streaming, error codes) is exactly the surface RFC-0001 declined to re-implement, and
  the bridge is where a PSR-7 `UploadedFileInterface` comes from.
- No middleware, no `Stream`, no PSR-7 types. `Session` and `CsrfToken` join this group at item 6.2.

## References

- Spec FR-13, FR-14; RFC-0001 §Alternatives 3 and the imported ADR-002 commitment
- ADR-0019 — why `Response::html()` escapes nothing
- Verified directly on PHP 8.3.1: array-valued query parameters, `getallheaders()` absence,
  integer array keys from `?0=`, and `filter_var`'s empty-string behaviour
