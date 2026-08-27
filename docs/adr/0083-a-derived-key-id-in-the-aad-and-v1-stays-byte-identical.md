# ADR-0083: A derived key id in the AAD, and `v1.` stays byte-identical

- **Status:** Accepted
- **Date:** 2026-08-27
- **Deciders:** project architect (agent), maintainer
- **Related:** issue [#114](https://github.com/danielPoloWork/egl-util-php/issues/114) ·
  [ADR-0054](0054-authenticated-encryption-with-fixed-lengths-and-a-key-only-secretkey-can-produce.md)
  (the `v1.` format this extends, and the fixed-offset discipline it reuses) ·
  [ADR-0065](0065-a-detached-signature-over-a-derived-key-with-the-algorithm-never-in-the-token.md)
  (`Hmac`'s HKDF domain-separation, the pattern the key-id derivation copies) ·
  [ADR-0059](0059-freeze-the-api-at-1-0-0-with-internal-symbols-outside-the-frozen-surface.md)
  (why nothing here may change an existing signature or format) ·
  [ADR-0082](0082-pin-the-internal-inventory-so-widening-the-carve-out-is-visible.md)
  (the inventory this widens by one, visibly) · spec **FR-40**, **FR-40b**, revision **r28**

## Context

ADR-0054's token is `"v1." . base64url(nonce ‖ ciphertext ‖ tag)`. The `v1.` prefix versions the
**format**, not the key — a fact both `Crypto`'s and `Hmac`'s docblocks already state explicitly.
So a deployment that rotates its key after a suspected compromise has two options, and issue #114
(Senior Security Engineer + API Review Board, 2026-08-09 — recorded as the board's *one major
security finding*) rejects both: invalidate every outstanding token at once, or hand-roll a
try-each-key loop around a library whose stated posture is that security mechanisms are explicit
and not the caller's to assemble.

The headroom was reserved in advance. RFC-0002 named a `v2.` escape hatch; ADR-0054 kept `"v2."`
exactly as long as `"v1."` so the prefix check would treat it as a real case; and `Hmac`'s docblock
already promises that "key identifiers, when the `SecretKeyRing` issue lands, arrive the same way
ADR-0054 planned — as a `v2.` grammar, while `v1.` tokens keep verifying." This ADR spends that
headroom for `Crypto`.

## Decision

**A `SecretKeyRing` holds a current key plus the previous ones still accepted. Its key ids are
HKDF-derived, four bytes, and travel in the token as GCM's AAD. `Crypto` emits `v2.` only when
constructed with a ring; a bare `SecretKey` keeps producing byte-identical `v1.` tokens.**

`v2.` is `base64url(keyId ‖ nonce ‖ ciphertext ‖ tag)` — the id first, at a fixed offset, every
field a fixed width. ADR-0054's slicing discipline extended rather than reinterpreted.

### 1. The key id is in the AAD, not merely prefixed — and that is the load-bearing choice

The id travels in the clear, so an attacker can edit it. Putting it in GCM's *additional
authenticated data* means the tag covers it: AAD is authenticated but not encrypted, so the id
stays readable (it has to be — you need it to pick the key) while becoming unforgeable.

Probed before the design was committed, on PHP 8.3.1: the same ciphertext and tag decrypt under the
same AAD, and return `false` under a different AAD **or an empty one**. Two attacks follow, and
both are refused in the test suite with a live control proving the refusal is not vacuous:

- **Key-id substitution.** Rewriting the id to name a *different key the ring actually holds* — so
  the lookup succeeds and only the tag can object — fails. Without the AAD binding, the id would be
  unauthenticated metadata and the only thing between a substituted id and a decrypt attempt under
  the wrong key would be luck about which key it named.
- **Downgrade to `v1.`** Stripping the four id bytes and presenting the remainder as a `v1.` token
  fails, because `v1.` decrypts with an empty AAD and the tag was computed over the id. This one
  falls out of the design rather than being separately engineered, and it is asserted because
  nothing else would notice if it stopped holding.

### 2. Derived, not assigned

`keyIdOf()` is `hash_hkdf('sha256', bytes, 4, 'egl/utils:keyid:v1')` — the same
domain-separated-derivation pattern ADR-0065 established for `Hmac`'s MAC key, with its own label.
Two properties, both load-bearing:

- **It cannot be inverted to key material**, because HKDF is a PRF. Issue #114's third acceptance
  criterion ("key-id never leaks key material") is met by construction rather than by care, and the
  suite pins the specific failure a naive implementation would have had: the id is not a slice of
  the key in either encoding.
- **It is stable with no registry.** The same key yields the same id in any process, so a ring
  rebuilt from the same environment variables — in a different order, in a different service —
  recognises its own tokens. A caller-assigned label was the alternative and was rejected for
  making correct rotation depend on the caller keeping a numbering scheme straight across
  deployments, which is exactly the bookkeeping this library takes on rather than delegates.

**Four bytes, with collisions refused rather than resolved.** 32 bits is far more than a handful of
keys needs, but "unlikely" is not "checked": two colliding ids would make the lookup return the
wrong key, and a wrong key in an AEAD surfaces as a tampered token — a genuinely confusing failure.
`SecretKeyRing::of()` refuses a collision outright, which in practice catches the likelier operator
error underneath it: the same key listed twice.

### 3. An unknown key id fails closed

Never retried against the ring's other keys. Falling back would make the id decorative and, worse,
would mean removing a key from the ring changed nothing — a retired key would go on working for as
long as its tokens were presented, which is the opposite of what rotation after a compromise is
for. The refusal names the id in hex, because an operator debugging a rotation needs to know *which*
key is missing.

### 4. `v1.` stays byte-identical, and that is what makes this additive

A bare `SecretKey` produces exactly the token it produced before this change. A consumer who passed
one has not asked for rotation, and their verifiers — possibly in another language, written against
ADR-0054's published grammar — have not been told there is a second format. Opting in is passing a
ring. Under ADR-0059's freeze that distinction is the difference between an additive MINOR and a
format break.

Internally there is one decryption path, not two: a bare key becomes a ring of one, so `v1.` and
`v2.` handling cannot drift apart. And adopting a ring is a **migration rather than a cutover** — a
ring reads `v1.` tokens by trying each of its keys, so tokens already in flight when the ring
arrives keep working.

## Alternatives Considered

- **Prefix the key id without putting it in the AAD.** The obvious implementation, and the one this
  ADR exists to argue against (§1). Rejected: it leaves the id unauthenticated, which is the whole
  attack surface a key identifier introduces.
- **A caller-assigned key id** (an integer or short label). Rejected in §2 — it works, and it makes
  rotation correctness depend on caller bookkeeping across deployments.
- **A truncated hash of the key, without HKDF** (`substr(hash('sha256', $bytes), 0, 8)`). Rejected:
  it is a key-dependent value with no domain separation, so the same digest could collide with
  another use of the same key material elsewhere. HKDF with an explicit label costs the same and
  removes the question, which is precisely ADR-0065's reasoning for the MAC key.
- **Change `v1.`'s meaning to include a key id.** Rejected: it is a format break under ADR-0059 and
  would silently invalidate every outstanding token — the exact harm issue #114 was filed about.
- **Make `encrypt()` emit `v2.` for a bare key too**, treating the single key as a ring of one on
  the wire. Rejected in §4: it changes the token a current consumer receives without them asking,
  and their external verifiers would break.
- **Try every key on an unknown `v2.` id**, for robustness. Rejected in §3 — it would make key
  retirement inoperative.
- **Move `Hmac` to `v2.` in the same change.** Deliberately out of scope, on the maintainer's
  explicit call. Issue #114's second criterion asks that the format be *coordinated* with the HMAC
  utility so both can share the convention, and that is what ships: the derivation, the label
  grammar (`egl/utils:keyid:v1`) and the `keyId ‖ …` layout are defined here as the shared
  convention, and `Hmac` adopts them unchanged when its own item lands — with the id covered by the
  MAC, which is the signing-side equivalent of §1's AAD binding. Filed as
  [#179](https://github.com/danielPoloWork/egl-util-php/issues/179) rather than folded in, to keep
  one security-critical format change per review.
- **A key id wider than four bytes.** Considered; rejected as cost without benefit. Eight bytes
  would add ~5 base64 characters to every token to reduce a collision probability that is already
  refused explicitly at construction rather than tolerated statistically.

## What was measured

Not the reference machine NFR-06 names, so indicative rather than authoritative — but the shape is
what matters, and the shape is "no cost on the budgeted path". 1 KiB round trip, 20 000 revs,
OPcache off:

| subject | µs |
|---|---:|
| bare key, `v1.` — NFR-13's subject, budget ≤ 60 µs | **14.79** |
| ring of 1, `v2.` | 14.18 |
| ring of 3, `v2.` | 14.25 |
| `v1.` token read by a 3-key ring, worst-case trial order (decrypt only) | 18.90 |

The first three are one number inside noise, which is the point: the key id is derived **once at
construction**, not per call. That was not true of the first draft — `keyIdOf()` was being called
inside `encrypt()`, paying an HKDF per message for a value that cannot change, and `current()` was
allocating an array per call. Both were fixed before measuring; the row that would have shown it is
the one that now shows nothing.

The last row is the only real cost and it is migration-only: a `v1.` token in a multi-key ring
cannot be addressed by id, so it is tried against each key in turn, bounded by ring size. Current
key first, so the common case is the first attempt, and every failure is the same uniform refusal —
the attempt count leaks nothing a caller could use.

## Consequences

- **Additive.** One new class, one widened constructor parameter type (`SecretKey|SecretKeyRing`),
  no signature removed, no existing behaviour changed. `^1.0` still resolves and the BC checker sees
  no break.
- **`v1.` is byte-identical**, asserted by its own test — a claim about *not* changing is exactly
  the kind that needs one.
- **The `@internal` inventory grows to six**, and ADR-0082's check from the previous PR caught the
  addition on its first real use — `SecretKeyRing::keyIdOf()` had to be added to
  `EXPECTED_INTERNAL` in a reviewed diff before the lint would pass. Working as designed, one PR
  after it shipped.
- **`SecretKey::bytes()`'s `@internal` note gains a third legitimate caller**, on the same terms as
  `Hmac`: the ring reads the bytes only to derive an id under its own HKDF label, and that id cannot
  be inverted back to them.
- **Known property, not a weakness:** a key id is a stable public label, so an observer can group
  tokens by key and see when a rotation happened. That is inherent to key identification and is
  what makes the window work; recorded so it is known rather than discovered.
- **Known limitation:** `Hmac` still has no rotation story. Filed as
  [#179](https://github.com/danielPoloWork/egl-util-php/issues/179),
  and its docblock already anticipated this grammar, so adopting it is additive there too.
- **Rotation is now a documented operator procedure** rather than an open question: add the new key
  as `current` with the old one behind it, wait out the longest-lived token, then drop the old key —
  and the window closes at that point, not when the new key arrived.

## References

- Issue [#114](https://github.com/danielPoloWork/egl-util-php/issues/114) — the 2026-08-09 release
  review board's one major security finding.
- Spec **r28** — FR-40b, the additive requirement.
- RFC 5869 (HKDF), RFC 5116 §2.1 (AEAD additional authenticated data).
- Probed directly on PHP 8.3.1: GCM AAD binding (same/different/empty), and `hash_hkdf`'s length
  and determinism.
