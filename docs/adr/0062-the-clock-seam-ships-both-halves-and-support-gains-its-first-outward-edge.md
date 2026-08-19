# ADR-0062: The clock seam ships both halves, and Support gains its first outward edge

- **Status:** Accepted
- **Date:** 2026-08-13
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item **14.1** · issue [#97](https://github.com/danielPoloWork/egl-util-php/issues/97) ·
  spec **r17 FR-45** · [RFC-0003](../rfc/0003-post-1-0-functional-scope.md) (the design this
  realizes) · [ADR-0061](0061-a-token-bucket-behind-a-compare-and-swap-store-and-keys-hashed-at-the-boundary.md)
  §5 (the first consumer's skew rule, which fixes this seam's test-double semantics) ·
  [ADR-0028](0028-container-exceptions-live-in-the-container-group-and-get-carries-a-type.md)
  (the "Support depends on nothing" principle this narrows) · [PSR-20](https://www.php-fig.org/psr/psr-20/) ·
  RFC-0001 R-3 / spec NFR-08 (the interface-only carve-out `psr/clock` rides)

## Context

`src/main` contained no time abstraction — verified by grep during the review board's pass and
again at RFC-0003, which made the clock its keystone item: FR-49's retry deadlines, FR-48's token
expiry and FR-46's sortable identifiers all consume time, and each would otherwise read it
privately. RFC-0003 decided the *what* (PSR-20, both implementations, in `Support`); this ADR
records the implementation decisions the RFC left open, and one consequence the RFC did not
notice it had already made.

That consequence: `deptrac.yaml` carried the comment *"Support depends on nothing, and that must
not change to accommodate one class (ADR-0028)"* — written when PSR-11's exception interfaces
were kept **out** of `Support` precisely to preserve that property. FR-45 places classes
implementing `Psr\Clock\ClockInterface` **in** `Support`, which makes `Support → Psr` an edge for
the first time in sixty ADRs.

## Decision

### 1. Both halves ship in `src/main`, and the test double is deliberately mutable

`SystemClock` (production) and `FrozenClock` (the double) both land in `Support`. Shipping the
double is the seam's value proposition — nobody re-implements it per project, the same reason
`RecordingMailer` exists for transport, except consumers get this one too.

`FrozenClock` is the one class in this library whose job is **controlled mutation**:
`advance(DateInterval)` moves the held instant while the code under test keeps the same injected
reference. An immutable `withAdvanced()` would return a clock nothing under test holds — the
mutation *is* the mechanism. It honours **inverted** intervals: time moving backward is a
first-class scenario, because ADR-0061 §5's clock-skew tests simulate a node whose clock runs
behind the state it reads by doing exactly that. `advance()` is cumulative, and the suite pins
all three properties (frozen-between-reads, cumulative, backward) with planted defects proving
each pin can fail.

### 2. Timezones are objects, optional, and default to PHP's default

`SystemClock`'s constructor takes `?DateTimeZone = null`. Three consequences, each chosen:

- **Construction cannot fail.** A `DateTimeZone` object is already valid — an invalid zone never
  becomes one. Taking a string would have bought a validation path and a failure mode for
  nothing; the type is the enforcement, `SqlStatement`'s stance applied to time.
- **`null` means PHP's default timezone**, byte-for-byte what `new DateTimeImmutable('now')`
  does — the seam changes *where* time is read, never *what* is read. A library that silently
  pinned UTC would return instants formatted differently from every `new DateTimeImmutable()`
  the surrounding application writes: surprise without benefit.
- The benefit UTC-pinning chases is already free: **instant arithmetic is timezone-independent**,
  and arithmetic is every in-library use (deadlines, expiry, refill). The timezone matters only
  when a caller formats the value, and the caller who cares injects one explicitly.

### 3. `Support → Psr` is granted by name, and the old blanket claim is retired honestly

The deptrac `Psr` layer's collector widens to `^Psr\\(Log|Container|Clock)\\.*` and `Support`
gains the grant. This is **not** the move ADR-0028 forbade, and the difference is worth stating
precisely: ADR-0028 refused to *relocate* classes into `Support` so that a grant could dodge its
rightful home. Nothing is relocated here — RFC-0003 placed FR-45 in `Support` on its merits, and
placed two of its consumers (FR-46's `Str` additions, FR-49's `RetryPolicy`) there too, so the
edge exists under the accepted design **no matter where the clocks live**. The alternative
placements are all worse (Alternatives 5–6). ADR-0028's operative rule survives unchanged; the
blanket sentence "Support depends on nothing" was true for sixty ADRs and is retired in the
config comment with a pointer here, rather than left to contradict the ruleset below it.

The grant is proven to discriminate, not just granted: with the edge live (`Support → Psr`,
deptrac reporting **344 allowed / 0 violations / 0 uncovered**, up from 342), a planted
`Support → Dto` property type was refused by name (`FrozenClock must not depend on
D4np\Utils\Dto\Collection`) and removed. Direction is preserved: nothing in `Support` reaches any
sibling group; `Support` reaches one interface-only vendor layer, the exact carve-out RFC-0001
R-3 wrote for `psr/container` and `psr/log`, now carrying its third package.

### 4. No NFR budget, no new exception, no named suite

- **No budget**: RFC-0003's reasoning stands — NFR-14's control subject measured 57% of its own
  subject, so a ceiling on one `DateTimeImmutable` allocation would bound PHP's method dispatch
  and assert nothing about this library (ADR-0040: the spec owns its numbers, and declines this
  one).
- **No exception type**: construction cannot fail (§2), `now()` cannot fail, `advance()` cannot
  fail. `ExceptionHierarchyTest`'s pinned lists are untouched — stated because an absence leaves
  no trace.
- **No named T-suite**: the spec's suite vocabulary marks distinct verification *techniques*
  (injection corpora, live origins, multi-process races); these are plain unit tests, and
  claiming a T-number would dilute what the vocabulary marks.

## Alternatives Considered

1. **UTC always** — rejected in §2: silently different from every `new DateTimeImmutable('now')`
   the consuming application writes, and the portability it chases (instant comparison) is
   already timezone-independent. Surprise without benefit.
2. **Timezone as a string parameter** — rejected: buys a validation path, an exception type and a
   failure mode for a class whose whole point is that it cannot fail; the object parameter makes
   the invalid state unrepresentable instead of checked.
3. **An immutable `FrozenClock`** (`withAdvanced()` returning a new instance) — rejected: the
   injected reference is the mechanism. The code under test holds one clock; a test that must
   re-inject after every advance is a test that cannot simulate time passing mid-operation.
4. **Not shipping `FrozenClock`** (consumers write their own double) — rejected: the RFC counted
   the shipped double as the seam's value, and a hand-rolled frozen clock is exactly the
   re-implemented-per-project class this library exists to remove.
5. **A dedicated `Clock` group** instead of `Support` — rejected: two classes do not carry a
   layer, and every group that consumes time would then need a `→ Clock` grant — N new edges to
   avoid one.
6. **Hosting the clocks in `Errors`** (beside the other PSR implementer, the PSR-3 logger) —
   rejected: time is not an error concern, and `Support`-resident consumers (FR-46, FR-49) would
   need **upward** edges into a sibling group — the direction this file exists to forbid.
7. **A monotonic-clock feature** (`hrtime`-backed) — out of scope: PSR-20 is wall-clock by
   contract, ADR-0061 deliberately does not assume monotonicity across nodes, and the benchmarks
   that need `hrtime` already use it directly without a seam.

## Consequences

- `psr/clock ^1.0` in `require` (lock updated; `composer.json` stays normalized). The install
  footprint grows by one interface-only package — the third, after `psr/container` and `psr/log`.
- `Support` has an outward edge for the first time; the deptrac comment that claimed otherwise is
  rewritten to the narrower rule that was always the operative one, with a pointer here.
- Items **14.2, 14.4, 14.5 and 14.7 are unblocked** — every remaining M14 unit consumes this
  seam, which is why 14.1 shipped first and alone.
- Spec **r17** (FR-45); 2 848 tests (+17); five planted test defects and one planted deptrac
  violation, six for six caught, each verified landed by absence-of-the-original before its
  result was believed.
- What this does not settle: nothing. The item is closed whole; the seam's first consumers arrive
  with their own items.

## References

- Issue [#97](https://github.com/danielPoloWork/egl-util-php/issues/97) · ROADMAP item 14.1 ·
  spec r17 FR-45
- [RFC-0003](../rfc/0003-post-1-0-functional-scope.md) § New components (FR-45) and § Context
  (the keystone argument: three private clocks is the same mistake three times)
- [ADR-0061](0061-a-token-bucket-behind-a-compare-and-swap-store-and-keys-hashed-at-the-boundary.md) §5
  — the skew clamp whose tests fix `advance()`'s backward semantics
- [ADR-0028](0028-container-exceptions-live-in-the-container-group-and-get-carries-a-type.md) —
  the principle §3 narrows rather than repeals
- [PSR-20: Clock](https://www.php-fig.org/psr/psr-20/) · `psr/clock` 1.0.0
