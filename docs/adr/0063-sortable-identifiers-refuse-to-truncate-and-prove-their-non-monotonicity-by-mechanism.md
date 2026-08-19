# ADR-0063: Sortable identifiers refuse to truncate, and prove their non-monotonicity by mechanism

- **Status:** Accepted
- **Date:** 2026-08-19
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item **14.2** · issue [#96](https://github.com/danielPoloWork/egl-util-php/issues/96) ·
  spec **r18 FR-46**, **NFR-15** · [RFC-0003](../rfc/0003-post-1-0-functional-scope.md)
  (the design this realizes, and the algorithm sketch that ruled on monotonicity) ·
  [ADR-0062](0062-the-clock-seam-ships-both-halves-and-support-gains-its-first-outward-edge.md)
  (the clock seam these consume) · [ADR-0027](0027-constant-time-comparison-is-asserted-by-mechanism-not-by-timing.md)
  (the mechanism-assertion rule §2 leans on) ·
  [ADR-0040](0040-install-the-mutation-tester-outside-the-graph-and-keep-nfr07s-own-number.md) /
  [ADR-0058](0058-an-absolute-ceiling-needs-twice-the-worst-reading-and-catches-accumulation.md)
  (who owns NFR-15's number, and the rule that derives it) ·
  [RFC 9562](https://www.rfc-editor.org/rfc/rfc9562) · [ULID specification](https://github.com/ulid/spec)

## Context

`Str` generated only v4 UUIDs. As a primary key at enterprise table sizes a random UUID fragments
a B-tree index: every insert lands at an arbitrary leaf, so the working set is the whole index
rather than its tail. Time-sortable identifiers are the standard remedy, and both mainstream
formats need zero dependencies — which is why RFC-0003 accepted FR-46 rather than routing it to a
third-party pick.

RFC-0003 decided the surface (both formats, in `Str`, on the FR-45 clock seam) and made one
substantive ruling in its algorithm sketch: **monotonicity within a single millisecond is out of
scope**, because guaranteeing it requires cross-call state in a static method, and the stated
problem — index locality — is a millisecond-granularity property that the guarantee does not
improve. It also asked for that boundary to be *"pinned by test in both directions"*.

Implementing it surfaced the question that ruling leaves open: **how do you assert an absence?**
Everything else here follows from the formats' specifications; this ADR exists mostly for that.

## Decision

### 1. Both formats ship, because they are not substitutes

`ulid()` returns 26 Crockford-Base32 characters; `uuidV7()` returns the familiar 36-character
UUID. They are the same 48-bit-timestamp-then-entropy idea in two encodings, and a consumer
cannot freely swap them: **v7 is the answer when the column, validator, ORM cast or downstream
service expects a UUID** — it is a valid UUID everywhere `uuid()` is, and a test asserts both
match one shape. **ULID is the answer otherwise**: shorter, no separators, and drawn from an
alphabet chosen so that a human transcribing one cannot confuse `I`/`1` or `O`/`0`. Shipping one
would have forced half the consumers to hand-roll the other.

### 2. Non-monotonicity is pinned as a **mechanism**, because behaviour cannot see it

This is the decision worth the ADR. Asserting *"these are ordered"* is easy. Asserting *"these are
**not** guaranteed ordered"* has no honest behavioural form:

- Asserting the identifiers come out unsorted is **probabilistic** — true with overwhelming
  probability and flaky by construction, which is the shape this project refuses in a gate.
- Asserting they are merely *distinct* proves the entropy is live, not that ordering is absent.
- And crucially: **a monotonic implementation passes every behavioural test in the suite.** More
  ordering never contradicts an assertion that some ordering exists.

So the pin is an assertion on the class's shape: `Str` declares **no properties at all**
(`ReflectionClass::getProperties()` is empty). Monotonicity requires remembering the previous
call; remembering requires state; state on a static-only class means a property. The assertion
therefore fails the moment someone implements the thing FR-46 excludes — forcing the spec change
that decision would need, rather than letting the guarantee arrive silently and become
load-bearing for a consumer who was told it did not exist.

**Verified non-vacuous, and the number is the point.** Planting a `private static int
$lastMillisecond` — the first line of any monotonic implementation — failed **exactly one** test:
this one. The other nineteen stayed green. That is ADR-0027's rule producing its clearest instance
yet: the mechanism assertion is not belt-and-braces here, it is the only thing standing between
the decision and its silent reversal.

The companion behavioural assertions pin what *is* guaranteed, so the pair brackets the boundary:
identifiers from different milliseconds sort in generation order (25 of them, both formats), one
millisecond of separation is enough (not one second — a second-resolution timestamp passes every
other sorting test and fails only this one), and identifiers from the same millisecond share a
timestamp prefix while remaining distinct.

`uuidV7()`'s `rand_a` field follows from the same ruling: RFC 9562 permits a sub-millisecond
counter there, and this implementation fills it with **entropy instead**. Adopting the counter
would be adopting monotonicity by the back door, in one format only, contradicting FR-46's ruling
and the assertion above.

### 3. An unrepresentable instant is refused, never truncated

Both formats spend exactly 48 bits on time. An instant before the Unix epoch or beyond
`10889-08-02T05:31:50.655Z` cannot be encoded, and the alternative to refusing is masking the
value into range.

Truncation is worse than an error **specifically here**: a wrapped timestamp produces a
well-formed identifier that **sorts wrongly**, silently defeating the single property the format
exists for, in a value that will sit in a primary-key column for years. A clock that reports 1969
is a misconfiguration, and the caller finds out at the call rather than at a report six months
later. `InvalidArgumentException` matches `Str::random()`'s existing precedent for the same class
of refusal, and the message names the *public* method the consumer actually called rather than
the private helper that raised it.

The boundaries are inclusive on both sides, and both edges are tested — an off-by-one at the
epoch would be invisible until a fixture used it.

### 4. Milliseconds come from `U * 1000 + v`, not from a float

`microtime(true)` returns a float, and reading a millisecond field out of it invites a rounding
error at the boundary. `format('U')` (whole seconds, negative before the epoch) plus `format('v')`
(the 0–999 milliseconds *within* the second, never negative) is exact integer arithmetic — and it
is what makes §3's pre-epoch detection work at all, since the sum goes negative exactly when the
instant does.

### 5. The two halves encode independently

48 bits of timestamp and 80 bits of entropy are **both whole multiples of five**, so the ULID
encoder does not need 128-bit arithmetic: ten characters from the timestamp integer, sixteen from
a ten-byte bit stream. There is no padding case, so no padding convention is invented. The
consequence a reader can check: 26 characters carry 130 bits against the format's 128, so the
leading two bits are always zero and **a well-formed ULID's first character never exceeds `7`** —
asserted at the maximum representable instant, where the timestamp encodes to `7ZZZZZZZZZ`.

### 6. NFR-15 is measured, not chosen

Identifier generation is the one plausibly hot path in FR-46's surface — drawn once per inserted
row, so its cost rides every write. It therefore gets an absolute budget, and the budget is
derived on the reference runner rather than this developer machine (which has overstated
CPU-bound work 2–5× on every occasion this project has checked), following ADR-0058's rule that
an absolute ceiling sits at **≥ 2× the worst observed reading**.

`SortableIdentifierBench` measures `benchUlid` and `benchUuidV7` beside **`benchRandomToken`**
(`Str::random(10)`). The third subject was added on item 10.10's lesson — check a budget against
the already-accepted cost it contains before setting it — and **the check refuted its own
premise, which is the point of running it**:

| Subject | CI (run 1) | CI (run 2) |
|---|---|---|
| `benchUlid` | 3.453 µs (±0.68%) | 3.722 µs (±0.79%) |
| `benchUuidV7` | 2.592 µs (±1.34%) | 2.812 µs (±0.76%) |
| `benchRandomToken` | 6.083 µs (±1.08%) | 8.195 µs (±1.45%) |

Both identifiers are **faster** than the draw they were assumed to be built on top of. The scopes
are not nested: `Str::random()` calls `random_int()` once **per character** — ten rejection-sampled
draws — while these call `random_bytes(10)` **once**. The subject measures a different entropy
mechanism, not a smaller amount of the same one, so it is a reference point rather than a floor,
and NFR-15 is derived from the identifiers' own readings alone.

**The ceiling is 10 µs**, covering both subjects (they realize one requirement). Derivation, per
ADR-0058: the worst observed reading across both runs is `benchUlid` at **3.722 µs**, and that
ADR's empirical finding was that every never-fired ceiling in this repository sits at **≥ 2.66×**
its subject's worst reading, with nothing between 1× and 2.66×. 3.722 × 2.66 = 9.90, so 10 µs is
the next round number above the safe band — **2.69×** the ULID reading and 3.56× the UUIDv7 one.

Two runs rather than one, per the standing rule earned at item 10.12, and the second run is why
this ADR does not quote 2.90×: run 1 alone would have put the ceiling at 2.90× a reading 7.8%
lower than the truth. **10 µs therefore sits at the bottom edge of ADR-0058's band, deliberately
and not comfortably** — that is what applying the rule to the honest worst reading produces, and
inflating it further would be a number with no rule behind it.

The spreads are themselves a datum about this runner, on identical code: ULID +7.8%, UUIDv7
+8.5%, `benchRandomToken` **+34.7%**. The outlier is explicable rather than alarming and its
explanation matters for the budget — `Str::random(10)` makes ten `random_int()` calls to the two
subjects' one `random_bytes()`, so it is ten times as exposed to per-call CSPRNG variance. The
budgeted subjects' own ~8% swing is the figure the ceiling must survive, and it does with room.
No new control subject — `RowNormalizerBench::benchInlineTrimHundredRows` already serves this CI
job (ADR-0057).

## Alternatives Considered

1. **Guarantee intra-millisecond monotonicity** (a static last-timestamp/last-entropy pair,
   incremented on collision). Rejected by RFC-0003 and re-affirmed here: it puts mutable global
   state behind a static method — the shape this library refuses everywhere — and buys nothing for
   the stated problem, since index locality is a millisecond-granularity property. A consumer who
   genuinely needs it needs a *stateful generator object* with a lifecycle, which is a different,
   additive class this decision does not preclude.
2. **Assert non-monotonicity behaviourally** (generate N at one instant, assert they are not in
   sorted order). Rejected as flaky by construction: it is a probabilistic assertion, correct with
   probability `1 − 1/N!`, and this project does not ship gates that can fail on a coin toss
   (ADR-0030's whole argument).
3. **Ship only `uuidV7()`** — one format, universally accepted. Rejected: ULID is shorter, has no
   separators, and its alphabet is transcription-safe; a consumer without a UUID-shaped column is
   paying ten characters and a hand-rolled encoder for nothing.
4. **Ship only `ulid()`** — the better format on the merits. Rejected for the reason above,
   mirrored: it cannot go in a `uuid` column or past a UUID validator, and telling consumers to
   change their schema is not this library's business.
5. **Truncate an out-of-range instant into 48 bits** (mask, or clamp). Rejected in §3: it produces
   a well-formed identifier that sorts wrongly — silent corruption of the one property the format
   exists for, in a column that outlives the bug.
6. **A mandatory clock parameter.** Rejected: the overwhelmingly common call is `Str::ulid()` with
   no arguments, and forcing every consumer to construct a `SystemClock` to get one would make the
   seam a tax rather than a service. Optional-with-a-default keeps both audiences.
7. **A separate `Identifier` class or group** rather than two more `Str` statics. Rejected: `Str`
   is where `uuid()` already lives, and a consumer looking for "how does this library make me an
   identifier" looks exactly once. It also keeps the stateless-class assertion of §2 covering the
   whole identifier surface in one place.
8. **RFC 9562's optional sub-millisecond counter in `rand_a`.** Rejected in §2: it is
   monotonicity adopted by the back door, in one of the two formats, against FR-46's ruling.

## Consequences

- Spec **r18** (FR-46, NFR-15); 2 868 tests (+20); **five planted defects, five caught**, each
  verified *landed* by absence of the original before its result was believed.
- `Str` acquires a permanent constraint: **it must stay stateless**, and a test says so with its
  reason. Any future addition needing per-call memory belongs in its own class.
- `Str::ulid()`/`uuidV7()` are additive — ADR-0059's freeze is respected, no existing signature
  moves — and become the recommended primary-key generator wherever `Str::uuid()` was reached for
  out of habit.
- The `Support → Psr` edge ADR-0062 granted now carries its second consumer, as RFC-0003
  predicted it would; deptrac reports 347 allowed / 0 violations / 0 uncovered.
- **What this does not settle**: a stateful, guaranteed-monotonic generator (deliberately left to
  a future additive item if demand appears), ULID *parsing*/decoding (nothing in this library
  consumes an identifier it did not generate), and the UUIDv8 custom layout (no use case).

## References

- Issue [#96](https://github.com/danielPoloWork/egl-util-php/issues/96) · ROADMAP item 14.2 ·
  spec r18 FR-46/NFR-15
- [RFC 9562 §5.7](https://www.rfc-editor.org/rfc/rfc9562#name-uuid-version-7) (UUIDv7's field
  layout, and the optional counter §2 declines) · [ULID specification](https://github.com/ulid/spec)
  (Crockford Base32, the 26-character encoding, and the worked example this suite pins)
- [ADR-0062](0062-the-clock-seam-ships-both-halves-and-support-gains-its-first-outward-edge.md)
  (the injected clock) · [ADR-0027](0027-constant-time-comparison-is-asserted-by-mechanism-not-by-timing.md)
  (assert the mechanism when behaviour cannot see it) ·
  [ADR-0058](0058-an-absolute-ceiling-needs-twice-the-worst-reading-and-catches-accumulation.md)
  (NFR-15's ≥ 2× derivation)
