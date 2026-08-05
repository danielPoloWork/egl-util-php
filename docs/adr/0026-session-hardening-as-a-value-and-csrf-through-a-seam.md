# ADR-0026: Session hardening as a value, CSRF through a seam, and a mechanism asserted because behaviour cannot see it

- **Status:** Accepted
- **Date:** 2026-08-05
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 6.2 · spec FR-12, FR-15, §7 T-03 · item **6.3** (the `php -S`
  integration suite this item depends on) · [RFC-0001](../rfc/0001-egl-utils-library.md) R-1
  (placement) · [ADR-0022](0022-argon2id-by-default-with-a-fallback-decided-at-construction.md)
  (the same "make the policy a value" move) ·
  [ADR-0015](0015-identifier-allowlist-closed-keywords-and-the-anchor-hole.md) (closed keyword
  types) · [ADR-0025](0025-typed-http-wrappers-that-refuse-rather-than-coerce.md) ·
  [ADR-0006](0006-shared-reflection-metadata-cache.md) (which refused an interface until a
  consumer needed one)

## Context

Spec FR-15 asks for *"cookie_httponly, cookie_secure, cookie_samesite=Lax at start; regenerate()
wraps session_regenerate_id(true)"*, and FR-12 for *"CSPRNG token generation + constant-time
validation (hash_equals), per-session storage, optional per-form scoping"* — the latter placed in
`Http` by RFC-0001's R-1 note.

One probe determined the entire shape of this item:

| probe | result |
|---|---|
| `session_start()` in CLI | **`false`** |
| `session_set_cookie_params()` in CLI | **`false`** |
| `session_regenerate_id(true)` in CLI | **`false`** |

**Nothing about a live session can be exercised in the unit suite.** Left alone, that would mean
FR-15's three flags and the whole of CSRF validation had no unit assertion at all — everything
resting on item 6.3's integration suite. For CSRF validation in particular that is the wrong place
for the only coverage to live.

## Decision

### 1. The cookie policy is a **value**, not a side effect

`Session::cookieParams()` returns exactly what `start()` will apply and is a pure function of the
constructor arguments. FR-15's three flags become assertable without a live session.

This is the same move ADR-0022 made when `defined('PASSWORD_ARGON2ID')` put the bcrypt-fallback
branch out of reach: separate the **decision** from the **I/O**, test the decision, keep the I/O
thin enough to be covered by an integration suite.

### 2. `CsrfToken` depends on a three-method `SessionStore`, not on `$_SESSION`

The seam exists for one reason — testability of security-critical logic that PHP otherwise makes
untestable. ADR-0006 refused an interface for the reflection cache because no consumer needed one;
here a consumer does, and the interface is kept no wider than that need.

### 3. `secure` defaults to `true`; the opt-out is explicit and narrow

A `Secure` cookie over plain HTTP is never sent, so local development on `http://localhost` would
appear to have no session at all. That is a real need, and the honest answer is a named constructor
argument — **not** auto-detection from `$_SERVER['HTTPS']`, which would silently disable the flag
on any deployment behind a misconfigured proxy. Same shape as `Hash`'s `bcryptFallback`: safe by
default, opt out on purpose, visible in the wiring.

`httponly` has **no** opt-out. No legitimate caller needs the session identifier readable from
JavaScript, and offering the switch would be offering the vulnerability.

`SameSite` defaults to `Lax` rather than `Strict` because `Strict` also withholds the cookie from
ordinary inbound links — a logged-in user following one from an email arrives logged out, which is
the kind of breakage that gets a security control switched off entirely.

### 4. `SameSite` is an enum, which PHPStan pushed us toward and ADR-0015 had already argued for

It began as a validated `string`. PHPStan at max rejected it: `session_set_cookie_params()` is
itself typed against a literal union. A closed keyword set reaching a security-relevant output is
exactly what ADR-0015 made `Sort` and `Operator` enums for, so the same answer applies —
an illegal value becomes a compile-time impossibility instead of a runtime check.

The one constraint the type system *cannot* express spans two arguments: browsers **drop** a
`SameSite=None` cookie that is not `Secure`. That stays a constructor check, so the
misconfiguration surfaces at wiring time rather than as "sessions do not work".

### 5. A token is issued **once per scope** and reused

Regenerating on every render would invalidate the token already sitting in another open tab, and
the usual response to that — users retrying until it works — trains people to ignore the failure
this protects them with. `rotate()` is the explicit call for a privilege transition, where any
token issued to the previous identity *should* stop working.

### 6. Scope names are validated, which is a storage decision as much as a security one

A scope becomes a session-storage key, so a scope taken from user input would let a client grow the
session record one key per request. Scopes are application-chosen labels; anything that does not
look like one is refused rather than trusting every caller to have remembered.

### 7. Constant-time comparison is asserted **as a mechanism**, because behaviour cannot see it

`hash_equals($a, $b)` and `$a === $b` return **identical values for every input**. They differ only
in timing, so no functional assertion can distinguish them — and a PHP-level timing assertion is
too noisy to be anything but flaky.

This is not a theory. Replacing `hash_equals()` with `===` was planted as a probe and **the whole
suite passed**. The single most important line in CSRF validation was unasserted.

So `testValidationComparesInConstantTime()` asserts the mechanism directly, via reflection over the
method's own source. That is the pattern roadmap item **4.6** settled when a saving sat under the
measurement noise floor: *when a property cannot be observed in behaviour, assert the thing that
produces it rather than pretending a behavioural test covers it.* Re-running the probe now fails.

## Alternatives Considered

- **`CsrfToken` reading `$_SESSION` directly** — rejected: it makes the security-critical logic
  untestable outside an integration suite, for no gain.
- **Auto-detecting `secure` from `$_SERVER['HTTPS']`** — rejected in §3: it silently disables the
  flag exactly where a proxy is misconfigured, which is where it is most needed.
- **An `httponly` option** — rejected in §3.
- **`SameSite=Strict` as the default** — rejected in §3; a control that breaks email links gets
  switched off.
- **Accepting lower-case `lax`/`strict`/`none`** (PHP allows them) — rejected: two spellings of one
  policy is a distinction with no meaning.
- **Rotating the token on every successful `validate()`** — rejected in §5: it breaks the second of
  two open tabs.
- **A timing-based test for constant-time comparison** — rejected in §7 as inherently flaky.
- **Accepting that `hash_equals` is untestable and documenting the gap** — rejected. That was the
  first instinct, and item 5.3 already showed where it leads: a gap written up as acceptable is a
  gap nobody closes. The mechanism assertion is crude but deterministic.
- **Capping the number of stored per-scope tokens** — rejected in favour of validating the scope
  name: a cap would silently invalidate live tokens, where a refusal names the actual mistake.

## Consequences

- 51 tests across the three classes; `--group T-03` runs 48. Total 1157.
- **Verified non-vacuous**: a predictable token (5 failures), scope validation removed (14),
  `httponly` off (2), and — after §7 — `hash_equals` → `===` (1, where it previously caught
  nothing).
- **`Session::start()` and `regenerate()` have no unit coverage**, and cannot: PHP refuses to run a
  session in CLI. The cookie *policy* is covered; the *behaviour* — that the cookie really carries
  the flags, that the identifier really changes across `regenerate()` — belongs to item **6.3**'s
  `php -S` suite. Named here, in the class docblock, and in the test file rather than left as a
  silent hole.
- `Session` implements `SessionStore`, so the production wiring is `new CsrfToken(new Session())`
  with no adapter.
- `destroy()` deliberately does not expire the cookie: writing headers is a `Response` concern, and
  this class does not write headers.

## References

- Spec FR-12, FR-15, §7 T-03; RFC-0001 R-1 (why CSRF lives in `Http`)
- ADR-0022 — separating a policy from its I/O so the policy can be tested
- ADR-0015 — closed keyword types instead of validated strings
- ADR-0006 — the interface deferred until a consumer needed it; this is that consumer
- Item 4.6 — assert the mechanism when the property is invisible to behaviour
- Verified directly on PHP 8.3.1: `session_start()`, `session_set_cookie_params()` and
  `session_regenerate_id()` all returning `false` in CLI
