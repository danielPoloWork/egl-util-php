# ADR-0054: Authenticated encryption, with lengths pinned structurally and a key only `SecretKey` can produce

- **Status:** Accepted
- **Date:** 2026-08-07
- **Deciders:** tech-lead (agent-drafted), maintainer (merge)
- **Related:** ROADMAP item **12.1** (security) · spec **FR-40**, **NFR-13**, **T-09** ·
  [ADR-0021](0021-delegate-rich-html-and-escape-like-wildcards-with-a-portable-character.md)
  (missing-optional-dependency refusal, and the untestable-in-CI-precedent) ·
  [ADR-0022](0022-argon2id-by-default-with-a-fallback-decided-at-construction.md)
  (construction-time refusal; the pure-function-extraction pattern) ·
  [ADR-0004](0004-root-the-exception-hierarchy-on-an-interface.md) (the exception family
  `CryptoException` joins) · RFC-0002 Alternatives #6 (defuse/php-encryption, libsodium —
  rejected at the RFC level, not revisited here)

## Context

Spec FR-40: *"`Crypto` + `SecretKey`: AES-256-GCM, versioned `v1.` base64url compact token;
`decrypt()` throws `CryptoException` on any failure; ext-openssl suggested with constructor
refusal when absent; `#[SensitiveParameter]` on secret-bearing signatures."* This replaces the
surveyed estate's cipher helper: AES-256-CBC, no authentication tag, `decrypt()` returning
`bool|string`.

Four probes ran before any class existed, on PHP 8.3.1, and two of them changed the design:

| Probe | Result |
|---|---|
| `openssl_decrypt()` given a **truncated tag** — 8, 4, even 1 byte of the real one | **succeeds**, returns the correct plaintext |
| The same, with a **forged** short tag (random bytes, not a prefix of the real one) | correctly rejected at every length tried |
| `openssl_encrypt('aes-256-gcm', ...)` given an 8, 16, or 24-byte key | **succeeds silently**, no warning, at every length |
| A 16-byte key's ciphertext decrypted via `aes-128-gcm` with the same bytes | fails — not silently degrading to a smaller cipher, simply not checking length at all |
| `#[\SensitiveParameter]` on a parameter, an uncaught exception's trace | redacts to `Object(SensitiveParameterValue)`, verified on 8.3.1 |

The first two probes are the same fact from two directions: **`openssl_decrypt()`'s
authentication is only as strong as the tag length it is told to check.** A token format that
let an attacker influence that length — by making the tag's length part of what the token
states, rather than a fixed constant the parser enforces — would hand back exactly the lever
GCM's authentication exists to remove: shrink the tag, then brute-force a forgery in an average
of `2^(8·n-1)` attempts for an *n*-byte tag, trivial once *n* drops far enough. The third and
fourth probes are their own fact: **nothing in `openssl_encrypt()` protects a caller from
passing a wrong-length key to `aes-256-gcm`.** A key is not a string that happens to be the
right length; it needs to be a type that cannot be constructed as the wrong one.

## Decision

### 1. The token format fixes both lengths structurally, never parses them

`"v1." . base64url(nonce ‖ ciphertext ‖ tag)`, where the nonce is always the first 12 bytes and
the tag always the last 16 — `Crypto::decrypt()` slices at those fixed offsets and never reads a
length from the token itself. There is therefore no field an attacker can shrink: the only lever
left is flipping bits within a fixed-size tag, which is exactly the attack GCM's authentication
is designed to catch, and every planted-tamper test below confirms it does.

### 2. `SecretKey` is the only way to produce key material, and it is the only place the length is checked

`Crypto`'s constructor accepts a `SecretKey`, never a raw string. `SecretKey::generate()`
(32 bytes from the CSPRNG) and `SecretKey::fromBytes()`/`fromBase64()` (validated, throwing
`CryptoException` on any other length) are the only three ways one exists. Since
`openssl_encrypt()` itself enforces nothing — probed above — the enforcement has to live
somewhere, and putting it in the one type that can produce a key means it is checked exactly
once, at the one place a wrong length could ever originate, rather than defensively re-checked
at every call site that happens to remember to.

### 2b. `base64UrlDecode()` has no separate alphabet check — found redundant by a planted-defect campaign

A first version checked the token's alphabet with `preg_match('/^[A-Za-z0-9\-_]*\$/', ...)`
before handing the string to `base64_decode(..., true)`. Planting its removal to verify the
tamper/malformed-input tests found **nothing** — every test still passed. Probed directly
against ten shapes (a stray `+`/`/`, whitespace, a control byte, non-ASCII, wrong padding, a
misplaced `=`): `base64_decode()`'s own strict mode already rejects every one, once the string
has been through the `-_`→`+/` translation and padding this method already does. The
`preg_match` never fired; it was dead defensive code implying a failure mode that does not
exist, the same shape ADR-0022 removed from `Hash::make()`'s `password_hash()` guard. Removed.

### 3. `decrypt()` throws on every failure, and cannot distinguish *why*

Wrong key, tampered nonce, tampered ciphertext, tampered tag, and a malformed or truncated token
all reach the same `openssl_decrypt() === false` and the same `CryptoException`. This is not a
missed opportunity for a more specific exception: GCM's authentication step is what would have
to run to tell a wrong key apart from a tampered tag, and by the time it has run, the honest
answer is already "no" — there is no cheaper check that would let the two be distinguished
without also being a working decryption oracle for one of them.

### 4. `ext-openssl` is refused at construction, following `Hash`'s precedent — and, like `Sanitizer`'s, cannot be exercised in CI

`Crypto::__construct()` checks `\extension_loaded('openssl')` and throws immediately if it is
missing, the same "fail while being wired, not at first use" reasoning ADR-0022 §4 gives for
Argon2id's absence. Unlike Argon2id's *policy branch* — which ADR-0022 extracted into a pure
`selectAlgorithm()` so an *availability* argument could substitute for an unreachable build
fact — there is no policy to extract here: the refusal is unconditional, and `ext-openssl` is a
core extension no CI runner or dev machine in this project's matrix lacks. The branch is
**probed rather than tested**, the same accepted shape ADR-0021 records for
`Sanitizer::richText()`'s missing-package check: `extension_loaded('openssl')` was verified
`true` on the PHP build this class was designed against, and the guard's presence is not
otherwise asserted by the suite.

### 5. `#[\SensitiveParameter]` on every key-bearing constructor and factory parameter

`SecretKey`'s constructor and its `fromBytes()`/`fromBase64()` factories all carry it. Verified
on 8.3.1 that an uncaught exception's trace redacts the argument. On the 8.1 floor the attribute
class does not exist; PHP resolves attributes lazily — only when something reflects on them —
so an unresolved `\SensitiveParameter` is inert there rather than a compile error, which is what
lets the same source run unmodified across the 8.1–8.3 matrix while doing something real on
8.2+.

## Alternatives Considered

1. **Let the token state its own tag length** (a length-prefixed or delimited format) —
   rejected on probe #1/#2 directly: it is the one design shape that reopens the truncated-tag
   weakness a fixed format closes by construction.
2. **Accept a raw string as a key, with a length check inline in `Crypto`** — rejected: every
   call site would need to remember the check, and `openssl_encrypt()` proved (probe #3/#4) it
   will not catch a mistake for you. A dedicated type that cannot exist at the wrong length is
   cheaper than a discipline nobody can forget.
3. **Distinguish "wrong key" from "tampered" in `decrypt()`'s exception** — rejected in §3: no
   cheaper check exists, and building one would mean partially decrypting before authenticating,
   which is the ordering GCM is designed to prevent.
4. **libsodium's AEAD (`XChaCha20-Poly1305`) instead of AES-256-GCM over OpenSSL** — already
   rejected at the RFC level (RFC-0002 Alternatives #6b): `ext-openssl` is demonstrably present
   across this project's target estate (the cipher helper this replaces already used it), and
   the RFC's `v2.` escape hatch remains open if that estate's builds later standardize on
   sodium. Not revisited here.
5. **Extract the `ext-openssl` guard into a pure function taking availability as an argument**,
   mirroring ADR-0022's `selectAlgorithm()` — rejected: that extraction earns its keep only when
   there is a *policy* behind the unreachable branch (Argon2id's fallback-or-refuse choice).
   Here the branch is an unconditional refusal with nothing to parameterize, so extracting it
   would add a seam with no decision on the other side of it.

## Consequences

**Easier:** a caller cannot construct a `Crypto` with a wrong-length key even by accident — the
type system refuses before any cipher call happens; every tamper vector T-09 names is caught by
the same mechanism, so there is one code path to trust rather than several.

**Harder / accepted:** the `ext-openssl` refusal branch is unexecuted by the suite, a documented
gap rather than a hidden one, matching `Sanitizer::richText()`'s own precedent; `decrypt()`
cannot tell a caller *why* it failed beyond "not this key, on this token," which is GCM's
guarantee working as intended rather than a missing feature.

**Verification:** T-09 (`CryptoTest`) covers round-trips (including the empty-plaintext boundary
and binary/NUL-bearing payloads), tamper on both the ciphertext and the tag regions
independently, decryption under a different key, truncation both mid-token and below the
minimum nonce+tag floor, malformed and non-base64url input, a missing or unrecognised version
prefix, nonce uniqueness across 10⁵ tokens, and `SecretKey`'s length refusal at six wrong
lengths plus its base64 storage round trip.

**Six planted defects, six caught** — decrypt() swallowing `openssl_decrypt()`'s failure, a
fixed (non-random) nonce, the version-prefix check removed, the minimum-length check removed,
`SecretKey`'s length check removed, and the (since-removed) alphabet pre-check — plus one that
was **not caught**, which is what found §2b: removing the alphabet pre-check changed nothing,
because `base64_decode()`'s own strict mode already covered it. Two of the six passing plants
needed a test fix first: the version-prefix tests originally used a fresh `SecretKey` for
encrypt and decrypt, which meant a dropped or altered prefix and a wrong key threw for the same
underlying reason and the plant went undetected — corrected to reuse one instance, which is what
makes `"v2."` (exactly as long as `"v1."`) a meaningful case rather than an accidental pass.

## References

- Spec FR-40, NFR-13 (the 1 KiB round-trip budget item 12.5 measures), T-09
- ADR-0021 (missing-optional-dependency refusal; the untested-in-CI precedent this item's
  `ext-openssl` guard follows)
- ADR-0022 (construction-time refusal; where the pure-function extraction pattern earns its
  keep, and where it does not)
- Verified directly on PHP 8.3.1: the tag-length truncation behaviour, the key-length
  non-enforcement, and `#[\SensitiveParameter]`'s trace redaction
