# ADR-0067: The bucket refills in whole tokens, and the store contract is tested twice

- **Status:** Accepted
- **Date:** 2026-08-21
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item **14.7** · issue [#91](https://github.com/danielPoloWork/egl-util-php/issues/91) ·
  spec **r22 FR-50** ·
  [ADR-0061](0061-a-token-bucket-behind-a-compare-and-swap-store-and-keys-hashed-at-the-boundary.md)
  (**the design this implements**; every numbered section below answers a question it left to 14.7) ·
  [ADR-0038](0038-one-lock-across-the-read-and-the-write-and-a-sequence-that-refuses-to-wrap.md)
  (`File::update()`, the locked read-modify-write §3's store is built on) ·
  [ADR-0062](0062-the-clock-seam-ships-both-halves-and-support-gains-its-first-outward-edge.md)
  (the clock, and the ship-both-halves rule §3 follows) ·
  [ADR-0066](0066-a-second-seam-for-waiting-and-a-deadline-that-only-bounds-the-loop.md)
  (the plain-parameters typing rule §1 inherits) ·
  [ADR-0027](0027-constant-time-comparison-is-asserted-by-mechanism-not-by-timing.md)
  (the three mechanism assertions §5 owes) ·
  [ADR-0022](0022-argon2id-by-default-with-a-fallback-decided-at-construction.md)
  (the redundant-guard stance §5's removed clamp follows) ·
  [ADR-0005](0005-atomic-file-writes-with-a-sidecar-lock.md) (the sidecar lock §3's inode cost comes from)

## Context

ADR-0061 decided the design and deliberately shipped no code: a token bucket, a compare-and-swap
store seam, keys hashed at the limiter's boundary, enforcement scope stated in every store's own
docblock, and a store failure that never becomes a decision. It named four things it did not settle —
the public signatures, the CAS retry bound, whether a benchmark subject is warranted, and any future
Redis store (that one refused permanently).

This ADR is the implementation's own decisions. The design is not revisited; where a question was
open, the answer and its reasoning are here.

## Decision

### 1. The refill is integer arithmetic over an interval, not a float rate

`RateLimitPolicy` stores **microseconds per token**, not tokens per second. Refilled tokens are
`intdiv(elapsed, interval)` — no float goes anywhere near a security decision, and a long-lived
bucket cannot accumulate rounding error the way `tokens += elapsed * 0.7` does.

**The division rounds up, and the direction is the decision.** Three tokens per second is
333 333.33… µs each; rounding down to 333 333 refills three tokens in 999 999 µs, marginally *faster*
than configured. For a security control, erring permissive is erring in the direction nobody audits.
Rounding up means the effective rate is at or just under what was asked for — the same
degrade-toward-strictness rule ADR-0061 §5 applies to clock skew.

**`lastRefill` advances by whole tokens, never to `now`.** The sub-token remainder carries into the
next read. Setting it to `now` would discard whatever fraction of an interval had accrued, so a key
polled faster than its refill rate would never refill at all — a limiter that gets *stricter* the
more often it is asked. That defect survives any test that watches only the first token arrive, which
is exactly what the campaign found (§5).

Bounds are plain `int` parameters with runtime refusals, per ADR-0066 §4: a `positive-int` parameter
would make its own `< 1` throw unreachable from type-correct code and therefore untestable without a
suppression this project forbids. A `DateInterval` window is **measured against a fixed reference
date** rather than multiplied out of its calendar fields, because a month has no fixed length — and
the reference is a constant so that the same policy does not become a different one depending on the
month it was constructed in.

### 2. The CAS bound is three, and exhaustion refuses

ADR-0061 left the number to the implementation and decided only the direction of the failure.

**Three.** A conflict means this exact key is being written concurrently, and for a login throttle
that *is* the attack signature — so every extra retry is work an attacker sets the price of, which is
the objection that ruled out a sliding-window log in ADR-0061's alternatives. Three survives
incidental contention (two genuine concurrent logins for one account) and gives up quickly under a
hammering. Exhaustion answers **denied**, never "unknown": contention on a throttled key is evidence
for denial, not against it.

### 3. Both stores, and what the file store costs

The in-memory store's CAS is real rather than a stub — a per-key counter, compared before replacing —
because PHP has one thread of execution per process, which makes it the seam's simplest *correct*
implementation. Its docblock's first sentence says it enforces nothing across requests, because a
store that says nothing gets deployed as if it said "global".

The file store's CAS is genuine for a subtler reason worth writing down: **the version comparison
happens inside `File::update()`'s exclusive lock.** Whatever `read()` returned may be stale by the
time `writeIfVersion()` runs, and the comparison *inside* the lock is what catches that. The version
is a **content hash** rather than a counter, which follows from the same lock: two workers that read
the same bytes compute the same version without coordinating, and any write by either changes it. A
counter would need its own storage and its own increment race.

The TTL lives **inside the file**, ahead of the state, not inferred from `mtime`. An `mtime` is moved
by a backup, a `touch`, an rsync or a container rebuild, none of which mean the bucket is fresh and
all of which would silently extend or reset a throttle.

**Two facts an operator needs, which nothing else in the tree would tell them.** Each key costs
**two inodes** — `<hash>.bucket` and the `<hash>.bucket.lock` sidecar ADR-0005 keeps the lock on so
the atomic rename cannot pull it out from under a waiting process. And nothing prunes them: expired
state reads as absent, but its file stays until overwritten, so a limiter keyed on user input wants a
periodic sweep. That is left to the deployment deliberately — a library deleting files on a schedule
of its own choosing would be doing it inside somebody's request.

### 4. The TTL is `capacity × interval`, and that choice has a consequence worth naming

A bucket may be forgotten once it would have refilled to capacity, because a full bucket and an
absent one are indistinguishable. Expiring any earlier hands a throttled key a fresh burst, which is
the one way a TTL can lose enforcement.

The consequence the campaign surfaced: since `refilled > capacity` requires `elapsed > capacity ×
interval`, and that is exactly the TTL, **the refill's own ceiling is very nearly unreachable through
idling** — an idle long enough to over-refill has also expired the state. The ceiling stays, because
its other case is plainly reachable and is the one its comment names: state written under a larger
capacity, read back by a limiter whose policy has since been tightened. Both are now tested; before
the campaign, neither was.

### 5. Three mechanism assertions, and one guard removed

ADR-0061 said the implementation owes mechanism assertions because a suite watching only allow/deny
outcomes stays green when any of three properties silently vanishes. All three are pinned:

- **the key path is hash-then-lookup**, comparing nothing — which is what makes it content-oblivious
  by construction rather than by audit;
- **elapsed time clamps at zero** — the clock-skew rule, one expression;
- **the CAS loop is bounded by a constant** — and this is the one behaviour genuinely cannot reach:
  an unbounded loop against a permanently conflicting store does not *fail* a test, it **hangs** it,
  and a hanging suite is diagnosed as a flake.

**One guard was removed rather than tested.** The refill had a `min($capacity, …)` inside it *and* a
ceiling immediately after, and the campaign found the two masked each other — removing either left
the suite green, so neither had ever been tested. One clamp, one test (ADR-0022's stance on redundant
guards, third application after `Hash` and `Crypto`).

## Alternatives Considered

- **A float refill rate** (tokens per second) — rejected in §1: rounding error accumulating inside a
  security control, and no exact way to state "one token every 20 seconds".
- **`lastRefill = now` after a refill** — rejected in §1. It is the simpler line and it makes a
  frequently-polled key refill more slowly than a quiet one.
- **Rounding the refill interval down** — rejected in §1, on direction rather than magnitude.
- **A counter version for the file store** — rejected in §3: it needs storage and an increment race
  the content hash does not have.
- **`mtime` as the TTL** — rejected in §3, on the list of ordinary operations that move it.
- **Pruning expired files on read or on a timer** — rejected in §3; a library must not spend a
  caller's request on housekeeping it was not asked for. Named in the docblock instead.
- **A TTL longer than `capacity × interval`** — considered because it would make the refill ceiling
  reachable by idling and therefore easier to test. Rejected in §4: a test's convenience is not a
  reason to keep state longer than it means anything, and the ceiling's real case is testable
  directly.
- **Policy passed per `attempt()` call** rather than at construction — rejected: one limiter per
  policy keeps the invariant that a key's bucket is always read under the policy that wrote it, and
  it matches `Retrier`'s shape in the same milestone.
- **A benchmark subject** — not added, and this is the measurement ADR-0061 asked for rather than a
  shrug: the guarded path costs ~100 ms of Argon2id *by design*, and the limiter's whole job is to be
  invisible next to that. Two integer divisions and one `sha256` of a short string are not a subject;
  a number here would bound the store's I/O, which is the consumer's backend and not this library's
  code. ADR-0040 reserves spec numbers regardless.

## Consequences

- `Security\{RateLimiter, RateLimitPolicy, RateLimitDecision, RateLimitRecord, RateLimitStore,
  ArrayRateLimitStore, FileRateLimitStore}` and `Support\RateLimitStoreException`, which joins
  `ExceptionHierarchyTest`'s two pinned lists — the guard fired on the new class exactly as designed,
  which is worth recording as the pin working rather than as an inconvenience.
- **No new deptrac rule**, as ADR-0061 §7 predicted: `Security → Support` and `Security → Psr` were
  both already granted, so the file store's `File` use and the clock cost nothing architecturally
  (417 allowed, 0 violations, 0 uncovered). Stated because an absence leaves no trace.
- **The store contract is tested against both implementations** through one data provider, because a
  contract only one implementation was ever checked against is not a contract — the same reasoning
  the PSR-7 bridge's suite uses against two PSR-17 implementations. That is what makes a consumer's
  own Redis store a drop-in rather than a hope.
- **The patterns catalogue gains two rows**, not one. *Rate Limiting / Throttling* moves from the
  Planned bullet ADR-0061 created to *Implemented*. And *Retry with Backoff* is added for item
  **14.5**, which shipped without it: the catalogue's own entry said it "moves to *Implemented* with
  its own ADR when that item lands", and the previous item's PR recorded "no catalogue entry"
  instead. A miss, corrected here rather than left for a reviewer to find.
- **21 planted defects, 21 caught — but only after four escaped and changed the work.** The four are
  the item's real verification story: the two capacity clamps masking each other (§5), the refill
  remainder invisible to a test that stopped at the first token (§1), a `retryAfterSeconds` test
  written on a whole-second value where `ceil` and `floor` agree, and — the worst of them —
  `testRefillIsCappedAtCapacity` idling an hour past a sixty-second TTL, so the state had **expired**
  and the refill branch never ran at all. That last one passed for the wrong reason and named a
  property it did not test. **A test whose name describes a branch it never reaches is the same
  defect class as BUG-0001 two items ago**, and a plant is the only thing that has caught either.
- **What this does not settle**: a future in-library Redis store stays refused (ADR-0061 §7); PSR-15
  middleware and automatic wiring into `HttpClient`/`Session` stay out for the same opt-in reason
  FR-49 gives; and the `Planned`-status disagreement between the pattern vocabulary and
  `consistency_lint.py`'s patterns check — recorded in the catalogue on 2026-08-13 — is now moot for
  this entry but still unresolved in general, and still the maintainer's call.
