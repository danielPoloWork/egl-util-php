# 2026-08-27 — No AAD to hide the key id in, so it went under the MAC

Issue **#179**, the deferred half of #114. Route `frontier-reasoning / high`; session model Opus 5
— one tier below the route, recorded rather than glossed.

The issue arrived unusually well specified: ADR-0083 had already settled the key-id derivation, the
layout, the fail-closed rule and the byte-identity requirement, and the issue text said explicitly
*"already defined, do not re-decide"*. It also named the one part that was genuinely open. `Crypto`
binds its key id with GCM's **AAD**; HMAC has no AAD. There is no second input to authenticate
beside the message — only the message.

So the id went inside the signed bytes: `mac = hmac(keyId ‖ expiry ‖ message)`.

## The alternative that passes almost every test

An id carried in the token but *not* in the signed bytes — a lookup hint — passes every round
trip, every wrong-key test, every tamper test on the message or the expiry. What it fails is one
case: an id rewritten to name **another key the ring genuinely holds**. The lookup succeeds, the
MAC was computed over bytes that never included the id, and it matches. The token verifies under a
key its author did not choose.

That case is why `HmacTest` has two id tests rather than one, and the distinction between them is
the whole assertion:

- `testAnUnknownKeyIdFailsClosedRatherThanTryingTheOtherKeys` — the id resolves to nothing, so a
  refusal proves only that the lookup failed.
- `testASubstitutedKeyIdNamingAnotherHeldKeyIsRefusedByTheMac` — the id resolves to a real key, so
  the lookup *succeeds* and the refusal can only have come from the comparison. The test asserts
  `findByKeyId()` is non-null before tampering, so it cannot silently degrade into the first case.

**Generalisable: a fail-closed test and an authentication test look identical until you make the
lookup succeed.** If every negative case is also an absent case, nothing is being said about the
authentication.

## The plant that survived, and what it was really about

Five defects planted, four caught immediately. The fifth changed the payload length check from
`!==` to `<` and the suite stayed **green**.

Not because the token was accepted — it was still refused. `hash_equals()` compares lengths before
bytes, so an overlong payload sails past a `<` check and dies one step later anyway. Same outcome,
different reason, and the reason is the entire point of the check.

`testAnOverlongPayloadIsRefused` asserted only `CryptoException`. Worse, the docblock on its
neighbour `testACorrectMacPrefixIsRefused` already spelled out the property nothing was enforcing:

> `hash_equals()` compares lengths first and so does not have that flaw, but the payload-length
> check is what makes the property structural here rather than a consequence of which comparator
> happens to be in use.

**The claim was written down and unasserted.** This is a pre-existing gap in the `v1.` suite, from
item 14.4, not something this change introduced — and it took a planted defect to find it, months
after a reviewer had described the exact property in prose.

Fixed by asserting the *message*, so the length check is what has to refuse; the tests are renamed
`...RefusedByTheLengthCheck` to say so. Note only the **overlong** direction can distinguish the
two comparisons — a short payload fails either — which is why truncation tests would never have
caught it. Re-planted afterwards: both paths now fail, as they should.

## Which line is load-bearing, discovered by breaking the other one

The second plant removed the fail-closed refusal, so an unknown key id fell back to trying every
key. Two tests caught it — **but on the message, not on acceptance.** The token was still refused,
because the id is signed and no other key produces a matching MAC.

That is worth recording, because it says which mechanism is actually the security boundary:

- the **MAC over the id** is what makes the id unforgeable;
- **fail-closed** is what makes retiring a key mean something, and what gives an operator a legible
  error instead of a generic signature failure.

Both stay. But if only one could, it is the first — and before this plant I would have described
them the other way round.

## The empty id, which is why `v1.` cannot drift

`sign()` computes `hmac($this->currentKeyId . $expiryBytes . $message)` on **both** paths, with
`currentKeyId` empty on the `v1.` path. `v1.` is therefore not a second code path kept in step with
`v2.` by discipline — it is the same expression with an empty first field, byte-identical to
ADR-0065's grammar by construction.

The third plant made `currentKeyId` unconditional. The **`v1.` conformance vector** was the only
test that failed. Item 14.4 wrote that vector precisely so a silent grammar change would be a
failing test rather than a fleet of tokens that stop verifying after a deploy, and this is the first
time it has earned its keep.

## What the measurement changed

The issue asked for numbers rather than an assumption, since `Hmac` has no NFR budget. Two runs,
agreeing, on this development box (informative absolutes only — it overstates CPU work):

```
construction   1 HKDF                      24.2 - 25.5 us
               3 HKDFs (ring of 3)         46.8 us
verify         v1. bare key                 6.06 - 6.08 us
               v2. ring of 3, by id         6.24 - 6.32 us
               v1. ring of 3, oldest key   12.70 - 13.04 us   <-- 3 candidates
```

The `v1.`/`v2.` per-message gap is 3–4% and **the ordering of two rows flipped between runs**, so
under this repository's standing rule — no claim under ~3% from a single run — the honest statement
is that they are indistinguishable. That rule earned its place twice before and it applies cleanly
here; the two-run discipline is what stopped a 4% "v2. costs slightly more" claim.

The row well outside noise reframed the feature. **`v2.` is not only about rotation — it is what
keeps verification O(1) during one.** A `v1.` token names no key, so a ring must walk its keys, and
a token from the oldest of three costs roughly twice a single check, linear in candidates. Running a
ring on `v1.` indefinitely is not a neutral choice; it is a per-request cost that grows with the
rotation window. That argument is not in the issue and did not exist before the measurement.

Per-key MAC keys are derived once at construction (~11 µs per extra key, paid once), asserted as a
mechanism — ADR-0083's first draft made exactly this mistake with the key id, so it is a regression
guard for a known error rather than a hypothetical.

## A process error of my own, worth more than the fix

To run the suite in the worktree I pointed its `vendor/` at the main checkout's with a directory
junction. PHPUnit reported **41 tests green, including the `v1.` conformance vector** — and it was
testing `master`'s source, not mine. Composer's autoloader resolved the junction to its real path,
so `$baseDir` became the main working tree.

I caught it only because `ConstantTimeComparisonTest`'s registry check failed listing **all five**
constant-time call sites as unregistered, including four I had never touched. A failure that broad
is not a failure about your change. One `ReflectionClass::getFileName()` confirmed it:

```
D:\gh\egl-util-php\src\main\...\Hmac.php        <-- the wrong tree
```

**A green suite is only evidence if it ran against the code you changed.** The tell was there in the
first run — 41 tests passing on a change that adds behaviour no existing test could exercise — and I
read it as reassurance. `composer install` in the worktree, and everything was re-run from scratch.

This is the same family as the earlier "a planted defect that never landed is indistinguishable from
one the tests caught": both are cases where the *absence* of the expected work looks exactly like
success. The junction trick is faster and it is now recorded as unusable.

## Totals

3255 tests (+16), 7942 assertions, 24 pre-existing local skips. PHPStan max clean (two errors in my
own test helpers first — an untyped tuple return and `array_shift()`'s nullable, both fixed by
typing rather than asserting), PHP-CS-Fixer clean, deptrac 436 allowed / 0 violations / 0 uncovered
— no new edge, since `Hmac` already sat beside `SecretKeyRing` in the Security layer.
