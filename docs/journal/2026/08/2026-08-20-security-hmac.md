# 2026-08-20 — The signature, and the guard that had stopped watching

Roadmap item **14.4**, issue **#92**. Route `frontier-reasoning / extra` (the security group's
protected floor, twice over); session model Opus 5 — **matched**.

`Security\Hmac` is the item. The thing worth reading is what happened when I tried to hold it to the
library's own safety net.

## The four decisions RFC-0003 left open

**Detached, not a container.** `sign()` returns `v1.base64url(expiry ‖ mac)` and the message stays
where it lives. `verify(string $message, string $token)` is handed the message back anyway, so
carrying a copy inside would duplicate it on the wire — and both real use cases already keep the
message elsewhere: a webhook body the library never sees, a URL that would otherwise contain itself.

**The MAC covers the expiry.** The expiry travels in the token, so it must be signed; otherwise
extending a signed URL's lifetime is an eight-byte edit and the signature still checks out, because
it never spoke for those bytes. The width is fixed at eight *so that the concatenation needs no
delimiter*: with a variable-width prefix, `1 ‖ "23"` and `12 ‖ "3"` are the same bytes to sign, which
is the canonicalization collision that has broken real schemes.

**The algorithm is never read from the token.** This is the one design point here that is not a
trade-off but a vulnerability class: a token naming its own algorithm hands the attacker the choice
of how their forgery gets checked — JWT's `alg` confusion. The `v1.` prefix versions the *format*.
The consequence is accepted out loud and pinned by a test: a deployment that changes algorithm
invalidates its outstanding tokens rather than trusting them.

**The length comes from the allowlist.** `ALGORITHMS` maps each accepted algorithm to its exact raw
digest length, and `verify()` requires the payload to be exactly `8 + macBytes`. This is ADR-0054's
finding one class over — that ADR probed `openssl_decrypt()` accepting a *correct prefix* of a GCM
tag at any length down to one byte. `hash_equals()` compares lengths first and so does not have that
flaw, but resting the property on which comparator happens to be in use is weaker than pinning it.

## Two decisions the item did not name

**The MAC key is derived.** The common deployment is one `APP_SECRET` behind everything, which means
the same 32 bytes reach AES-256-GCM in `Crypto` and HMAC here. Nobody has demonstrated a break from
that pairing — but *"no published attack"* is a weaker claim than *"the two primitives never see the
same key"*, and the stronger one costs one `hash_hkdf()` at construction. The alternative was
documenting **"do not reuse a `SecretKey` between `Crypto` and `Hmac`"**, and I rejected it for the
reason this library rejects that shape everywhere: a correctness requirement on the caller, with no
error when it is missed.

The cost is real and recorded rather than glossed: the HKDF label is now part of the `v1.` grammar,
so a verifier in another language needs it. That verifier already needed the payload layout, since
`expiry ‖ mac` is not a bare HMAC either — the derivation adds a line to the format spec, not a new
category of problem.

**`Base64Url` extracted, not copied** (its own commit). `Crypto` held the private pair; this class
needs the identical one. Item 10.4 is the argument: it shipped `MutationBuilderTest` with a
ten-payload identifier corpus while `QueryBuilderTest` held nineteen, **both suites green**, the
newer copy holding to the weaker rule. The decoder's reasoning here is load-bearing — strict-mode
`base64_decode()` already rejects every malformed shape, so an earlier alphabet guard was dead code
and was removed — and a drifted copy would still round-trip every token its own tests fed it.

## ★ The guard had stopped watching, and it was green about it

`ConstantTimeComparisonTest` owns the library's registry of secret comparisons. Its completeness
test exists so exactly what I was doing — adding a new one — cannot go unasserted. I added
`Hmac::verify()` with a `hash_equals()` call, did **not** register it, and ran the file expecting
red.

It reported `OK (5 tests, 15 assertions)`.

The scanner tokenizes and matches `T_STRING` whose value is `hash_equals` or `password_verify`. But
`\hash_equals` is not that: PHP tokenizes a qualified call as `T_NAME_FULLY_QUALIFIED`, value
`\hash_equals`. ADR-0048 prefixed every internal call in the tree at item **10.12** — so from that
item onward the scanner saw **0 of the library's 3** comparisons and `assertSame([], $unregistered)`
compared an empty list against an empty list.

Filed as **BUG-0001**, the ledger's first record, and fixed here. I proved the impact instead of
asserting it: scanner reverted to its pre-repair state, `Hmac::verify()` unregistered, and
`hash_equals($expected, $mac)` replaced by `$expected !== $mac` in `src/main` — a timing-unsafe
comparison on a secret path, in the library, with the file whose whole purpose is to refuse it
reporting five green tests.

**Item 10.12's own audit had looked and cleared it.** That item knew it had broken one
source-inspecting test (`NativeSessionApiTest`, which searched for the literal
`return session_start();`) and checked the other three, concluding they "match patterns, not
spellings, and were fine". This one matched neither: it matched a **token type**, a third category
the audit did not have, and the prefixing changed exactly that.

**The asymmetry is the transferable part.** ADR-0048 turned one mechanism assertion *red* and
another *green*. The red one was fixed within the hour. The green one survived ten items, two of
them security items, because a test that passes because it found nothing to check is
indistinguishable from the outside from a test that passes because everything checked out. The
repaired guard therefore now also asserts it can **see** at least as many comparisons as are
registered — the anti-vacuity check the original lacked.

One honesty note: in the reverted-state run, the wider `HmacTest` did fail one test — but
incidentally. `testTheMacIsCheckedBeforeTheExpiryIsRead` locates `hash_equals(` in order to check
what precedes it, so it breaks when the call vanishes for an unrelated reason. That is a coincidence
of this item's own new assertions, not the net working. Had 14.4 needed no ordering assertion, the
plant would have passed everything.

## The campaign, and what its distribution proves

**15 planted defects, 15 caught.** The distribution is the evidence, not the count:

| Plant | Caught by |
|---|---|
| MAC over the message alone, **symmetrically** in `sign()` and `verify()` (every round trip still succeeds) | exactly 1 — `testEditingTheExpiryBreaksTheSignature` |
| Expiry decoded before the MAC is checked | exactly 1 — the ordering **mechanism** assertion |
| Payload length taken from the token | exactly 1 — the allowlist-length **mechanism** assertion |
| HKDF domain label changed | exactly 1 — the **conformance vector** |
| `===` in place of `hash_equals()` | the registry + the ordering assertion — **no behavioural test at all** |

That last row is ADR-0027's premise demonstrated rather than argued. The four single-catch rows are
why the mechanism assertions and the vector exist: a round-trip test passes for any self-consistent
format, so nothing else in the suite can see a changed grammar until tokens stop verifying in
production after a deploy.

## Process: my plant harness lied to me three times

1. **`git checkout --` restores from the INDEX**, and mine ran against a stale one — silently
   deleting the unstaged HKDF work. Three plants then reported "did not land" because the feature
   under test had been erased. The recorded lesson (item 11.4) says *stage first*; what it did not
   say is **re-stage after every edit the harness will outlive**. That is the sharper form.
2. **A plant "passed" that never landed in code**: my pattern for the HKDF label matched the
   docblock's copy of the constant before its declaration, and `replace(…, 1)` edited the comment.
   Verify a plant against the **declaration**, and by the property it breaks — for that one, that
   `decodeExpiry()` now precedes `hash_equals()` — never by a string that occurs twice.
3. **An asymmetric plant is a weak plant.** MACing the message alone in `verify()` only broke every
   round trip and looked well-caught. The claim worth testing was the symmetric version, where
   nothing round-trip-visible changes — and that is the one that isolated a single test.

## Left as it is

No NFR budget: RFC-0003's own reasoning for the clocks applies (a number here would bound PHP's
method dispatch, as NFR-14's control subject showed), and ADR-0040 reserves spec numbers for the
maintainer regardless. No new deptrac edge — `Security` has had `Psr` since `Hash`'s PSR-3 logging
and `Support` since always, so the clock seam cost nothing architecturally; said out loud because an
absence leaves no trace (item 12.3's rule).

**M14 still has 14.5 and 14.7 open**, so `consistency_lint`'s milestone check keeps README's M14 row
at planned — structurally, not by choice.
