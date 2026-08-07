# ADR-0051: One envelope shape, and a reference instead of the exception

- **Status:** Accepted
- **Date:** 2026-08-07
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item **11.3** · spec r3 **FR-39** (RFC-0002) ·
  [ADR-0029](0029-result-carries-a-throwable-and-production-withholds-the-message-too.md)
  (production withholds the message as well as the trace — the stance this applies) ·
  [ADR-0015](0015-identifier-allowlist-closed-keywords-and-the-anchor-hole.md) (a closed
  vocabulary becomes an enum) · [ADR-0050](0050-classify-the-miss-and-keep-the-router-a-table.md)
  (the router whose misses this envelope reports) · RFC-0001 §"Decision" (the layering rule that
  keeps the `Result` mapping out of `Http`) · pattern doc
  [`endpoint-kernel.md`](../patterns/endpoint-kernel.md)

## Context

The surveyed estate carried **three** response-envelope implementations — one per application
plus a vendored copy — with 232+ construction sites between them and no two agreeing on their
field names. A client written against one could not read another, and the outcome vocabulary
(`ok`, `created`, `notFound`, …) was re-spelled in each, with each having its own idea of which
HTTP status accompanied which outcome.

FR-39 asks for one envelope: a readonly value with the fixed shape `status`, `code`, `messages`,
`data`, nine outcome constructors, and message strings supplied by the caller so localization
stays in the application.

Most of that is transcription. Two things in it are decisions, and one of them is
security-relevant — which under the enterprise posture (`AGENTS.md` §7/§10) is what earns this
ADR, since the roadmap item did not schedule one.

## Decision

**One envelope, one fixed shape, and `caught()` takes a correlation reference rather than a
`Throwable`.**

- **The shape is fixed**: all four keys are serialized on every outcome, including `data: null`
  and `messages: []`. A client may read `payload.data` without first checking the key exists —
  which is the entire value of an envelope, and is lost the moment nulls are omitted to save
  bytes. Asserted per outcome, and `messages` is pinned to encode as `[]` rather than `{}`.
- **The outcome vocabulary is an enum** (`Outcome`), each case owning **its HTTP status**, so the
  mapping the estate had three times exists once. ADR-0015's reasoning: a closed vocabulary that
  reaches a consumer-visible payload should be settled by the type system.
- **`caught(string $reference)` does not accept a `Throwable`.** An envelope built from an
  exception would put `getMessage()` on the wire by default, and a message names schemas, file
  paths and query fragments as readily as a stack trace does — which is precisely why ADR-0029
  has production withhold *both*. The client receives a reference; the exception belongs in the
  log under that same reference, where `Errors\ExceptionHandler` already puts it. Asserted as a
  **mechanism** on the method's signature, because behaviour cannot see an overload that does not
  exist: a future `caught(Throwable $e)` would pass every other test in the suite.
- **Two status choices, stated rather than assumed.** `Invalid` is **422**, not 400 — the request
  was well-formed and understood, and a client can then tell a malformed request from a rejected
  one without reading the body. `Empty` is **200**, not 404 — a search with no results is a
  successful search, and the estate's habit of 404-ing an empty collection is what teaches
  clients to treat "no rows" as a retryable failure.
- **The `Result` → envelope mapping is deliberately not here.** `Errors\Result` is in another
  group and RFC-0001's layering rule forbids `Http` importing it. The three-line adapter belongs
  in the application, and the pattern doc shows where. This is the same call ADR-0050 made for
  the router's status codes: the library supplies the vocabulary, the application supplies the
  policy.
- **The envelope is a payload, not a response.** It carries the status its outcome implies via
  `status()`; sending it is `Response`'s job. The constructor is private, so every instance comes
  from a named outcome rather than an arbitrary combination.

## Alternatives Considered

- **`caught(Throwable $e)`, formatting the message internally with an env check.** The obvious
  API, and the one the estate used. Rejected: the env-gated variant duplicates a decision
  `ExceptionHandler` already owns (ADR-0029), and it makes the safe behaviour conditional on
  configuration being right in production. A signature that cannot receive the exception cannot
  leak it.
- **Omitting `null` fields from the JSON** (the `array_filter` reflex). Rejected: it makes the
  shape depend on the data, which is the problem the envelope exists to solve. The bytes saved
  are noise next to a client having to guard every access.
- **`status` as a boolean `success` flag plus a separate error code.** Rejected: it collapses
  nine distinct answers into two, and the caller then re-derives which of them happened from the
  HTTP code — putting the taxonomy back in the client, where it was in the estate.
- **`code` as an application-specific error code** rather than the HTTP status. Rejected for this
  library: an application code space is the application's to define, and duplicating the HTTP
  status is more useful to a client that has already got the status from the response line — it
  makes the payload self-describing when logged or forwarded, which is where envelopes get read
  in practice.
- **A tenth `unauthorized`/`forbidden` outcome.** Not added: FR-39 fixes the taxonomy at nine,
  and authentication outcomes belong to whatever authorization layer a consumer has — a library
  that names them implies it knows what they mean. Adding one is a spec change, and the suite
  asserts the count so it cannot happen quietly.
- **Enum without the HTTP mapping**, leaving status choice to callers. Rejected as the default:
  it is exactly the state the estate was in, with three implementations disagreeing. A caller
  that needs a different status for one endpoint still sends one — the envelope does not send
  itself.

## Consequences

**One shape across every application that adopts it**, and one place the outcome→status mapping
lives. A client can be generated from the four keys.

**The taxonomy is closed at nine**, and the suite asserts the count, so growing it is a
deliberate spec change rather than an addition someone slips in. Both `match` expressions are
exhaustive over `Outcome::cases()` — asserted by iterating the enum, because a missing arm is an
`UnhandledMatchError` at run time rather than a compile error.

**`caught()` is slightly less convenient than the version that takes the exception**, which is
the point: the caller must obtain a reference from the logger first, which is the step that puts
the diagnosis where it belongs. `ExceptionHandler` already produces exactly that reference.

**No i18n surface.** The library writes one English string — the fallback message for a
`caught()` with no wording — and every other message is the caller's. That one string is
asserted, so a future addition to the library's vocabulary is visible in a diff.

**Nothing in `Http` learned about `Errors`.** No new deptrac edge; the mapping the application
wants is documented instead of imported.

## References

- Spec r3 **FR-39**; [RFC-0002](../rfc/0002-application-layer-groups-from-legacy-intake.md)
- RFC 4918 §11.2 (`422 Unprocessable Content` — the well-formed-but-rejected distinction)
- `src/main/php/d4np/utils/Http/{ApiEnvelope,Outcome}.php`
- [ADR-0029](0029-result-carries-a-throwable-and-production-withholds-the-message-too.md) — the
  message-withholding stance this applies at the payload boundary
