# Design Patterns Catalogue

Living index of every design pattern **adopted**, **planned**, **considered and rejected**,
or **under evaluation** for `egl-util-php`. Mandatory reading whenever a PR introduces
or removes a pattern, and updated in the same PR.

- **Rules** — [`AGENTS.md`](../../AGENTS.md) §8.
- **Canonical taxonomy** — [`design-patterns.md`](design-patterns.md). All pattern names
  used here, in ADRs, and in commit messages must match its spelling and categorisation.
- **Third-party picks** — [`third-party-picks.md`](third-party-picks.md). Not a pattern this
  codebase implements; endorsed libraries for needs it deliberately doesn't cover, plus the
  explicit do-not-add list.

## Architecture style

**Committed style:** Layered — from [`design-patterns.md`](design-patterns.md) §5.
**Pattern discipline:** `advisory` — `advisory` means the agent advises and the human
decides; `enforced` makes conformance to the committed style + adopted patterns a review expectation.


## How to use this catalogue

- **Adding a pattern** — when a PR lands one, add a row to *Implemented / Planned* as
  `Implemented`, with the ADR link and the code location (a real path under
  `src/main/php/...`); a pattern decided in an ADR but not yet in code is added as `Planned`.
- **Refining** — update the row and link the new ADR.
- **Rejecting** — add it to *Rejected* with the reason; do not silently drop it.
- **Removing** — move the row to *Superseded*, link the superseding ADR, keep the history.

Status vocabulary: `Planned` (decided in an ADR, not yet landed) · `Implemented` (present
in `src/main/...`, ADR `Accepted`) · `Considered` · `Rejected` · `Superseded`.

## Implemented / Planned

_Patterns named in the spec at intake are seeded below as **Planned**; each becomes
**Implemented** with its ADR and a real code location in the PR that introduces it._

| # | Pattern | Status | Problem it addresses | Code location | ADR / PR |
|---|---------|--------|----------------------|---------------|----------|
| 1 | Table Data Gateway | Implemented | Per-entity CRUD boilerplate: the surveyed estate wrote a `Dao` + `Query` + `CrudImpl` triple per table, each re-deriving how to build safe SQL and each disagreeing on what a failure returns | [`Persistence/TableGateway.php`](../../src/main/php/d4np/utils/Persistence/TableGateway.php) | [ADR-0044](../adr/0044-the-write-builder-querybuilder-never-had-and-one-allowlist-for-both.md) · item 10.4 |
| 2 | Front Controller | Implemented | Routing by filesystem: the surveyed estate deployed **37 folders**, each with an `index.php` differing from its neighbours in one line, so the autoloader, the response envelope and the error boundary existed 37 times and drifted apart. One entry point and one route table replace them — the kernel is written out in [`endpoint-kernel.md`](endpoint-kernel.md) | [`Http/Router.php`](../../src/main/php/d4np/utils/Http/Router.php) | [ADR-0050](../adr/0050-classify-the-miss-and-keep-the-router-a-table.md) · item 11.2 |
| 3 | Decorator | Implemented | Level filtering that applies to **any** PSR-3 logger, including a third-party one: the estate instead built one logger per level — eight properties per class, re-created in every constructor — so "what is being dropped" was a property of twenty classes rather than of one wiring. Wrapping keeps the filter out of every destination that would otherwise re-implement it | [`Errors/LevelFilteredLogger.php`](../../src/main/php/d4np/utils/Errors/LevelFilteredLogger.php) | [ADR-0055](../adr/0055-one-ordering-validation-before-filtering-and-a-swallow-only-at-the-leaf.md) · item 12.3 |
| 4 | Composite | Implemented | One record, several destinations, with nothing downstream of the wiring aware there is more than one — and the uniformity is load-bearing twice over: the **empty** composite is the disabled channel (it validates a level and discards the record, which PSR-3's own `NullLogger` does not), and the filter above it cannot tell one destination from ten | [`Errors/MultiLogger.php`](../../src/main/php/d4np/utils/Errors/MultiLogger.php) | [ADR-0055](../adr/0055-one-ordering-validation-before-filtering-and-a-swallow-only-at-the-leaf.md) · item 12.3 |
| 5 | Retry with Backoff | Implemented | Transient failures retried by hand, wrongly in one of three ways the 2026-08-09 review board named: **no jitter**, so N clients that failed together retry together and the retry storm is the outage; **retrying non-retryable failures**, because a `400` will not become a `200`; and **unbounded total time**, since an attempt count bounds no loop when an attempt can hang. An explicit policy value object makes each of the three a decision somebody wrote down, and the jitter has no switch to turn it off | [`Support/RetryPolicy.php`](../../src/main/php/d4np/utils/Support/RetryPolicy.php) · [`Support/Retrier.php`](../../src/main/php/d4np/utils/Support/Retrier.php) | [ADR-0066](../adr/0066-a-second-seam-for-waiting-and-a-deadline-that-only-bounds-the-loop.md) · item 14.5 |
| 6 | Rate Limiting / Throttling | Implemented | Hand-rolled throttles that are *"usually bypassable (per-node state, resettable windows)"* — issue #91's own words. A token bucket has no window edge to straddle, so the "resettable windows" defect (the fixed-window boundary burst, 2× the intended rate) cannot occur; and the store seam is **compare-and-swap**, because a `get()`/`set()` store cannot be composed race-free by any caller — two nodes read one remaining token, both approve, and the limit is exceeded by the limiter at exactly the concurrency a brute-force attack produces. Keys are hashed at the limiter's boundary, so no user-controlled byte reaches a store | [`Security/RateLimiter.php`](../../src/main/php/d4np/utils/Security/RateLimiter.php) · [`Security/RateLimitStore.php`](../../src/main/php/d4np/utils/Security/RateLimitStore.php) | [ADR-0061](../adr/0061-a-token-bucket-behind-a-compare-and-swap-store-and-keys-hashed-at-the-boundary.md) (the design) · [ADR-0067](../adr/0067-the-bucket-refills-in-whole-tokens-and-the-store-contract-is-tested-twice.md) (the implementation) · items 14.6 / 14.7 |


## Rejected

_Recorded rather than silently dropped (`AGENTS.md` §8.3). Each was a live candidate for the
`Persistence` group at items 10.3–10.4._

| # | Pattern | Considered for | Rejected because | ADR / PR |
|---|---------|----------------|------------------|----------|
| 1 | Active Record | The row shape returned by the gateway | It puts persistence on the DTO, and the `Dto` group's whole contract is that a DTO is immutable data with no collaborators. It would also invert the layering rule — `Dto` would need `Database` — which is the direction [ADR-0043](../adr/0043-two-named-edges-out-of-persistence-and-no-catch-at-all.md) keeps closed and proves closed. | [ADR-0044](../adr/0044-the-write-builder-querybuilder-never-had-and-one-allowlist-for-both.md) · item 10.4 |
| 2 | Unit of Work | Coordinating multi-statement writes | Change tracking and an identity map are ORM scope, rejected wholesale by RFC-0002 Alternative #1. Transaction scope — the part consumers actually need — is already `Transaction`/`Repository::withTransaction()` under [ADR-0016](../adr/0016-closure-scoped-transactions-with-savepoint-nesting.md)'s semantics. | [ADR-0044](../adr/0044-the-write-builder-querybuilder-never-had-and-one-allowlist-for-both.md) · item 10.4 |
| 3 | Intercepting Filter / middleware pipeline | Cross-cutting work around a routed handler | The pipeline shape everyone reaches for is PSR-15, which is defined in PSR-7 messages — and the bridge is this library's only sanctioned crossing into PSR-7 (RFC-0001 Alternative #3). Adopting it would put a PSR-7 dependency behind `Router`, which the whole HTTP stance exists to avoid. The endpoint kernel does the same work in straight-line code a reader can follow. | [ADR-0050](../adr/0050-classify-the-miss-and-keep-the-router-a-table.md) · item 11.2 |
| 4 | Data Mapper | Mapping rows to DTOs | The full pattern's value is mapping *independent* object and table models, which needs metadata, a registry and change tracking. This library maps a flat row to a readonly DTO by constructor name, which the shared hydrator already does ([ADR-0008](../adr/0008-dto-hydration-strictness-and-shared-hydrator.md)); adopting the name would promise a machinery that is deliberately absent. | [ADR-0044](../adr/0044-the-write-builder-querybuilder-never-had-and-one-allowlist-for-both.md) · item 10.4 |

## Superseded

_No superseded patterns yet._

| # | Pattern | Superseded by | When | ADR / PR |
|---|---------|---------------|------|----------|
| — | —       | —             | —    | —        |

## Candidate patterns to consider

Narrowed from the full taxonomy to what is plausibly applicable and not yet decided one way or
the other. A candidate remains a candidate until adopted (own ADR, moves to *Implemented*) or
explicitly rejected (moves to *Rejected*).

- **Cloud & Distributed Systems — Rate Limiting / Throttling — adopted, now row 6 above.**
  Kept as a pointer rather than deleted, because the *reason* this was a bullet outlived it: the
  status vocabulary defines *Planned* (decided in an ADR, not yet in code), but
  `consistency_lint.py`'s patterns check requires a real source location for every table row — so
  between ADR-0061 (2026-08-13) and item 14.7 (2026-08-21) this entry had nowhere legal to live.
  **That disagreement is now moot for this entry and still unresolved in general**: either the lint
  learns the Planned case or the vocabulary drops it, and that call is the maintainer's (same class
  as ADR-0040's spec-owns-its-numbers rule). The next decided-but-unbuilt pattern will hit it again.
- **Cloud & Distributed Systems — Retry with Backoff — adopted, now row 5 above** (item 14.5,
  ADR-0066). Recorded late: that item shipped reporting "no catalogue entry", while this bullet's
  own promotion rule said it moves to *Implemented* when the item lands. Corrected at 14.7.
- **Cloud & Distributed Systems — Circuit Breaker.** Possible application: guarding `HttpClient`
  or a future retry policy against a dependency that is failing outright rather than transiently.
  **Named a stated non-goal** of item 14.5's own acceptance criteria ("no circuit breaker in v1 of
  the feature") — recorded here rather than in *Rejected*, since that table's rows carry an ADR and
  none exists yet for a decision made inside an issue.

## Out-of-scope categories

Whole taxonomy categories pre-classified as not applicable, so the policy of explicit rejection
is honoured without filling the *Rejected* table with a pattern-by-pattern N/A for a category
this artifact structurally cannot host.

- **Concurrency** (Monitor Object, Thread Pool, Producer-Consumer, Read-Write Lock, Future/Promise,
  Lock-Free/Wait-Free, Thread-Local Storage, …). PHP's standard execution model is share-nothing
  per request: this library owns no persistent process, no thread, and no shared memory to
  coordinate across one. `Immutable Object` and `Guarded Suspension` are the two entries in this
  category with a real single-request analogue, and both already exist under other names —
  immutability is the DTO group's whole contract (ADR-0008), and `Repository::withTransaction()`
  is this codebase's guarded-precondition seam (ADR-0016). Nothing in the category needs its own
  adoption.
- **Enterprise Integration Patterns** (Message Channel, Message Router, Publish-Subscribe,
  Competing Consumers, Dead Letter Channel, …). This library owns no messaging infrastructure —
  no queue, no broker, no publish/subscribe mechanism anywhere in `src/main`. `Mail` sends
  synchronously to an SMTP transport (FR-44); it is not a message bus and does not become one by
  adding a queue in front of it, which would be a different library's job.
