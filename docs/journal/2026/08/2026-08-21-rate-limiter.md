# 2026-08-21 — Four plants got through, and every one of them changed the work

Roadmap item **14.7**, issue **#91**. Route `frontier-reasoning / extra` (security, adr — protected
floor); session model Opus 5 — **matched**. **M14 closes with this.**

ADR-0061 had already done the hard design thinking at item 14.6: a token bucket, a compare-and-swap
store seam, keys hashed at the limiter's boundary, a store failure that never becomes a decision, and
enforcement scope in every store's first sentence. So the implementation's own decisions were the
narrow ones — and the interesting part of this item is not the code, it is that **four planted
defects escaped a suite I thought was thorough, and fixing that changed both the tests and the
code.**

## The four

**1. Two capacity clamps masked each other.** The refill had `min($capacity, $tokens + $refilled)`
inside it *and* a ceiling immediately after. Remove either and the suite stayed green, because the
other one still fired — so **neither had ever been tested**. The redundant one is gone; one clamp, one
test. Third application of ADR-0022's stance on guards that cannot fail, after `Hash` and `Crypto`.

**2. The refill remainder was invisible to a test that stopped too early.** `lastRefill` advances by
whole tokens so the sub-token remainder carries; the naive `lastRefill = now` discards it. But with
the naive version **the first token still arrives on schedule** — only the carry is lost. My test
asserted the first token and stopped, so it never saw the defect. Extended to a second refill, where
the carried 2 s is exactly what makes the token arrive on time.

**3. A rounding test that asserted the value, not the rule.** `retryAfterSeconds()` rounds up so a
client is never told to return before its token exists. Both my tests used whole-second intervals,
where `ceil` and `floor` agree. Replaced with a genuinely fractional wait (two tokens over three
seconds is 1.5 s each: up is 2, down is 1).

**4. The worst one: a test that passed for the wrong reason.** `testRefillIsCappedAtCapacity` spent
the bucket, idled **an hour**, and asserted three attempts were granted. The TTL is
`capacity × interval` — **sixty seconds**. So the state had expired, the store returned nothing, the
limiter built a fresh full bucket, and the refill branch it claimed to test never ran at all.

That one is worth dwelling on, because it is the same defect class as BUG-0001 two items ago: **a test
whose name describes a branch it never reaches.** Both were found by a plant and by nothing else.
Green tells you nothing about which lines ran.

It also surfaced a real property of the design. Over-refilling needs
`elapsed > capacity × interval`, which *is* the TTL — so the refill ceiling is very nearly
unreachable by idling. It stays, because its other case is plainly reachable and is the one its own
comment names: state written under a larger capacity, read by a limiter whose policy has since been
tightened. Both cases are now tested; before the campaign, neither was.

## ★ The guard I repaired at 14.4 fired on my own code

`ConstantTimeComparisonTest`'s completeness check — the one that had been blind for ten items until
BUG-0001 — named both stores' unregistered `hash_equals()` calls, at file and line, on its first real
opportunity since the repair.

Two things I did with that:

- **Registered rather than downgraded.** The compared value is a CAS version token, not a credential,
  and its matching prefix length reveals nothing an attacker does not know — so `===` would have been
  defensible and would have made the guard quiet. Weakening code to silence a security guard inverts
  item 11.2's rule ("when a guard false-positives, reword your code, not the guard"), and here the
  guard was not even false-positiving: the call really is there.
- **With named locals on both sides.** A registered path whose compared values are method calls makes
  the negative assertion (`$a === $b` never appears) vacuously green — item 14.4's own trap. Avoided
  this time because I had already met it once.

## Implementation decisions ADR-0061 left open

**CAS bound of three, exhaustion refuses.** A conflict means this exact key is being written
concurrently, which for a login throttle *is* the attack signature — so every extra retry is work an
attacker prices, the same objection that ruled out a sliding-window log. Three survives two genuine
concurrent logins and gives up fast under a hammering. Denied, never "unknown".

**Microseconds per token, not a float rate**, so refill is exact integer arithmetic with no float
near a security decision. **The division rounds up**: three per second is 333 333.33… µs, and
rounding down would refill three tokens in 999 999 µs — marginally faster than configured, which is
erring in the direction nobody audits.

**No benchmark subject, and that is the measurement ADR-0061 asked for rather than a shrug.** The
guarded call costs ~100 ms of Argon2id by design; two integer divisions and one short `sha256` are not
a subject, and a number here would bound the consumer's backend I/O rather than this library's code.

**The file store's CAS is genuine for a subtle reason**: the version comparison happens *inside*
`File::update()`'s exclusive lock, so a stale read is caught there. The version is a content hash, not
a counter — two workers reading the same bytes compute the same version without coordinating, and a
counter would need its own storage and its own increment race.

## An omission from item 14.5, corrected

The patterns catalogue's own entry for *Retry with Backoff* said it "moves to *Implemented* with its
own ADR when that item lands". Item 14.5 landed and its PR recorded "no catalogue entry". Both it and
*Rate Limiting / Throttling* are table rows now. The `Planned`-status disagreement between the
vocabulary and `consistency_lint.py` that ADR-0061 recorded is moot for this entry and still open in
general — the next decided-but-unbuilt pattern will hit it again.

## Two operator facts nothing else would have said

Written into `FileRateLimitStore`'s docblock: each key costs **two inodes** (the state file plus
ADR-0005's sidecar lock), and **nothing prunes expired files** — expired state reads as absent but its
file stays until overwritten, so a limiter keyed on user input wants a periodic sweep. Left to the
deployment on purpose: a library deleting files on a schedule of its own choosing would be doing it
inside somebody's request.

## Numbers

3 169 tests (+95), 21 planted defects and 21 caught (after the four above were fixed), PHPStan max,
CS-Fixer, `consistency_lint` clean, deptrac **417 allowed / 0 violations / 0 uncovered with no new
rule** — exactly as ADR-0061 §7 predicted, since `Security → Support` and `Security → Psr` were
already granted. `Support\RateLimitStoreException` joined `ExceptionHierarchyTest`'s two pinned lists,
and that guard fired on the new class as designed.

**M14 closes:** 14.1 the clock, 14.2 sortable identifiers, 14.3 pagination, 14.4 HMAC, 14.5 retry,
14.6 the rate-limiter design, 14.7 its implementation. `README`'s milestone table gains its row.
