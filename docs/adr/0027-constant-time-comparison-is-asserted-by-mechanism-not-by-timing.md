# ADR-0027: Constant-time comparison is asserted by mechanism, not by timing — and the spec says so now

- **Status:** Accepted
- **Date:** 2026-08-05
- **Deciders:** maintainer (`@danielPoloWork`, who authorised the spec amendment), agent acting as tech-lead
- **Related:** ROADMAP item 6.3 · spec [§6/T-03 revision **r2**](../specs/01_spec_utils.md#revision-history)
  (amended by this decision) · [ADR-0026 §7](0026-session-hardening-as-a-value-and-csrf-through-a-seam.md)
  (the mechanism assertion this generalises) · [ADR-0026 §8](0026-session-hardening-as-a-value-and-csrf-through-a-seam.md)
  (the same shape, found by the coverage gate) · item **4.6** (where "assert the mechanism" started)

## Context

Spec §6 required, for T-03, a **`hash_equals` timing test**. Implementing T-03 meant implementing
that, and ADR-0026 §7 had already rejected a timing assertion — but rejected it by *reasoning*, on
the grounds that PHP-level timing is "too noisy to be anything but flaky". Reasoning is not
measurement, and a spec requirement should not be set aside on an argument when it can be settled
with numbers.

So it was measured first.

A timing test distinguishes `hash_equals()` from `===` by exploiting `===`'s short-circuit on the
first differing byte: an early difference should be measurably faster than a late one. On
64-character tokens, 2,000,000 iterations × 5 rounds, PHP 8.3.1, idle machine:

| scenario | median ns/op | within-scenario spread |
|---|---|---|
| `===`, differing at byte 0 | 101.517 | 2.63 ns |
| `===`, differing at byte 63 | 104.352 | 2.12 ns |
| `hash_equals()`, differing at byte 0 | 232.103 | 38.22 ns |
| `hash_equals()`, differing at byte 63 | 227.929 | 29.85 ns |

- The gradient the test depends on — `===` late minus early — is **+2.8 ns/op**.
- Worst within-scenario noise is **38 ns/op**. The signal is roughly **13× below the noise floor**.
- `hash_equals()`'s own gradient is **−4.2 ns/op**: noise with a sign on it.
- T-03 runs over HTTP, where 2.8 ns sits **six orders of magnitude** below request latency. CI
  runners on shared vCPUs are noisier than the machine these numbers came from.

The requirement was not merely hard. It was asking for a measurement of something that is not
measurable at this layer.

## Decision

### 1. The spec is amended; T-03 asserts a mechanism

Spec §6/T-03 revision **r2** replaces the timing test with a mechanism assertion, stated in both
directions: **positively**, that `hash_equals()` is the comparator on every secret-comparison path,
and **negatively**, that `==`, `===`, `strcmp()`, `strncmp()` and equivalents are absent from those
paths.

Amending the spec rather than recording a deviation is deliberate. A deviation is a standing
disagreement between the contract and the code that someone must keep re-reading and re-deciding;
there is nothing here to keep deciding, so the contract is corrected and closed.

### 2. The scoping argument, which is the real reason

Whether `hash_equals()` is itself constant-time is **PHP's** contract, verified in PHP's own test
suite. Re-deriving it here would be testing someone else's implementation through a strictly worse
instrument.

The property that exists at *this* layer is **which comparator this code invokes**. That is not a
weaker substitute for the timing question — it is the whole of the question this codebase can
actually get wrong, and unlike the timing it is decidable *exactly*, from the source, with no
measurement and no tolerance.

### 3. `password_verify()` counts as constant-time, and `hash_equals()` is not required everywhere

`Hash::verify()` uses `password_verify()`, which re-derives the hash and compares in constant time.
Demanding the literal name `hash_equals` on every path would be cargo-culting a function rather than
the property. The registry records the *correct* comparator per path.

### 4. The registry guards its own completeness

A new secret comparison added later, and not registered, would leave itself unasserted while the
suite stayed green — the exact failure mode ADR-0026 §8 was written about. So the test scans every
library file for calls to a constant-time comparator and fails on any that falls outside a
registered path. Comments are stripped with `token_get_all()` first, because the classes involved
discuss `hash_equals()` at length in their docblocks and a text search would match the prose.

## Alternatives Considered

- **Assert that `hash_equals()` is measurably *slower* than `===`** (~230 vs ~101 ns/op, a 2.3× gap
  comfortably clear of noise) — rejected: it asserts an implementation artifact with an **inverted
  failure profile**, going red on a legitimate PHP optimisation and staying green on a slow but
  non-constant-time comparator.
- **A full statistical, dudect-style test** — rejected: the right technique at the wrong abstraction
  layer. At this signal-to-noise ratio its discriminative power is zero, so it would be either flaky
  or tuned until it caught nothing.
- **Keep the spec text and record a standing deviation** (the first option put to the maintainer) —
  rejected by the maintainer in favour of amending: a deviation is a disagreement someone has to
  keep re-reading, and this one has no open question left in it.
- **Leave the assertion in `CsrfTokenTest` and add a second copy for `Hash`** — rejected: two files
  asserting one property is how they drift. The assertion moved to a dedicated class and
  `CsrfTokenTest` keeps a pointer.

## Consequences

- Spec `01_spec_utils.md` gains a **Revision history** and moves to **r2**. The measurement lives in
  the spec's own rationale as well as here, so the requirement cannot be re-opened on intuition
  without meeting the numbers.
- 5 tests in `ConstantTimeComparisonTest`, covering 2 registered paths in both directions plus
  completeness. **Verified non-vacuous**: `hash_equals` → `===` in `CsrfToken::validate()` fails 2;
  an unregistered `hash_equals()` planted in `Session` fails the completeness guard, naming the file
  and line.
- `CsrfTokenTest::testValidationComparesInConstantTime()` is removed, subsumed rather than weakened.
- **No standing deviation to track.** T-03 ships with its behavioural suite and this assertion.

## References

- Spec §6/T-03 r2 and its rationale table — the same measurements, at the contract level
- ADR-0026 §7 — the original mechanism assertion, reasoned rather than measured; this ADR supplies
  the measurement it lacked and generalises the assertion
- Item 4.6 — the first time a property was asserted by mechanism because it sat under the noise floor
- Measured directly on PHP 8.3.1, 2,000,000 iterations × 5 rounds, idle Windows developer machine
