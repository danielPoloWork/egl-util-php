# ADR-0061: A token bucket behind a compare-and-swap store, with keys hashed at the boundary

- **Status:** Accepted
- **Date:** 2026-08-13
- **Deciders:** maintainer (`@danielPoloWork`) — reopened issue #91 against RFC-0003's deferral
  and chose the design-first route, both explicitly, 2026-08-13; agent acting as tech-lead — the
  design below
- **Related:** issue [#91](https://github.com/danielPoloWork/egl-util-php/issues/91) · ROADMAP
  items **14.6** (this decision) and **14.7** (the implementation) ·
  [RFC-0003](../rfc/0003-post-1-0-functional-scope.md) § *Scope: deferred* (the deferral this
  lifts, annotated there) · [ADR-0038](0038-one-lock-across-the-read-and-the-write-and-a-sequence-that-refuses-to-wrap.md)
  (`File::update()`, the locked read-modify-write the file store reuses) ·
  [ADR-0056](0056-refuse-the-terminator-at-construction-and-hand-mail-an-array.md) (the
  `Mailer`/`NativeMailer` seam shape this repeats) · RFC-0003 **FR-45** / ROADMAP item 14.1 (the
  clock this consumes) · [ADR-0027](0027-constant-time-comparison-is-asserted-by-mechanism-not-by-timing.md)
  (mechanism assertions the implementation owes) ·
  [ADR-0040](0040-install-the-mutation-tester-outside-the-graph-and-keep-nfr07s-own-number.md)
  (why no budget number appears here)

> **Decision-only.** No code lands with this ADR — item 7.4/ADR-0033's shape: the deliverable is
> the decision, and ROADMAP item 14.7 carries the implementation (after 14.1, whose clock this
> design consumes). The spec amendment (**FR-50** reserved) lands with 14.7, the pattern M14's
> other items follow.

## Context

Issue #91 asked for an in-library throttle for the call sites the Security group already serves —
login endpoints, `Hash::verify()` loops — because the hand-rolled versions are "usually bypassable
(per-node state, resettable windows)". RFC-0003 **deferred** it: this library owns no shared-state
component, so the shippable version would be single-node, and a single-node limiter behind a load
balancer *looks like* protection while enforcing N independent limits — worse than nothing,
because it removes the pressure to install a real one.

Two facts, established since, changed the maintainer's calculus:

1. **The deferral's revisit condition is unreachable as written.** It says *"reconsider when a
   storage seam exists that can carry the multi-node honesty statement"* — and nothing in the
   backlog creates a storage seam. No filed issue builds one, and
   [`third-party-picks.md`](../patterns/third-party-picks.md) (issue #90) deliberately routes
   caching to `symfony/cache`, so an in-library storage abstraction will never arrive by another
   road. The seam the deferral waits for is exactly what #91's own design defines: deferred on
   that condition, it stays deferred forever.
2. **The deferral's real objection is answerable by design rather than by waiting.** The objection
   was never "a rate limiter is out of scope"; it was "a single-node limiter that presents itself
   as protection is a lie." A seam whose enforcement scope is explicit — stated in the interface's
   own contract and in every shipped store's docblock — answers the objection the way this library
   answers everything else: by refusing to let the dangerous case be the silent default.

So the maintainer reopened #91 on 2026-08-13 and commissioned this design. The deferral is
**resolved, not overridden**: its reasoning stands as written in RFC-0003 (annotated, per the
house rule), and every constraint it named is a requirement below.

## Decision

### 1. The algorithm is a token bucket

Per key: a bucket of capacity **B** tokens refilling at rate **r**; an attempt consumes one token;
an empty bucket refuses. State per key is two numbers — the token count and the timestamp of the
last refill — and refill is computed lazily from elapsed time at read, so nothing ticks in the
background (there is no background in PHP's execution model to tick in).

Why this one, against the field (each rejection is concrete — see Alternatives for the full
table): a **fixed window** institutionalizes the estate's own defect — the issue's "resettable
windows" complaint *is* the boundary burst, 2× the intended rate for an attacker who straddles the
window edge; a **sliding log** stores a timestamp per request per key, which hands the attacker
being throttled a lever on the defender's memory; a **sliding-window counter** is an interpolated
approximation, and an approximation sold as a limit is the "looks like protection" shape wearing a
different coat; **GCRA** is mathematically the same control as a token bucket with more compact
state and less legible arithmetic — and in this codebase, where the endpoint kernel was chosen
over a middleware pipeline explicitly because a reader can follow straight-line code, legibility
is a requirement, not a preference.

The policy is a **readonly value object** (working name `RateLimitPolicy`): capacity and refill
rate, named constructors, nonsense refused at construction (a zero capacity, a non-positive rate)
— the same shape `RetryPolicy` (FR-49) takes in the same milestone, because they are the same kind
of thing: an explicit mechanism a caller states once.

### 2. The seam is compare-and-swap, because get/set cannot be made safe by any caller

The store interface (working name `RateLimitStore`) exposes **atomic conditional replacement**,
not reads and writes:

```
read(key)                       -> {state, version} | null
writeIfVersion(key, state, ttl, expectedVersion) -> bool   // null version = create-if-absent
```

`state` is an opaque byte string the limiter serializes (the store never interprets it);
`version` is an opaque token the store defines (a counter, a content hash — its business). The
limiter runs the loop: read → compute refill and consumption → `writeIfVersion` → on conflict,
re-read and retry, **bounded**.

This is the load-bearing choice, and it is what the deferral's "per-node state" complaint turns
into at interface level. A `get()`/`set()` store **cannot be composed race-free by any caller**:
two nodes read one remaining token, both approve, both write zero — the limit is exceeded *by the
limiter*, at exactly the concurrency a brute-force attack produces. With get/set, every backend
implementor inherits a TOCTOU bug as the default outcome; with CAS, atomicity is the interface's
stated contract and a store that cannot honor it has no honest way to exist. Every serious backend
has a native CAS to build on — Redis (`WATCH`/`MULTI` or a Lua script), APCu (`apcu_cas`), any
SQL engine (optimistic `UPDATE … WHERE version = ?`), and a locked file (where exclusivity makes
the version check trivially true).

**Bounded retries, and exhaustion refuses.** CAS conflict means the key is being hammered
concurrently — for a login throttle, that *is* the attack signature — so after N conflicts the
limiter answers "denied", never "unknown". The exact N lands with the implementation; what is
decided here is the direction of the failure.

### 3. A store failure is never an allow

The limiter converts **no** store failure into a decision. A store that throws propagates —
typed, per the house rule FR-34 established by omission (no `catch` anywhere in `Repository`,
because every failure below it is already typed and must be seen). The caller — the only party
who knows whether this endpoint prefers lockout or exposure when the backend is down — makes the
availability-versus-security call at its own `catch`.

What is refused here is the *silent* version of either policy: a limiter that quietly allows on
store failure has reproduced the deferral's nightmare (protection that evaporates exactly when
infrastructure degrades, which is when attacks are cheapest), and one that quietly denies has
decided an outage on the caller's behalf. The implementation's documentation owes one sentence of
guidance: catching the store failure and returning "allowed" recreates the silent-failure hole,
so if an endpoint chooses fail-open it should do so loudly (log at error, alert) — and that
sentence lands in 14.7's docblocks.

### 4. Keys are hashed at the boundary, and that is the whole key-safety story

The caller supplies a namespace and a key (`'login'`, the target username). The limiter — not the
store — canonicalizes them into the storage key:

```
storage_key = hex(sha256( len(ns) ‖ ns ‖ len(key) ‖ key ))
```

Every store therefore receives a **fixed-length, fixed-alphabet token**, and three problems are
gone by construction rather than by per-store discipline:

- **Store-syntax injection**: a user-controlled key cannot carry a Redis separator, a SQL
  wildcard, or — the one that would be a vulnerability in this library's own shipped store — a
  **path traversal** into the file store's directory. User input never becomes a filename.
- **Unbounded keys**: an attacker cannot inflate per-key storage by sending kilobyte usernames;
  every key costs the same 64 hex characters.
- **Content-shaped timing**: issue #91 asked for "constant-time-safe key handling". Two raw keys
  differing in the first byte versus the last are indistinguishable after hashing, so any
  store-side comparison is content-oblivious *by construction* — satisfied once at the boundary
  instead of by auditing `hash_equals` usage across N consumer-written stores this library will
  never see.

The length prefixes are domain separation — `("ab","c")` and `("a","bc")` must not collide — the
same discipline ADR-0054 applied by slicing fixed offsets instead of trusting delimiters.

### 5. Time comes from the clock seam, and a skewed clock cannot mint tokens

Refill math consumes **`Psr\Clock\ClockInterface`** (FR-45, item 14.1 — the dependency is why
implementation is sequenced after the clock lands). Deterministic tests use `FrozenClock`; no test
sleeps.

The adversarial case the issue names — **clock skew** — gets a decided rule, not a hope: elapsed
time is computed as `max(0, now − last_refill)`, and the refilled count is capped at capacity. A
node whose clock runs behind the one that wrote the state sees negative elapsed and refills
**zero** — it can under-grant (deny slightly early), never over-grant. Skew degrades toward
strictness, which for a security control is the correct direction to degrade. Monotonic time is
deliberately not assumed: the state crosses nodes, and no node's monotonic counter means anything
on another.

### 6. Two stores ship, and each states its enforcement scope in its own docblock

The `Mailer`/`NativeMailer` shape, a second time: the interface is what application code depends
on; the shipped implementations are the honest native transports; the fuller backend is the
consumer's to plug in ([`third-party-picks.md`](../patterns/third-party-picks.md) already frames
Redis-class infrastructure as bring-your-own).

- **An in-memory store** (working name `ArrayRateLimitStore`) — one process, exact. Under
  PHP-FPM that means **one request**: it enforces nothing across requests and its docblock says
  so in the first sentence. It exists for tests (the `FrozenClock` of stores) and for the one
  production shape where it is genuinely correct: a single long-running worker (a CLI daemon, a
  resident application server), where one process *is* the whole node.
- **A file store** (working name `FileRateLimitStore`) — built on
  [`File::update()`](0038-one-lock-across-the-read-and-the-write-and-a-sequence-that-refuses-to-wrap.md),
  the locked read-modify-write ADR-0038 already proved under real multi-process contention
  (T-14). Enforcement scope: **every PHP-FPM worker on one machine, and no further.** Under the
  exclusive lock the CAS version check is trivially satisfied, so the store is the seam's
  degenerate — and correct — local case. TTL is honored by storing expiry inside the state file
  rather than trusting filesystem timestamps.

**The multi-node honesty statement** — the deferral's named requirement — is decided here
verbatim, and 14.7 places it in the interface docblock, both store docblocks, and the pattern
page:

> A rate limit exists at the scope its store is shared, and nowhere else. Behind a load balancer,
> a per-machine store means each node enforces its own independent limit: the effective limit is
> N× the configured one, and an attacker who spreads requests across nodes is throttled by none
> of them. Multi-node enforcement requires a store every node shares — a consumer-implemented
> `RateLimitStore` over Redis or equivalent. This library ships the algorithm and the seam; it
> deliberately ships no network client.

And its second half, the one that is about the control rather than the deployment: a rate limiter
bounds *attempt frequency through the keys you chose*. Keyed on source IP alone, it is defeated
by address rotation; credential-stuffing defense keys on the **target identity** (and optionally
also the source). That guidance is documentation the implementation owes, not code.

### 7. Placement and what is deliberately absent

The limiter, policy, decision value and store interface land in **`Security\`** (the issue's
seat, the keying discipline's home); the file store's `Support\File` use rides the standing
group→Support grant, so **no new deptrac edge** — asserted at implementation with the usual
planted-violation proof. The decision value (working name `RateLimitDecision`) carries
`allowed()` and `retryAfter()` — a denial is a **normal outcome**, not an exception; the typed
exception (working name `RateLimitStoreException`, joining the hierarchy and
`ExceptionHierarchyTest`'s pinned lists) is reserved for the store failing, per §3.

Deliberately absent, stated so the next request meets an answer: **no middleware/PSR-15
integration** (the endpoint kernel is straight-line code by choice), **no automatic wiring into
`HttpClient` or `Session`** (an implicit limiter changes callers' semantics unasked — FR-49's
opt-in rule applies identically), **no Redis/Memcached store in-library** (a network client is a
dependency the NFR-08 posture refuses; the seam is the product), **no distributed-consensus
ambitions** (CAS over a shared backend is the honest ceiling of what a library at this layer can
promise). **No benchmark budget is set here**: ADR-0040 reserves numbers for the spec, and the
guarded path costs ~100 ms of Argon2id *by design* — the limiter's job is to be invisible next to
that; whether it earns a subject at all is measured at 14.7, not asserted.

## Alternatives Considered

1. **Fixed window** — one counter per key per aligned window. Rejected: the boundary burst (2×
   the intended rate for an edge-straddling attacker) is precisely the "resettable windows"
   defect issue #91 was filed against; shipping it as the library's primitive would
   institutionalize the estate's bug with better ergonomics.
2. **Sliding-window log** — exact, but memory is a timestamp per request per key: the party being
   throttled controls the defender's storage. An abuse control whose cost is set by the abuser is
   backwards. Rejected.
3. **Sliding-window counter** — two buckets and linear interpolation. Rejected: the interpolation
   assumes uniform arrival, i.e. it is an *estimate*, and an estimate presented as a limit is the
   "looks like protection" shape in miniature. This library refuses to guess elsewhere; it does
   not get to guess here.
4. **GCRA** — equivalent control, one stored timestamp, opaque arithmetic. Rejected on
   legibility, the same axis that chose the endpoint kernel over a pipeline: a reviewer can
   check a token bucket's refill in their head; GCRA's theoretical-arrival-time algebra they
   take on faith. Revisit only if measured state size ever matters, which nothing suggests.
5. **A `get()`/`set()` store seam** — rejected as unsound, not merely inferior: the check-then-act
   race lives in the *caller*, so every store implementor inherits it by default and no library
   code can close it. The interface would be an attractive nuisance with this library's name on
   it (§2).
6. **Pushing the whole decision into the store** (`consume(key, policy) -> decision`, à la a Lua
   script owning the math). Rejected: maximal backend fidelity, but the library then ships a name
   and no mechanism — every consumer reimplements the bucket per backend, which is the ad-hoc
   duplication this library exists to remove. A consumer with a Lua-scripted limiter doesn't need
   this library; one without gets the math from it.
7. **Interface only, no shipped store.** Rejected: the first thing every consumer would write is
   the racy store §2 exists to prevent, and the primitive would be untestable out of the box —
   against the house pattern of shipping the test double (`FrozenClock`, `RecordingMailer`).
8. **Raw keys passed to the store, safety left to implementors.** Rejected: the library's own
   file store would turn user input into filenames (a traversal vulnerability *in this
   codebase*), and "each store audits its own injection surface" is the two-copies-of-the-
   allowlist failure ADR-0044 spent an item removing. One boundary, hashed, owned by the limiter
   (§4).
9. **Fail-open or fail-closed baked in on store failure.** Both rejected in favor of §3's
   propagation: baked-in fail-open evaporates silently under infrastructure stress (the
   deferral's exact nightmare); baked-in fail-closed decides an outage for callers who never
   chose it. The caller owns the trade; the library refuses only the *silent* version of either.
10. **Stay deferred** — the status quo RFC-0003 chose. Rejected by the maintainer on 2026-08-13,
    on the finding that the revisit condition was unreachable (no backlog item creates the seam
    it waits for) and that every constraint the deferral named is satisfiable by the design
    above: the honesty statement has a home, the single-node stores state their scope in their
    own docblocks, and the multi-node path is a real seam rather than a promise.

## Consequences

- **ROADMAP**: item 14.6 (this decision) closes with this PR; item **14.7** carries the
  implementation — after 14.1, whose clock §5 consumes — with **FR-50** reserved for the spec
  amendment and the suite number claimed at landing, per M14's pattern. Issue #91 stays open
  until 14.7 ships, now milestoned `v1.1.0`.
- **RFC-0003** is annotated (Status note + inline marker at the deferral bullet), not rewritten:
  the deferral's reasoning stands as history; this ADR is where its objection got answered.
- **The patterns catalogue** gains *Rate Limiting / Throttling* as **Planned** (decided in an
  ADR, not yet in code — the vocabulary's exact case), and
  [`third-party-picks.md`](../patterns/third-party-picks.md)'s "deliberately not on this page"
  note now distinguishes #91 (reopened, Planned) from #93 (still deferred).
- **The implementation owes mechanism assertions** (ADR-0027): behaviour cannot see that the
  comparator-free key path is hash-then-lookup, that elapsed time clamps at zero, or that the
  CAS loop is bounded — each gets pinned as a mechanism, since a suite that only watches
  allow/deny outcomes stays green when any of them silently vanishes.
- **What this does not settle**: the exact public signatures (spec r17's job at 14.7), the CAS
  retry bound, whether a benchmark subject is warranted, and any future in-library Redis store —
  the last deliberately, per §7.

## References

- Issue [#91](https://github.com/danielPoloWork/egl-util-php/issues/91) (the ask and its
  acceptance criteria: ADR with algorithm choice + multi-node honesty statement; additive API;
  adversarial tests for clock skew, burst, key collision)
- [RFC-0003](../rfc/0003-post-1-0-functional-scope.md) § *Scope: deferred* — the deferral this
  resolves, and § *Non-goals* — the do-not-add list this stays inside
- [ADR-0038](0038-one-lock-across-the-read-and-the-write-and-a-sequence-that-refuses-to-wrap.md) —
  `File::update()`, the locked RMW the file store reuses; proven multi-process by T-14
- [ADR-0056](0056-refuse-the-terminator-at-construction-and-hand-mail-an-array.md) — the
  interface/native-transport/bring-your-own-backend shape this repeats
- [ADR-0054](0054-authenticated-encryption-with-fixed-lengths-and-a-key-only-secretkey-can-produce.md) —
  the fixed-shape/domain-separation discipline §4's key hashing follows
- [ADR-0027](0027-constant-time-comparison-is-asserted-by-mechanism-not-by-timing.md),
  [ADR-0040](0040-install-the-mutation-tester-outside-the-graph-and-keep-nfr07s-own-number.md) —
  the verification and budget rules the implementation inherits
- Token bucket / GCRA equivalence: standard results; see e.g. RFC 2697/2698 (single/two-rate
  three-color markers) for the traffic-policing lineage the algorithm comes from
