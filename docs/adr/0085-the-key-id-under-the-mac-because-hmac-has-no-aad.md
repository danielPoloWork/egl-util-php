# ADR-0085: The key id under the MAC, because HMAC has no AAD

- **Status:** Accepted
- **Date:** 2026-08-27
- **Deciders:** project architect (agent), maintainer
- **Related:** issue [#179](https://github.com/danielPoloWork/egl-util-php/issues/179) ·
  [ADR-0083](0083-a-derived-key-id-in-the-aad-and-v1-stays-byte-identical.md)
  (the rotation convention this reuses, and the sibling decision for `Crypto`) ·
  [ADR-0065](0065-a-detached-signature-over-a-derived-key-with-the-algorithm-never-in-the-token.md)
  (`Hmac`'s `v1.` grammar and its HKDF domain separation) ·
  [ADR-0054](0054-authenticated-encryption-with-fixed-lengths-and-a-key-only-secretkey-can-produce.md)
  (the fixed-offset slicing discipline, and the finding that a variable-length authenticator is a
  lever) ·
  [ADR-0059](0059-freeze-the-api-at-1-0-0-with-internal-symbols-outside-the-frozen-surface.md)
  (why nothing here may change an existing format) ·
  [ADR-0027](0027-constant-time-comparison-is-asserted-by-mechanism-not-by-timing.md)
  (why three of the assertions here are mechanisms) ·
  [ADR-0040](0040-install-the-mutation-tester-outside-the-graph-and-keep-nfr07s-own-number.md)
  (why no NFR budget is invented here) · spec **FR-48**, **FR-48b**, revision **r29**

## Context

ADR-0083 gave `Crypto` a rotation window. `Hmac` had the identical gap and none of the fix: its
`v1.` token is `base64url(expiry ‖ mac)` with no key identifier, so promoting a new signing key
invalidates every outstanding signed URL and webhook signature at the moment of the deploy — and
those are exactly the two artifacts that outlive a deploy by design. A webhook signature is checked
by someone else's server, on their schedule.

Issue #179 was deferred from #114 on the maintainer's call, to keep one security-critical format
change per review. It arrives with its convention already settled: ADR-0083 fixed the key-id
derivation (`hash_hkdf('sha256', bytes, 4, 'egl/utils:keyid:v1')`), the id-first fixed-width
layout, the fail-closed reading of an unknown id, and the rule that `v1.` stays byte-identical.
None of that is reopened here. **One thing is genuinely different, and it is the thing this ADR is
named after.**

`Crypto` binds its key id with GCM's **AAD** — authenticated but not encrypted, so the tag covers
the id without hiding it. That is what makes rewriting the id to name a different key unforgeable.
**HMAC has no AAD.** There is no second input to authenticate alongside the message; there is only
the message. So the question this ADR answers is where the key id goes such that it is
authenticated at all.

## Decision

**The key id goes *inside* the signed bytes: `mac = hmac(keyId ‖ expiry ‖ message)`, and `v2.` is
`base64url(keyId ‖ expiry ‖ mac)`. `Hmac` emits `v2.` only when constructed with a
`SecretKeyRing`; a bare `SecretKey` keeps producing byte-identical `v1.` tokens.**

Everything else is ADR-0083's, applied unchanged:

- **The id is four bytes, first, at a fixed offset.** ADR-0054's slicing discipline, extended
  rather than reinterpreted. The payload length is checked as an exact total from the algorithm
  allowlist, never derived from the token.
- **An unknown key id fails closed** — refused, never retried against the ring's other keys.
  Retrying would make the id decorative and a retired key effectively still live.
- **A ring also verifies `v1.` tokens**, by walking its keys, current first. That is what makes
  adopting a ring a migration rather than a cutover.
- **The derived MAC keys are computed once, at construction — one per key in the ring.**

### Why "under the MAC" is not merely the remaining option

It would have been possible to put the id in the token and *not* in the signed bytes — a prefix,
purely as a lookup hint. That version passes every round-trip test and every "wrong key is
refused" test, because a token whose id is a hint still selects the right key and still verifies.
What it fails is the case where the id is rewritten to name **another key the ring genuinely
holds**: the lookup succeeds, the MAC was computed over bytes that never included the id, and it
matches. The token verifies under a key its author did not choose.

Whether that is exploitable depends on what the deployment does with the fact of successful
verification, which is exactly the kind of "probably fine" this library refuses to ship. It is also
the failure ADR-0083 §1 exists to prevent, and answering it differently for `Hmac` than for
`Crypto` would leave the group with two security postures behind one token grammar.

**The consequence worth naming: this is what makes a `v2.` body replayed as `v1.` fail.** Strip the
four-byte id from a `v2.` payload and what remains is a perfectly well-formed `v1.` payload —
eight expiry bytes and a full-length MAC, so the length check passes. The refusal comes from the
MAC alone, because `v1.` verification computes over `expiry ‖ message` and the bytes were signed
over `keyId ‖ expiry ‖ message`. A format that appended the id would accept the downgrade.

### The empty id is what keeps `v1.` byte-identical

`sign()` computes `hmac($this->currentKeyId . $expiryBytes . $message)` on **both** paths, and
`currentKeyId` is the empty string on the `v1.` path. So `v1.` is not a separate code path kept in
step with `v2.` by discipline; it is the same expression with an empty first field, which is
byte-identical to ADR-0065's grammar by construction rather than by care. The `v1.` conformance
vector in `HmacTest` is the anchor, and a planted defect confirmed it fires: making `currentKeyId`
unconditional changed the `v1.` vector and nothing else caught it.

## Consequences

**A ring costs nothing per message, and that is the point of the id.** Measured on this development
machine, two runs agreeing (informative absolutes — this box overstates CPU work; there is no NFR
budget for `Hmac`, so these are stated rather than gated):

| Path | Cost |
|---|---|
| `sign()` `v1.` bare key / `v2.` ring of 3 | 5.04–5.06 µs / 5.23–5.30 µs |
| `verify()` `v1.` bare key | 6.06–6.08 µs |
| `verify()` `v2.` ring of 3, addressed by id | 6.24–6.32 µs |
| `verify()` `v1.` ring of 3, oldest key — **3 candidates** | **12.70–13.04 µs** |
| construction, 1 HKDF | 24.2–25.5 µs |
| construction, 3 HKDFs (ring of 3) | 46.8 µs |

The `v1.`/`v2.` per-message differences are **not claimed**: they sit at 3–4%, and between the two
runs the ordering of `v1. ring, newest` and `v2. ring` flipped. This repository's standing rule —
no benchmark claim under ~3% from a single run — applies, and the honest statement is that per
message the two formats are indistinguishable.

The number that *is* well outside noise is the last verification row. **`v2.` is not only about
rotation; it is what keeps verification O(1) during one.** A `v1.` token names no key, so a ring
must try each until one matches, and a token from the oldest key of three costs roughly twice a
single check — linear in candidates. That is the migration window's real price, and an argument for
adopting `v2.` rather than running a ring on `v1.` indefinitely.

Construction pays ~11 µs per additional key, once. Deriving inside `verify()` instead would have
moved that onto every message, multiplied by candidates tried — ADR-0083's first draft made that
mistake with the key id, which is why the point is asserted as a mechanism here rather than trusted
to review.

**Three assertions are mechanisms** (ADR-0027), because no behaviour can observe them: that the MAC
keys are derived at construction and nowhere else; that the algorithm reaching `hash_hmac()` is the
instance's validated one (pre-existing, still holds with the key now a parameter); and that the
expected payload length comes from the allowlist rather than the token.

**A pre-existing gap in the `v1.` suite was found and closed on the way.** A planted defect changed
the payload length check from `!==` to `<` and **survived** — an overlong payload sailed past the
check and was refused further down anyway, because `hash_equals()` compares lengths before bytes.
Same outcome, different reason. `testACorrectMacPrefixIsRefused`'s own docblock already claimed the
structural property ("rather than a consequence of which comparator happens to be in use") that
nothing was enforcing. The overlong tests now assert the *message*, so the length check is what has
to refuse; only the overlong direction can distinguish the two, since a short payload fails either
comparison.

**The fail-closed refusal is policy and diagnosis, not the security boundary.** A second planted
defect made an unknown key id fall back to trying every key, and it was caught by two tests — but
on the *message*, not on acceptance: the token was still refused, because the id is signed and no
other key produces a matching MAC. Worth stating plainly, because it says which line is load-bearing.
Fail-closed is what makes retiring a key mean something and what gives an operator a legible error;
the MAC is what makes the id unforgeable. Both stay.

**What this deliberately does not do.** It does not add an `Hmac` benchmark subject or an NFR
budget — the spec owns its own numbers (ADR-0040) and inventing one here would be deciding a
spec-scope question inside an implementation item. The numbers above are recorded so a future
budget has a starting point.

**Known and accepted, inherited from ADR-0083.** A key id is a stable public label, so an observer
can group tokens by key and see when a rotation happened; and the unknown-id refusal is
distinguishable from a MAC failure, so an observer can learn whether a given id is held. Both are
inherent to key identification rather than weaknesses of this construction, and both were accepted
for `Crypto` on the same reasoning.

## Alternatives considered

**The id as a lookup hint only, outside the MAC.** Rejected above — it is the whole subject of this
ADR.

**A separate `v2.`-only signing path.** Rejected: two paths that must produce identical bytes for
the `v1.` case are two paths that drift. The empty-id formulation collapses them into one
expression.

**Re-deriving MAC keys per call to keep the constructor cheap.** Rejected on the measurement above:
it moves ~11 µs per key from once-ever to once-per-message-per-candidate, for values that cannot
change after construction.

**Reusing a different HKDF domain for `v2.` MAC keys.** Rejected. `egl/utils:hmac:v1` names the
*MAC key derivation*, not the token format, and changing it for `v2.` would mean a bare key and a
ring of one derived different MAC keys — which would break the `v1.` tokens a ring is supposed to
keep verifying, for no gain. The formats are already separated by what the MAC covers.
