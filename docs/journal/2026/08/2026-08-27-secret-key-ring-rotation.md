# 2026-08-27 — Prefixing the key id would have been the bug

Issue **#114**, both criteria. Route `frontier-reasoning / extra`; session model Opus 5.
**ADR-0083** annotated, spec **r28** (FR-40b).

The board's one *major* security finding, and the one issue in this sweep where the naive
implementation is not merely incomplete but actively unsafe.

## The headroom was already reserved, which made the design question narrow

Three documents had already anticipated this: RFC-0002 named a `v2.` escape hatch, ADR-0054
deliberately kept `"v2."` exactly as long as `"v1."` so the prefix check would treat it as a real
case rather than an accidental pass, and `Hmac`'s docblock states outright that key identifiers
"arrive the same way ADR-0054 planned — as a `v2.` grammar, while `v1.` tokens keep verifying."

So the format question was settled before I started. What was not settled — and what nothing in
those three documents mentions — is **where in the token the key id goes relative to the
authentication tag**.

## The obvious implementation is the unsafe one

The issue's own proposed shape is "a key-id byte (or short id) in the token format". Read
literally, that is: prefix the id, slice it off on the way in, look up the key. It works. Every
test I would naturally have written for it passes. And it is wrong, because **the id would be
unauthenticated**.

An attacker who can see a token can edit its id. With a bare prefix, the only thing standing
between a substituted id and a decrypt attempt under a *different key* is luck about which key the
substituted id happens to name. That is not a defence; it is an absence of one.

The fix is to put the id in GCM's **additional authenticated data** — authenticated but not
encrypted, so it stays readable (it has to be, you need it to pick the key) while becoming
unforgeable. I probed this before committing to the design rather than after: the same ciphertext
and tag decrypt under the same AAD, and return `false` under a different AAD *or an empty one*.

Two attacks follow, and I asserted both:

- **Substituting the id to name another key the ring genuinely holds.** Chosen deliberately over
  "point it at a random id" — with a real id the lookup *succeeds*, so the refusal can only have
  come from the tag. A test using a nonexistent id would have passed against a completely
  unauthenticated implementation.
- **Stripping the id and replaying the body as a `v1.` token.** `v1.` decrypts with an empty AAD, so
  the tag — computed over the id — cannot verify. This one falls out of the design rather than being
  separately engineered, and it is asserted precisely because nothing else would notice if it
  stopped holding.

Both with a live control in the same suite: the untampered token from the same construction *does*
decrypt. A tamper test whose control never worked proves nothing, and this repository has already
been bitten by exactly that (ADR-0054's own version-prefix tests originally used a fresh key for
encrypt and decrypt, so a dropped prefix and a wrong key threw for the same reason and a planted
defect went undetected).

## Derived ids, and refusing a collision rather than tolerating it

`hash_hkdf('sha256', bytes, 4, 'egl/utils:keyid:v1')` — ADR-0065's domain-separation pattern with
its own label. The alternative, a caller-assigned label, works fine and was rejected for making
rotation correctness depend on the caller keeping a numbering scheme straight across deployments.
That is the bookkeeping this library exists to absorb.

Four bytes is far more than a handful of keys needs, and "unlikely" is not "checked": two colliding
ids would make the lookup return the wrong key, which in an AEAD surfaces as *a tampered token* —
a genuinely misleading failure to debug. So construction refuses a collision outright, and the
same check catches the far likelier operator error underneath it, the same key listed twice.

## Two things I got wrong first

**The first draft called the HKDF inside `encrypt()`.** A hash per message, for a value that cannot
change on an immutable ring — and `current()` was allocating an array per call on top of it. NFR-13
budgets a 1 KiB round trip at 60 µs and that is not a budget to spend re-deriving a constant. Both
were fixed before I measured, which is why the measurement table's most useful row is the one that
shows nothing: bare-key 14.79 µs, ring-of-one 14.18, ring-of-three 14.25 — one number inside noise.
Had I measured first and optimised after, the row would have shown the defect and I would have
reported it as a finding rather than as something caught in review of my own draft.

**And I nearly left `v1.` emitting `v2.`.** Making a bare `SecretKey` produce the new format is
tidier internally — one path, no flag. It is also a silent format change for every current consumer,
whose external verifiers were written against ADR-0054's published grammar and have not been told
there is a second one. Under ADR-0059's freeze that is the difference between an additive MINOR and
a break. So the flag stays, and `v1.` byte-identity has its own test — a claim about *not* changing
being exactly the kind that needs one.

## ADR-0082 caught me, one PR after it shipped

`SecretKeyRing::keyIdOf()` is `@internal`, which made it the sixth entry in an inventory the
previous PR had just pinned at five. The lint refused, naming the symbol and asking whether this was
a legitimate new exclusion or an already-frozen symbol being quietly moved outside the contract. It
was the former, so it cost a reviewed one-line edit to `EXPECTED_INTERNAL` — which is precisely the
"deliberate, visible act" ADR-0082 was written to force. Satisfying to be on the receiving end of it
this quickly.

## Where this leaves the project

One new class, one widened constructor parameter type, no signature removed, no existing behaviour
changed. 16 new tests; T-09 at 45 green. `Hmac` still has no rotation story — deliberately, on the
maintainer's call, with this ADR defining the convention it adopts and a follow-up filed rather than
two token formats changed in one security review.
