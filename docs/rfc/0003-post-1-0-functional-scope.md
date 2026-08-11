# RFC-0003: Post-1.0 functional scope — the seams a frozen library still owes

- **Status:** Accepted
- **Author:** tech-lead (agent-drafted) · **Reviewers:** reviewer, enterprise-architect ·
  **Approver:** tech-lead
- **Date:** 2026-08-10
- **Related:** [RFC-0001](0001-egl-utils-library.md) · [RFC-0002](0002-application-layer-groups-from-legacy-intake.md)
  (both Accepted and fully implemented) · frozen spec
  [`01_spec_utils.md`](../specs/01_spec_utils.md) r16 ·
  [ADR-0059](../adr/0059-freeze-the-api-at-1-0-0-with-internal-symbols-outside-the-frozen-surface.md)
  (the API freeze this RFC must live inside) · [ADR-0049](../adr/0049-tls-options-per-request-and-a-wall-clock-deadline-the-wrapper-cannot-give.md)
  (the wall-clock lesson FR-49 inherits) · [ADR-0054](../adr/0054-authenticated-encryption-with-a-versioned-compact-token.md)
  (the versioned token prefix FR-48 reuses) · [ADR-0040](../adr/0040-install-the-mutation-tester-outside-the-graph-and-let-the-spec-own-its-numbers.md)
  (the spec owns its numbers) · GitHub issues #84 (this planning item), #91–#97 (the candidates),
  #114 (SecretKeyRing) · feeds **Milestone 14**

## Context

`v1.0.0` froze the public API with M1–M12 delivered and both prior RFCs closed. The 2026-08-09
release review board recorded the consequence in its Product Manager seat's finding: **the post-1.0
roadmap contains zero functional direction.** Milestone 13 is documentation and release hygiene
end to end, and no functional backlog exists. The API was frozen at the exact moment the story for
"what is next" became empty.

Seven candidates were filed (#91–#97). A plan pass over them (issue #84) stopped before writing
`ROADMAP.md`, on a blocker this RFC exists to clear: the plan protocol requires every roadmap item
to reference an approved RFC, **all seven require a spec amendment by their own acceptance
criteria**, and no RFC covers them. Milestone 13 is the only RFC-less milestone in the file and it
bought that exemption by declaring itself *"Not spec work"* in its own preamble — a claim M14
cannot make.

**What forces the decision now** is not feature pressure. It is that three of the seven candidates
name the same missing thing as their dependency, and it is absent from the library entirely:

```
$ grep -rn "ClockInterface\|psr/clock" src/main/ composer.json
(no matches)
```

There is no time abstraction anywhere in `src/main`. Retry-with-backoff (#94), rate limiting (#91)
and signature expiry (#92) each need one, and each would otherwise invent its own. A library that
ships three private clocks has made the same mistake three times; the review board's Developer
Platform seat filed the clock separately (#97) precisely because it is a seam, not a feature.

The second force is the freeze itself. Everything below must be **additive** — ADR-0059 permits new
public surface in a MINOR and forbids altering existing signatures. A design pass now is cheaper
than discovering at implementation that a chosen shape cannot be corrected without a MAJOR.

## Decision

Add five functional units across three milestones' worth of work, all additive, all behind
explicit seams. Two of the seven candidates are **deferred with recorded reasons** rather than
accepted.

### The governing constraint

Every item here is bound by ADR-0059: **new symbols only, never a changed signature.** Where an
existing class gains behaviour (`Str`), it gains new static methods and no existing one moves.
Where new dependencies arrive, they are interface-only — the posture `psr/container` and `psr/log`
already established and NFR-08's rule requires.

### Scope: accepted

| FR | Component | Group | Source issue |
|---|---|---|---|
| **FR-45** | `SystemClock`, `FrozenClock` (PSR-20) | `Support` | #97 |
| **FR-46** | `Str::ulid()`, `Str::uuidV7()` | `Support` | #96 |
| **FR-47** | `PageRequest`, `Page<T>` + gateway/repository reads | `Persistence` | #95 |
| **FR-48** | `Hmac` — sign / verify | `Security` | #92 |
| **FR-49** | `RetryPolicy` | `Support` | #94 |

### Scope: deferred, with the reason recorded

- **#91 rate-limiting primitive — deferred.** A token bucket is only a control if its state is
  shared by every node that enforces it. This library owns no shared-state component and no
  storage abstraction beyond the session seam, so the shippable version is single-node. A
  single-node rate limiter deployed behind a load balancer **looks like protection and is not** —
  strictly worse than none, because it removes the pressure to install a real one. Reconsider when
  a storage seam exists and can carry the multi-node honesty statement the issue itself asks for.
- **#93 `utils-psr18-bridge` — deferred.** It reuses the ADR-0033 split-publication machinery,
  which **has never executed**: `bridge-release.yml`'s cross-repository push, release-mode install
  and subtree split are all unexercised (ADR-0035 Consequences records this), and the PSR-7
  bridge's own publication (#120) is currently paused by the maintainer. Building a second consumer
  of an unproven pipeline before the first one has run once is the wrong order.

### New components by group

- **FR-45 `Support\SystemClock` + `Support\FrozenClock`** — both implementing
  `Psr\Clock\ClockInterface`. `SystemClock::now()` returns `new DateTimeImmutable('now')`;
  `FrozenClock` holds a fixed instant with an explicit `advance(DateInterval)`. `psr/clock` joins
  `require` as the third interface-only dependency. **This is the sanctioned time seam**: every
  time-touching API added from here on accepts `ClockInterface`, and the two shipped
  implementations mean a consumer never writes a test double for it.

- **FR-46 `Str::ulid()` and `Str::uuidV7()`** — time-sortable identifiers under the existing
  `Str::random()` CSPRNG discipline (`random_bytes`, never `rand`). Both take an optional
  `?ClockInterface $clock = null` so conformance vectors can be pinned against a fixed instant;
  passing nothing uses the system clock. **Monotonicity within a single millisecond is explicitly
  out of scope** — see the algorithm sketch for why, and for what is guaranteed instead.

- **FR-47 `Persistence\PageRequest` + `Persistence\Page<T>`** — readonly value objects.
  `PageRequest` carries page number and size with clamping refused rather than silent (a size of
  zero or a negative page throws, per the group's stance), plus `withTotal(bool)` defaulting to
  **true**. `Page<T>` carries `items`, `total`, and the derived page count, with `@template`
  generics matching the `TableGateway<T>` discipline — static-analysis only, as `Collection<T>`
  already is. `Repository` and `TableGateway` gain read methods accepting a `PageRequest`.
  **No new SQL door**: `QueryBuilder` already has `limit()`/`offset()` with non-negative validation
  (`QueryBuilder.php:258-274`), so composition stays inside the existing `Identifier` allowlist and
  `SqlStatement::fromQueryBuilder()`.

- **FR-48 `Security\Hmac`** — `sign()` / `verify()` over a **versioned compact token**, the exact
  shape ADR-0054 established for `Crypto`: a `v1.` prefix plus base64url payload. Key material is
  `SecretKey` only — the type is the enforcement, as it already is for `Crypto`. Comparison is
  `hash_equals()`, asserted as a **mechanism** per ADR-0027 because no behavioural test can observe
  a timing-unsafe comparator. Algorithms are an explicit allowlist, never a caller-supplied string.
  Optional expiry is embedded in the signed payload and validated against an injected
  `ClockInterface` (FR-45), so expiry is testable without sleeping.

- **FR-49 `Support\RetryPolicy`** — an explicit policy value object: maximum attempts, jittered
  exponential delay, a retryable-exception allowlist, and — the part ADR-0049 already paid for
  once — a **total wall-clock deadline**. That ADR's finding was that PHP's per-phase timeout
  re-arms and therefore bounds no request; attempt-count alone bounds no retry loop for the same
  reason. Delay is consumed through the clock seam so tests never sleep. Consumed **opt-in** by
  `HttpClient` and transaction callers; never implicit, because a library that silently retries has
  changed a caller's failure semantics without being asked.

### API contract (`api` / `systemdesign`)

- **Operations** — the FR-45…FR-49 surfaces above, under the existing namespace scheme
  (`D4np\Utils\{Support,Persistence,Security}\`). No existing signature is touched.
- **Payloads** — value objects are `readonly` with named constructors, the shape every group here
  already uses (`SqlStatement`, `ApiEnvelope`, `EmailAddress`). `Page<T>`/`Collection<T>` genericity
  is `@template` + PHPStan max, with no runtime enforcement — stated, as `docs/releases/v1.0.0.md`
  already states it for `Collection<T>`.
- **Error model** — every failure is a typed exception from the existing hierarchy. FR-47 raises
  `DatabaseException` (the Persistence group's no-silent-`[]` rule, FR-34); FR-48 raises
  `CryptoException` for a failed verify, never a boolean — the `bool|string` return RFC-0002 named
  as the anti-requirement applies identically here; FR-45/46/49 raise `UtilsException` descendants.
  **`ExceptionHierarchyTest` pins both the discovered-class list and the finality lists**, so any
  new exception type updates both.
- **Versioning** — all additive: a MINOR each. A MAJOR would be required only to *remove* or
  *alter* one of these, which ADR-0059's deprecation window governs. FR-48's token carries its own
  `v1.` prefix, so the token format has a migration path independent of the package version.

### Scalability budgets (`scalability`)

Following ADR-0040, **the spec owns its numbers and this RFC does not invent them.** It names the
axes that get a budget and the method; the values are set from measurement on the reference runner
at implementation, never from this document and never from a developer machine — local timing on
the project's Windows box has overstated CPU-bound work by 2×–5× on every occasion it was checked
(items 10.11, 10.12, 12.3).

- **NFR-15 — identifier generation (FR-46).** The one plausibly hot path here: identifiers are
  generated per inserted row. Gets an absolute budget, measured with a control subject in the same
  job (item 12.4's rule: one control per CI job catches a run-wide slowdown).
- **No budget for FR-45.** `SystemClock::now()` is one `DateTimeImmutable` allocation. NFR-14's
  experience is instructive: its control subject measured 57% of the subject, meaning that budget
  mostly bounds PHP's own method dispatch. A clock budget would measure the same thing and assert
  nothing about this library.
- **FR-47's cost is architectural, not a code-speed axis.** `withTotal(true)` issues a **second
  statement**. That is a round trip, not microseconds, and no `src/bench` subject can express it;
  it is documented as the price of the default and opted out of per request.

**Item 10.10's lesson is a precondition on every number above**: when two requirements constrain
nested scopes on one axis, the outer must be satisfiable *given* the inner's own allowance. NFR-01
and NFR-09 were unsatisfiable from the day they were drafted because nobody performed that
division. Any FR-46 budget is checked against `Str::random()`'s existing cost before it is written
down.

### Algorithm sketch (`pseudocode`)

The one non-obvious decision, and issue #96 names it as the reason for its effort rating:
**monotonicity within the same millisecond.**

```
ulid(clock):
    ms      <- clock.now() as milliseconds since epoch     # 48 bits, time-ordered
    entropy <- CSPRNG(80 bits)                             # Str::random() discipline
    return base32_crockford(ms || entropy)                 # 26 chars, lexicographically sortable
```

Two ULIDs drawn in the same millisecond share a timestamp prefix and are ordered **only by their
random tails** — i.e. not ordered at all. Guaranteeing otherwise requires remembering the previous
call's timestamp and entropy and incrementing it, which means **cross-call mutable state**.

**Decision: guaranteed intra-millisecond monotonicity is out of scope, and the boundary is
tested rather than left to inference.** Reasons, in order of weight:

1. A `static` method holding cross-call state is a global mutable — the shape this library refuses
   everywhere else (`NativeMailer` takes its configuration through a constructor precisely so it
   does not mutate `ini` globals, FR-44).
2. The stated problem is **B-tree index fragmentation**. Millisecond-granular ordering already
   delivers index locality; two rows inserted in the same millisecond land in the same page
   regardless of their relative order. The motivating benefit does not require the guarantee.
3. Wanting the guarantee implies a stateful generator with a lifecycle — a different object, and
   an additive one, so deferring costs nothing later.

What is guaranteed and pinned by test: identifiers from *different* milliseconds sort in time
order; format conformance against the RFC 9562 / ULID specification vectors; the CSPRNG source.
What is pinned as explicitly *not* guaranteed: the relative order of two identifiers from the same
millisecond.

### Cross-cutting

**Security.** FR-48 is the security-surface item and carries the routing floor to prove it
(`os/routing`'s `security-surface` rule is a **protected** floor nothing may lower). Two mechanism
assertions per ADR-0027, because behaviour cannot see either property: that the comparator is
`hash_equals()`, and that the algorithm allowlist is consulted rather than the caller's string.
FR-48 coordinates with the SecretKeyRing issue (#114) on key identifiers — **but does not block on
it**, because ADR-0054's versioned prefix already absorbs the change: key-id-bearing tokens become
`v2.`, and `v1.` tokens keep verifying. The prefix was designed for exactly this and this RFC
spends it rather than inventing a second mechanism.

**Performance.** FR-49's jitter is not decoration: without it, N clients that failed together retry
together, and the retry storm is the outage. Jitter is part of the requirement, not an option.

**Non-goals — the standing answer to scope-creep requests.** Recorded here so it has a citable
home (issue #84's third acceptance criterion): **no money/decimal arithmetic** (`brick/math` is the
right answer and is a third-party pick, not a reimplementation), **no ORM features** (identity map,
change tracking, lazy loading and JOINs are already refused by FR-35's non-goals), **no SMTP
client** (FR-44 states `Mailer` is the seam a `symfony/mailer` adapter plugs into), **no console
or i18n helpers** (neither is a utility concern for a library at this layer).

## Alternatives

1. **Do nothing — let the post-1.0 backlog stay empty.** Rejected: the review board found the gap
   unanimously enough to file eight issues, and the clock's absence is already forcing every
   consumer to hand-roll a test seam. Doing nothing is a decision to keep paying that.
2. **Accept all seven candidates into one milestone.** Rejected on two concrete grounds, not on
   size: the rate limiter would ship a single-node control that misrepresents itself as protection,
   and the PSR-18 bridge would be the second consumer of a publication pipeline that has never run
   once.
3. **A private clock inside each consumer** (no FR-45; retry, HMAC and rate limiting each read the
   system time directly). Rejected: it makes all three untestable without sleeping, and PSR-20
   exists precisely so a library does not invent this. It also violates NFR-08's posture less
   visibly than it violates common sense — three private clocks is the same mistake three times.
4. **`Support\UlidFactory` as a stateful object instead of `Str::ulid()`.** Rejected for the
   default case: it splits identifier generation across two places for a consumer who only wants a
   sortable key, and `Str::uuid()` (v4) already established where consumers look. Recorded as the
   shape a future guaranteed-monotonic generator would take, if demand appears.
5. **Window-function pagination totals** (`COUNT(*) OVER ()`, one statement instead of two).
   Rejected on portability: the project's database proof is **SQLite-only** — no MySQL or
   PostgreSQL CI leg exists (an open review-board finding, issue #110) — so a construct with
   version-dependent support across three engines cannot be claimed to work. Revisit if #110 lands.
6. **`Page<T>` without a total** (no second query ever). Rejected: consumers then hand-roll the
   count query, which is the exact duplication this library exists to remove. The cost is made
   visible and opt-out-able instead of hidden by omission.
7. **Retry bounded by attempt count alone.** Rejected on evidence already paid for: ADR-0049
   established that a per-phase bound re-arms and therefore bounds nothing overall. Attempts
   without a deadline reproduce that defect in a new place.

## Consequences

**Made easier.** Every future time-dependent API has one sanctioned seam and two shipped
implementations. Signed URLs and webhook verification stop being the hand-rolled `===`-and-`sha1`
snippet the security seat flagged. Paging stops being re-derived per estate. Retry gets a shape
that cannot silently run forever.

**Made harder.** A third `require` dependency (`psr/clock`), interface-only and consistent with the
existing two, but it is one more line in the install footprint the dist-hygiene issue (#119) is
already trying to shrink. Five new public surfaces are five more things the freeze forbids
altering — the deprecation window (ADR-0059, `maintenance.md`) is the only exit, and it is
deliberately slow.

**Migration path.** None required: every item is additive and every consumer of `v1.x` is
unaffected until it opts in. The one caller-visible default worth naming is FR-47's
`withTotal(true)` — a consumer paging a large table pays a second query unless it opts out, and
that is stated in the notes rather than discovered in a slow log.

**Follow-up roadmap items.** This RFC feeds **Milestone 14**; the plan pass (issue #84) turns
FR-45…FR-49 into numbered items with sizes and routes. Two prerequisites belong to that pass and
not to this document: **#86** (apply the GitHub type labels — until it lands, `route_advice.py`
resolves every one of these issues to `fast / low` because none carries a label, and no route in
the tracker is machine-verifiable), and a **milestone-item → RFC gate**, since `traceability.py` is
one-directional and verified green today on a roadmap whose M13 references no RFC at all. Deferred
candidates #91 and #93 keep their issues open with the reasons above recorded.

## Approval

Recorded on the maintainer's approval of PR #129 (2026-08-11), the same shape RFC-0002's record
takes:

```
approved-by: tech-lead (2026-08-11)
```

Reviewers (structured findings addressed): **no separate peer-review pass was run.** The protocol's
`reviewer` and `enterprise-architect` seats did not return structured findings on this RFC; the
maintainer read it as opened and approved it directly. Recorded as it happened rather than as two
resolved review marks, because a tick standing for a review that never ran is the failure this
project has already corrected once — `docs/releases/v1.0.0.md` claimed a release gate's approval
that the gate had refused (item 13.1, issue #122). **What that costs, stated:** the design folds
below carry the author's confidence tags and nothing independent has challenged them. The two
decisions most exposed to a missing second opinion are the out-of-scope ruling on
intra-millisecond ULID monotonicity and the deferral of the rate limiter — both argued from stated
premises, neither adversarially tested.

## References

- Issues [#84](https://github.com/danielPoloWork/egl-util-php/issues/84) (planning),
  [#91](https://github.com/danielPoloWork/egl-util-php/issues/91)–[#97](https://github.com/danielPoloWork/egl-util-php/issues/97)
  (candidates), [#114](https://github.com/danielPoloWork/egl-util-php/issues/114) (SecretKeyRing),
  [#110](https://github.com/danielPoloWork/egl-util-php/issues/110) (real-engine DB leg),
  [#86](https://github.com/danielPoloWork/egl-util-php/issues/86) (type labels)
- [PSR-20 Clock](https://www.php-fig.org/psr/psr-20/) · [RFC 9562](https://www.rfc-editor.org/rfc/rfc9562)
  (UUIDv7) · [ULID specification](https://github.com/ulid/spec)
- `docs/specs/01_spec_utils.md` r16 (FR-01…FR-44, NFR-01…NFR-14, T-01…T-15 — this RFC's surface
  continues at FR-45, NFR-15, T-16)
