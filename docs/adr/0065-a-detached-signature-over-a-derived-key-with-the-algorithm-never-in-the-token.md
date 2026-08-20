# ADR-0065: A detached signature over a derived key, with the algorithm never in the token

- **Status:** Accepted
- **Date:** 2026-08-20
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item **14.4** · issue [#92](https://github.com/danielPoloWork/egl-util-php/issues/92) ·
  spec **r20 FR-48** · [RFC-0003](../rfc/0003-post-1-0-functional-scope.md) (the design this
  realizes) · [ADR-0054](0054-authenticated-encryption-with-fixed-lengths-and-a-key-only-secretkey-can-produce.md)
  (the `v1.` token grammar reused, and the fixed-length finding §3 applies) ·
  [ADR-0027](0027-constant-time-comparison-is-asserted-by-mechanism-not-by-timing.md) (why §5's assertions are
  mechanisms) · [ADR-0062](0062-the-clock-seam-ships-both-halves-and-support-gains-its-first-outward-edge.md)
  (the clock the expiry is measured against) ·
  [ADR-0022](0022-argon2id-by-default-with-a-fallback-decided-at-construction.md) (the dead-defensive-code
  precedent §6 follows) · [ADR-0048](0048-prefix-internal-calls-by-rule-because-a-hot-loop-cannot-be-tuned-by-hand.md) (the prefixing
  that caused [BUG-0001](../bugs/2026/08/BUG-0001-constant-time-registry-blind-to-prefixed-calls.md)) ·
  [ADR-0059](0059-freeze-the-api-at-1-0-0-with-internal-symbols-outside-the-frozen-surface.md)
  (`@internal` outside the frozen surface, which §6's extraction relies on)

## Context

Signed URLs and webhook signatures are, by the Senior Security Engineer's account in the 2026-08-09
review board, the most-copied security snippets in enterprise PHP — and the copies fail the same two
ways every time: `sha1($secret . $message)` for the digest, and `$expected === $actual` for the
check. The first is a keyed-hash construction whose weaknesses HMAC exists to remove. The second
short-circuits on the first differing byte, leaking the matching prefix length through timing, which
is enough to reconstruct a signature byte by byte given enough attempts.

The library already owns the pieces: `SecretKey` as the only way key material exists (ADR-0054),
`hash_equals()` as the house comparator with a registry asserting it (ADR-0027), and since item 14.1
a clock seam that makes expiry testable without sleeping (ADR-0062). What RFC-0003's FR-48 left open
were four questions the implementation had to answer, and one it did not anticipate.

## Decision

### 1. The token is a detached signature, not a container

`sign()` returns `"v1." . base64url(expiry ‖ mac)`; the message stays where it already lives.

Both use cases want this. A webhook signature travels in a header beside a body the library never
sees. A signed URL carries its signature in one query parameter rather than re-encoding the whole
URL inside itself. A container format would have to be given the message back at verify time
anyway — `verify(string $message, string $token)` — so carrying it inside would duplicate it on the
wire for nothing.

The prefix-plus-base64url grammar is ADR-0054's, reused deliberately so this group has **one** token
shape rather than two that look alike and differ in detail.

### 2. The MAC covers the expiry, and the expiry is fixed-width

The expiry travels in the token, so it must be authenticated. An implementation that signed only the
message would leave those eight bytes unattested, and extending a signed URL's life would be a
matter of editing them — the signature would still verify, because it never spoke for them.

The width is fixed at eight bytes precisely so `expiry ‖ message` needs no delimiter. With a
variable-width prefix, `1 ‖ "23"` and `12 ‖ "3"` produce identical bytes to sign and therefore an
identical MAC: the canonicalization bug that has broken more than one signing scheme. Fixed width
makes the concatenation unambiguous by construction rather than by a separator someone could later
decide to trim.

Timestamp `0` is the sentinel for "never expires", and `sign()` **refuses** an expiry that resolves
to it or below rather than encoding one — otherwise a clock set before 1970 turns a bounded token
into an eternal one. Likewise a TTL that does not move time forward is refused rather than honoured:
an inverted or zero `DateInterval` mints a token already past its expiry, which surfaces later as a
verification failure indistinguishable from a wrong key.

### 3. The expected payload length comes from the allowlist, never from the token

`ALGORITHMS` maps each accepted algorithm to the exact byte length of its raw digest, and `verify()`
requires the payload to be exactly `8 + macBytes`.

This is ADR-0054's finding applied one class over. That ADR probed `openssl_decrypt()` and found its
GCM tag check accepts a **correct prefix** of a real tag at any length down to one byte — so a token
format whose authenticator length varies hands an attacker the lever GCM was supposed to remove.
`hash_equals()` compares lengths first and so does not have that flaw, but resting the property on
*which comparator happens to be in use* is weaker than pinning it structurally. The length a token is
checked against is the table's.

### 4. The algorithm is never read from the token, and the MAC key is derived

Two decisions about what the key and the algorithm are.

**The algorithm is instance state, chosen at construction from the allowlist.** This is worth stating
as a prohibition rather than a preference: a token format that names its own algorithm lets the
attacker choose how their forgery will be checked, which is the JWT `alg`-confusion class of
vulnerability. The `v1.` prefix is a *format* version, not an algorithm field. The consequence is
accepted openly — a deployment that changes algorithm invalidates its outstanding tokens rather than
trusting them, and there is a test pinning exactly that. Key identifiers, when the `SecretKeyRing`
issue (#114) lands, arrive as ADR-0054 planned: a `v2.` grammar, while `v1.` tokens keep verifying.

**The MAC key is `hash_hkdf(algorithm, secret, 0, 'egl/utils:hmac:v1')`, not the caller's bytes.**
The deployment this protects is the common one: a single `APP_SECRET` wired into everything, so the
same 32 bytes reach both AES-256-GCM in `Crypto` and HMAC here. Nobody has demonstrated a break from
that pairing, but *"no published attack"* is a weaker property than *"the two primitives never see
the same key"*, and the stronger one costs one hash at construction.

The alternative was documenting **"do not reuse a `SecretKey` between `Crypto` and `Hmac`"**, and it
was rejected for the reason this library rejects it everywhere else: that is a correctness
requirement placed on the caller, and a caller who misses it gets no error. The cost is honest and
recorded — the label is part of the `v1.` grammar, so a verifier in another language needs it. That
verifier already needed the payload layout, since `expiry ‖ mac` is not a bare HMAC either.

### 5. `verify()` authenticates before it reads, and throws rather than returning `bool`

The MAC is checked first; only then is the expiry decoded and compared. Reversing the order means
acting on bytes an attacker supplied that nothing has vouched for, and lets the failure message
distinguish "expired" from "forged" for a token whose MAC was never valid. As written, reaching the
expiry check is itself proof the token was signed with this key.

`verify()` returns `void`. RFC-0002 named `bool|string` as the anti-requirement for
`Crypto::decrypt()` and the reasoning is identical: `if ($hmac->verify(...))` is a check a caller can
forget to write, and a caught exception is not.

The expiry boundary is **inclusive-expired** — invalid at the instant it names, following RFC 7519's
`exp` semantics. Either answer is defensible, which is exactly why it is pinned by a test rather
than left to drift.

### 6. `Base64Url` is extracted rather than copied

`Crypto` held private `base64UrlEncode`/`base64UrlDecode`; this class needs the identical pair. They
move to a shared `@internal` `Security\Base64Url` — `@internal` for the reason `SecretKey::bytes()`
is, so a token-format detail does not become a general-purpose encoder on the frozen public surface
(ADR-0059).

The extraction is the point, not tidiness. Item 10.4 shipped `MutationBuilderTest` with its own
ten-payload identifier corpus while `QueryBuilderTest` held nineteen, and **both suites were
green** — the newer of two copies held to the weaker rule and nothing could see it. The decoder here
carries load-bearing reasoning (`base64_decode()`'s strict mode already rejects every malformed
shape, so the `preg_match()` alphabet guard an earlier version added was dead code and was removed,
ADR-0022's precedent). A second copy that drifted from it would still round-trip every token its own
tests fed it.

Similarly, `Hmac` hand-rolls its eight-byte big-endian codec instead of `pack('J')`/`unpack('J')`:
`unpack()` returns `array|false`, and the `false` branch would be a guard that provably cannot fire
on an eight-byte input — the dead defensive code ADR-0022 removed from `Hash` and item 12.1 removed
from `Crypto`.

## Alternatives Considered

- **A self-contained token carrying the message** — rejected in §1: `verify()` is given the message
  anyway, so this duplicates it on the wire and makes a signed URL contain a copy of itself.
- **An algorithm field in the token** — rejected in §4. It is the one alternative that is not a
  trade-off but a vulnerability, and it is common enough in the wild to deserve naming.
- **Documenting "do not share a `SecretKey` between `Crypto` and `Hmac`"** — rejected in §4, for
  pushing a correctness requirement onto callers with no error when it is missed.
- **A hex digest** — rejected: it doubles the payload, and a hex MAC compared case-insensitively
  would accept two spellings of one signature.
- **`bool` from `verify()`** — rejected in §5; RFC-0002's standing anti-requirement.
- **Copying the base64url helpers** — rejected in §6, on item 10.4's evidence.
- **A construction-time `ext-hash` guard**, mirroring `Crypto`'s `ext-openssl` check — rejected as
  unreachable: `ext-hash` has been non-optional core since PHP 7.4. Said out loud in the class,
  because an absent guard next to a sibling that has one reads as an oversight.

## Consequences

- `Security\{Hmac, Base64Url}`; `SecretKey::bytes()`'s `@internal` note widened to name `Hmac` and to
  record that it does *not* use those bytes as the MAC key. **No new deptrac edge** — `Security` has
  had `Psr` since `Hash`'s PSR-3 logging and `Support` since always, so the clock costs nothing
  architecturally (390 allowed, 0 violations, 0 uncovered). Stated because an absence leaves no
  trace, item 12.3's rule.
- **Two mechanism assertions, as the item required, plus one this class needed** (ADR-0027): that
  `hash_hmac()` receives the validated property rather than a parameter; that the payload length
  comes from the allowlist rather than the token; and that the MAC is compared before the expiry is
  read. The comparator assertion lives where the library keeps it — `ConstantTimeComparisonTest`'s
  registry — and item 14.4 registered the new path there.
- **A conformance vector pins the whole `v1.` grammar** (HKDF label, expiry width and position, raw
  digest, unpadded base64url) against a fixed key and message. A round-trip test passes for any
  self-consistent format; this is what makes a silent grammar change a failing test instead of a
  fleet of tokens that stop verifying after a deploy. It is the only test that catches a changed
  HKDF label — verified by planting one.
- **15 planted defects, 15 caught**, and the distribution is the interesting part. Three were caught
  by exactly one test each, and in each case by a mechanism assertion or the vector: reading the
  expiry before the MAC, taking the payload length from the token, and changing the HKDF label. One
  more — computing the MAC over the message alone, symmetrically in both `sign()` and `verify()`, so
  every round trip still succeeds — was caught by exactly one behavioural test, the one written for
  it. Substituting `===` for `hash_equals()` was caught **only** by the registry and the ordering
  assertion, by no behavioural test at all, which is ADR-0027's premise demonstrated rather than
  argued.
- **The item found a live defect in the safety net it was supposed to be held to**, recorded as
  [BUG-0001](../bugs/2026/08/BUG-0001-constant-time-registry-blind-to-prefixed-calls.md) and fixed
  here. `ConstantTimeComparisonTest`'s completeness guard tokenized for `T_STRING`, and ADR-0048 had
  prefixed the whole tree at item 10.12, so `\hash_equals` became `T_NAME_FULLY_QUALIFIED` and the
  scanner saw **0 of 3** comparisons. With the scanner in that state, an unregistered
  `$expected !== $mac` on a secret path produced `OK (5 tests, 15 assertions)`. Item 10.12's audit
  had checked the other source-inspecting tests and cleared them as matching "patterns, not
  spellings" — this one matched a *token type*, a third category that audit did not have.
- **The asymmetry worth carrying beyond this repo:** ADR-0048 turned one mechanism assertion **red**
  (`NativeSessionApiTest`, fixed within the item) and another **green**. The red one was found in
  minutes. The green one survived ten items and two security items, because a test that passes
  because it found nothing to check is indistinguishable, from the outside, from one that passes
  because everything checked out. A repaired guard therefore now also asserts it can *see* at least
  as many comparisons as are registered.
- `Str::ulid()`'s optional-clock shape is followed: the clock defaults to `SystemClock` so
  `new Hmac(SecretKey::generate())` is the whole of the common case, and no NFR budget is claimed —
  RFC-0003's reasoning for the clocks applies (a number here would bound PHP's own dispatch), and
  ADR-0040 reserves spec numbers regardless.
