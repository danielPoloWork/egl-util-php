# ADR-0055: One ordering, validation before filtering, and a swallow only at the leaf

- **Status:** Accepted
- **Date:** 2026-08-08
- **Deciders:** tech-lead (agent-drafted), maintainer (merge)
- **Related:** ROADMAP item **12.3** · spec **FR-41**, **FR-42**, **NFR-14**, **T-12** ·
  [ADR-0029](0029-result-carries-a-throwable-and-production-withholds-the-message-too.md)
  (the leaf logger's deliberate non-escalation) ·
  [ADR-0016](0016-closure-scoped-transactions-with-savepoint-nesting.md) (PHP has no
  suppressed-exception mechanism; losing the cause vs losing the cleanup failure) ·
  [ADR-0015](0015-identifier-allowlist-closed-keywords-and-the-anchor-hole.md) and item 10.5
  (one rule in two places, the newer copy weaker) ·
  [ADR-0008](0008-dto-hydration-strictness-and-shared-hydrator.md) (strict-by-default: an
  unknown key is refused, not ignored) ·
  [ADR-0026](0026-session-hardening-as-a-value-and-csrf-through-a-seam.md) (a policy exposed as
  a value so a wiring can be asserted) ·
  [ADR-0040](0040-install-the-mutation-tester-outside-the-graph-and-keep-nfr07s-own-number.md)
  (the spec owns its own numbers) ·
  [ADR-0045](0045-exclude-io-bound-and-memory-hard-subjects-from-the-relative-gate.md) and
  item 10.12 (a control subject; no claim below the runner's own spread)

## Context

Spec FR-41 and FR-42: a `Level` enum carrying PSR-3's names *with an ordering*, a
`LevelFilteredLogger` that drops records below a floor, a `MultiLogger` that fans one record out
to several loggers, and a `LoggerFactory` that turns one configuration array into a named set of
channels — PSR-3 throughout, with no Monolog dependency (NFR-08).

The estate this replaces had eight logger properties per service class, each pairing a
destination with a level and an enabled flag, re-created in every constructor: 160 factory calls
across twenty classes, and a channel's floor changeable only by editing every class that named
it. That is the shape being deleted. What the shape *hid* is the more interesting part, and it is
what the decisions below are about: with eight destinations and eight flags, nothing ever said
which records were being dropped, whether a level was even spelled correctly, or which of the
eight destinations had silently stopped accepting writes.

Four decisions were not derivable from the requirement text.

## Decision

### D1 — The ordering exists once, in `Level`, and the enum's cases are PSR-3's own constants

PSR-3 defines eight level *names* and no order; every filtering decision needs the order. Before
this item, `Logger` carried a private severity map of its own — correct while nothing else needed
it, and one copy away from the failure this project has already paid for: ADR-0015's identifier
allowlist lived in two builders until item 10.5 found that the newer copy was the weaker one, with
both suites green. So `Level` owns the ordering, and `Logger` now reads it instead of its own map.

The cases were first backed by `LogLevel::*` rather than by literals, so that the enum and PSR-3
could not disagree at all:

```php
case Emergency = LogLevel::EMERGENCY;   // rejected by PHP 8.1
```

**PHP 8.1 refuses this** — *"Enum case value must be compile-time evaluatable"* — while 8.2 and 8.3
accept it. So the compile-time guarantee is simply unavailable on this library's floor, and the cases
are literals with `LevelTest` asserting the two sets equal in both directions instead: PSR-3 adding a
level, or this enum inventing one, fails a test rather than a consumer. The `RANK` map below still
references `LogLevel::*`, because an ordinary class constant is evaluated on first access rather than
at compile time — which is exactly the distinction the 8.1 restriction turns on.

**How this was found is the part worth carrying.** The probe that pronounced the constant-backed
version legal ran on **PHP 8.3**, the only runtime on this development machine, and the ADR's first
draft said "verified legal on the 8.1 floor" — a claim the probe had not made. The 8.1 CI cell
rejected the file in 16 seconds. This is the same failure class as item 10.10's attribution and item
10.11's µs figures, both taken on the wrong machine: **a probe inherits the runtime it ran on, and a
compatibility claim about a floor can only be made by something that runs on that floor.** The
matrix's 8.1 cell exists for this, and it worked.

**`rank()` reads a const map instead of running a `match`, and that is measured.** With OPcache
off — which is how NFR-06 pins every benchmark — `match ($this)` over the eight cases cost
**0.564 µs** against **0.246 µs** for a map lookup through the backing value, ~2.3× in the same
run on the same box. NFR-14 budgets an entire suppressed record at 0.5 µs, so the difference is
most of the budget. `Level::rankOf()` exists for the same reason: `AbstractLogger::debug()` hands
`log()` a *string*, so hydrating a case per record would pay `tryFrom()` (~0.147 µs) for a value
only an integer is wanted from. Measured end to end through a real decorator, the enum-hydrating
shape cost **1.089 µs** — 218% of the budget — against **0.435 µs** for the rank-comparing shape.

### D2 — An unknown level throws even when the floor would have dropped it

PSR-3 requires an unknown level to throw and says nothing about filtering, which leaves the order
open. Filtering first is the cheaper-looking option and makes a typo'd level behave as a
*function of the floor*: silently discarded under a high floor, fatal under a low one. The bug
then surfaces the moment someone raises verbosity — during the incident that made them raise it.

So validation goes first, in `LevelFilteredLogger`, in `MultiLogger` and in `Logger`. It is free:
`Level::rankOf()` answers "is this a level" and "how severe" in one array lookup, and the same
call site does both. This is ADR-0008's strict-by-default stance one layer out — an unknown key is
refused rather than ignored.

### D3 — The composite validates once, attempts every delegate, and re-throws the first failure

`MultiLogger` validates the level **before** the fan-out, because validating inside the loop
would let the first two destinations receive a record and the third reject it, leaving the same
record present in some logs and absent from others with nothing explaining why.

A delegate that throws is then handled by the opposite rule to the one `Logger` uses on itself.
`Logger` deliberately does not escalate its own write failures (ADR-0029): a logger that throws
while an exception handler is using it turns a handled failure into a fatal one. The composite
does escalate — after attempting every delegate — and the boundary is *ownership of a
destination*:

- a **leaf** owns a destination, can describe the failure in its own terms, and must not turn a
  logging problem into an application problem;
- a **composite** owns none, so swallowing would make a fan-out where every delegate failed
  indistinguishable from one that worked.

In the normal wiring nothing is thrown at all, because the leaves already refuse to escalate —
verified: a `Logger` whose file became unwritable *after* construction returns silently rather
than throwing, and `MultiLoggerTest` performs that composition rather than asserting it in prose.
What remains is the third-party case, where hiding the failure would be this library deciding, on
someone else's behalf, that a lost log is not worth mentioning.

Only the **first** failure survives: PHP has no suppressed-exception mechanism, the same
constraint ADR-0016 recorded when a failing rollback had to lose either its own error or the
original cause. The later failures are lost. That is stated in the docblock and pinned by a test
asserting the second failure is *not* chained, rather than left for a reader to discover.

### D4 — A disabled channel is still built and still validated; the empty fan-out is a readability choice, not a safety one

**The half of this decision that survived contact with a planted defect, and the half that did
not.** PSR-3 ships `NullLogger`, the obvious implementation of `enabled: false`. Probed, it
**accepts an invalid level without complaint**, and the first draft of this ADR concluded that an
empty `MultiLogger` — which validates and discards — was therefore what kept a disabled channel
from hiding a bad level.

That reasoning is **wrong for the factory**, and the planted-defect campaign is what showed it:
substituting `NullLogger` for the empty composite left the suite **green**. The channel is a
`LevelFilteredLogger` wrapping the sink, and the filter validates *before* the sink is reached, so
the sink's own strictness is unobservable through the factory's surface. The guarantee consumers
actually have — a disabled channel refuses an unknown level — holds either way, and holds because
of D2.

So the empty fan-out is kept on a smaller, honest claim: one sink type in both branches, differing
only in breadth, reads more plainly than a branch that changes the class. `MultiLogger`'s own
validation remains a real property of *that* class, asserted directly against it, where it is
observable. This follows ADR-0022's precedent for defensive code a probe proves inert — except that
here the code stays and the *justification* is what was removed.

The rest of the decision does survive: a disabled channel's destinations are still constructed, which
is what checks writability (no file is created, no record written), and the constructed logger is
then deliberately discarded. Otherwise `enabled: false` would hide a bad path until the day
somebody switched the channel on — at which point the failure arrives during a deployment,
attributed to the flag rather than to the path.

Config keys are closed: an unknown setting is refused, not ignored, because a logging
configuration is exactly where a silently-dropped `levels:` typo is worst — nothing fails, and the
records are simply not there when someone needs them. A non-boolean `enabled` is refused for a
sharper reason: the string `'false'` is *truthy*, so accepting it would leave a channel the
operator believes is off and which is writing every record.

## Alternatives Considered

1. **Keep `Logger`'s own severity map and give the decorator a second one** — rejected: two copies
   of one rule, which is item 10.5's finding restated. The map moved to `Level` instead, and
   `Logger`'s constructor widened to `Level|string` (additive; every existing
   `new Logger($path, 'warning')` still compiles).
2. **`match ($this)` for the rank**, the idiomatic enum shape — rejected on measurement (D1): 2.3×
   the cost of a map lookup with OPcache off, against a 0.5 µs total budget. Recorded so a future
   tidy-up does not "simplify" it back and hand the budget away.
3. **Filter before validating** — rejected (D2): it makes a typo's visibility depend on the floor.
4. **Swallow a delegate's failure in `MultiLogger`**, symmetrical with `Logger` — rejected (D3):
   the composite owns no destination, so it has nowhere to put the failure and nothing to lose by
   reporting it. The asymmetry is deliberate and its boundary is stated.
5. **Collect every delegate failure into one aggregate exception** — rejected: an aggregate type
   would be a new public exception shape for a case that cannot occur over this library's own
   loggers, and consumers catching `RuntimeException` from their own logger would stop catching it.
   The trade — later failures lost — is documented and tested instead.
6. **`NullLogger` for a disabled channel** — **not rejected on the grounds first written.** A probe
   showed it accepts invalid levels, which looked decisive until the substitution was planted and
   the suite stayed green: the filter above the sink validates first, so the two are
   indistinguishable through the factory. Kept as an empty `MultiLogger` for uniformity of type, and
   the stronger claim withdrawn (D4).
7. **Lazy channel construction** — rejected: `Logger` refuses an unwritable destination at
   construction precisely so the failure lands where the misconfiguration is (ADR-0029), and the
   first record is typically emitted while something else is already going wrong.
8. **Let each `Logger` apply the channel's floor, skipping the decorator for single-destination
   channels** — rejected: it would give a channel two places where "below the floor" is decided,
   only one of which is visible where the channel is configured. Every channel gets the same shape,
   which is also the shape NFR-14 measures.
9. **Back the enum cases with `LogLevel::` constants** — *chosen first, then withdrawn under
   compulsion*: PHP 8.1 rejects it (D1). Recorded as an alternative rather than deleted, so the next
   person to have the same good idea finds out from this file instead of from a red 8.1 cell.
10. **An in-class `Bench\Assert` for NFR-14** — rejected: `tools/bench_budget_gate.py` already owns
   the absolute ceilings in CI and nightly, prints the measured value, and fails on an absent
   report. A second home for the number is the drift D1 exists to avoid.

## Consequences

**Easier:** one injected `LoggerInterface` per class instead of eight properties; a channel's floor
and destinations are data, changed in one array; a third-party logger gets this library's floor
semantics by wrapping; a wiring test can assert a channel's floor (`floor()`) and a fan-out's
breadth (`count()`) without emitting a record and reading a file.

**Harder / accepted costs:** `MultiLogger` can throw, which `Logger` cannot — the boundary is
documented but it *is* an asymmetry a reader must learn. Later delegate failures are lost. A
disabled channel still validates its destinations, so `enabled: false` is not a way to defer a
path problem. The level vocabulary is spelled twice (the enum's literals and `RANK`'s
`LogLevel::` keys), held together by a test rather than by the compiler — the 8.1 floor's price.

**NFR-14, measured on CI** (the reference runner, not this development box, which overstated the
subject by ~5×):

| subject | CI | note |
|---|---|---|
| `benchSuppressedRecord` | **0.081 µs** (±1.69%) | against the 0.5 µs ceiling — 6.2× headroom |
| `benchFanOutSuppressed` | 0.081 µs (±1.57%) | identical: the filter returns before the composite is touched |
| `benchSinkDirectly` (control) | 0.046 µs (±2.63%) | **57% of the subject** |
| `benchPassedRecord` | 0.090 µs | a passing record adds ~0.009 µs before a destination is involved |

The control reproduces on CI what was seen locally: **most of what NFR-14 bounds is PHP's own method
dispatch**, not this library's filtering. The number is still the right one to gate — it is what a
consumer pays — but a future breach should be read as "the dispatch or the runner moved" before "the
filter got slower".

**Patterns:** `LevelFilteredLogger` is a **Decorator** and `MultiLogger` a **Composite** — both
catalogued with this ADR (`docs/patterns/README.md` rows 3 and 4). Both were already in the
taxonomy, unlike the two Fowler enterprise patterns items 10.4 and 11.2 had to add.

**No spec amendment.** FR-41, FR-42, T-12 and NFR-14 were implementable exactly as written — worth
stating, because four of the last six items needed the spec corrected in the same PR (FR-35's
SELECT-only builder at 10.4, FR-37's unbounded timeout at 11.1, NFR-09's unsatisfiable ratio at
10.10, T-07's tag collision at 11.4).

## References

- PSR-3 (`psr/log` 3.0.2): `LoggerInterface`, `LogLevel`, `AbstractLogger`, `NullLogger`
- RFC 5424 §6.2.1 — the severity ordering PSR-3's names come from
- Item 12.3's probes (severity of `match` vs a const map; `NullLogger`'s acceptance of an unknown
  level; a leaf `Logger`'s silence on a write failure) — recorded in the journal entry for
  2026-08-08
