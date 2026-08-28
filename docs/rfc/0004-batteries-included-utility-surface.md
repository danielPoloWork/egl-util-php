# RFC-0004: The batteries-included surface — twenty additive units from the module-coverage review

- **Status:** In review — awaiting the maintainer's approval. The Approval record below is
  filled on that word, never by the author (`AGENTS.md` §6.1; no RFC self-approves).
- **Author:** tech-lead (agent-drafted) · **Reviewers:** reviewer, enterprise-architect
  (seats worn by the session agent, disclosed in the Approval section — the 2026-08-06 plan
  pass's precedent) · **Approver:** tech-lead (the maintainer's word records it)
- **Date:** 2026-08-28
- **Related:** [RFC-0003](0003-post-1-0-functional-scope.md) (the shape this pass repeats, one
  size up) · frozen spec [`01_spec_utils.md`](../specs/01_spec_utils.md) **r29** (last numbers:
  FR-50 / FR-48b, NFR-15, T-15 — this RFC's surface continues at **FR-51, NFR-16, T-16**) ·
  [ADR-0059](../adr/0059-freeze-the-api-at-1-0-0-with-internal-symbols-outside-the-frozen-surface.md)
  (the freeze every unit lives inside) ·
  [ADR-0021](../adr/0021-delegate-rich-html-and-escape-like-wildcards-with-a-portable-character.md)
  (the optional-dependency posture reused three times here) ·
  [ADR-0040](../adr/0040-install-the-mutation-tester-outside-the-graph-and-keep-nfr07s-own-number.md)
  (the spec owns its numbers) · GitHub issues **#187–#194, #196–#197, #199–#208** (the twenty
  candidates; #195 was closed invalid before this pass and is not scope) · feeds **Milestones
  15–18** (the plan pass negotiates the roadmap)

## Context

`v1.1.0` shipped M14's five seams and the backlog emptied again: `ROADMAP.md` has zero unchecked
items and the only open issue outside this program is #115, whose remaining criterion is the
owner's. Into that gap the maintainer gave a direction, twice and explicitly (2026-08-27 and
2026-08-28): make `egl/utils` the **must-have** package — the library that covers the primary
functionality an enterprise PHP estate operates on, so that reaching for it is the default and
reaching past it is the exception.

Two module-coverage passes over the shipped surface produced **twenty candidate issues**, each
screened before filing against the recorded exclusions (RFC-0003's do-NOT-add list, the patterns
catalogue's rejections, the third-party picks) and each carrying its advisory route:

- **first pass (#187–#197):** the gaps visible from inside the existing groups — the missing
  inverse of hydration, key mapping, TOTP, path safety, XML intake, log redaction, URL signing,
  the circuit-breaker revisit, two `Str` gaps. #195 (streaming CSV) was filed in this pass and
  **closed invalid the next day**: `Csv` already streams in both directions, and the issue was
  written from method names without reading the docblocks. That correction is part of this
  record — one candidate here (#208) exists *because* the re-read found the real gap.
- **second pass (#199–#208), maintainer-directed:** serialization formats beyond JSON, format
  conversion, array/tabular tooling, string and date helpers — the breadth pass. It also
  **overrode one of the author's own screenings**: `Arr` had been kept out on an inferred taste
  conflict with the DTO direction; the maintainer asked for it, and the recorded decisions never
  named it, so the screening was inference, not precedent.

What forces an RFC now is the same mechanism that forced RFC-0003: **every one of the twenty
requires a spec amendment by its own acceptance criteria**, the plan protocol requires every
roadmap item to reference an approved RFC, and no RFC covers them. Two further forces are new:

1. **The candidates interlock.** `#[MapFrom]` (#188) needs `Str`'s case conversions (#197);
   `Archive` (#206) is built on `Path` (#191); the Validation group's `Length` rule needs the
   codepoint-safe `Str::length()` (#207) or it counts bytes and rejects a valid four-character
   name in Cyrillic; `Yaml`'s encode refusal message points consumers at `toArray()` (#187).
   Unsequenced, each lands hand-rolling the piece another already owns.
2. **The freeze prices mistakes.** All twenty must be additive (ADR-0059), and a shape wrong at
   landing can only be corrected through the deprecation window. A design pass is where wrong
   shapes are cheap.

## Decision

Accept **all twenty candidates**, grouped into **four milestones (M15–M18)** whose partition is
dependency-driven; defer nothing from this set, and record the standing exclusions that keep the
"must-have" ambition bounded. The screening that would normally reject candidates at this table
already ran twice at issue-filing — what this RFC decides is the *architecture the twenty share*,
the *sequence*, and the *one spec reversal* among them (FR-49's circuit-breaker non-goal).

### The governing constraints

1. **Additive only** (ADR-0059): new classes and new static/instance methods; no existing
   signature moves. Where an existing class gains behaviour (`Str`, `Csv`, `Json`,
   `Collection`, `DataTransferObject`), it gains members and loses none.
2. **Zero new `require` entries.** The entire program adds **no hard dependency**: `Yaml` rides
   `symfony/yaml` in `suggest`, `Archive` rides `ext-zip` in `suggest`, `Xml` rides
   `ext-dom`/`ext-libxml` in `suggest` — each behind
   [ADR-0021](../adr/0021-delegate-rich-html-and-escape-like-wildcards-with-a-portable-character.md)'s
   posture (refuse at the call site when absent, no foreign types in signatures, a dedicated
   deptrac layer only the consuming group may reach). Everything else is core PHP. NFR-08 is
   untouched — verified unit by unit in review finding **A2** below.
3. **One canonical shape, N codecs — composition is the converter.** Every format door
   (`Json`, `Csv`, `Yaml`, `Ini`, `Xml`, NDJSON) decodes to arrays and encodes from them; DTO
   serialization (FR-51) makes typed objects a source and a destination
   (`Yaml::encode($dto->toArray())`). Format conversion is therefore function composition —
   `Json::writeLines($path, Csv::read($csv))` is a streaming CSV→NDJSON converter — and **no
   converter class or format registry exists**. A registry would add a stringly-typed format
   axis, hide which codec runs behind a dispatch table, and break the `grep`-ability this
   codebase treats as a review tool (ADR-0041's property, generalized).
4. **The missing-value grammar is now a named convention.** `Lookup` established it; `Arr`
   adopts it verbatim; the rest of the program follows it: **asserting** reads throw
   (`Arr::get()` — the caller stated the key exists), **defaulting** reads take the default
   (`Arr::getOr()`), **probing** reads return `null` (`Arr::tryGet()`, `Str::before()` — a miss
   is an answer, not an error). A silent fallback that guesses (returning the subject unchanged,
   clamping, last-wins) is the shape this library refuses everywhere, restated here once so
   twenty ADRs can cite it instead of re-arguing it.
5. **Refusals name the culprit.** Duplicate keys (`Arr::keyBy`, `Csv::readAssoc` headers,
   `Ini` sections), ragged shapes (`Arr::transpose`, `readAssoc` rows), rollover dates,
   traversal segments, oversize archive entries: every refusal carries the key, line, column,
   segment or entry name that caused it. A refusal the operator cannot act on is half a refusal.
6. **Every time-touching API takes `ClockInterface`** — FR-45's clause, applied to `Dates`
   (anything "now"-relative), `Totp`, `SignedUrl` expiry, and the circuit breaker's cooldown.
   Nothing in this program reads system time directly.
7. **Streaming writers inherit `File::writeStream()`** (ADR-0005's atomicity): NDJSON and any
   future row-oriented writer produce no partial file at the destination, the guarantee `Csv`
   already carries.
8. **Security units carry ADR-0027's discipline**: mechanism assertions where behaviour cannot
   see the property, every new `hash_equals()` registered in T-03's completeness registry
   (BUG-0001's repaired guard), and a threat-model touch for each new untrusted-input boundary
   (XML documents, serialized payloads, archive entries, path segments) in the same PR.

### Scope: accepted, by milestone

FR numbers are permanent; milestone membership is the proposal the plan pass negotiates.
Groups: `Dto`, `Support`, `Validation` (new), `Security`, `Errors`.

**M15 — Data & time foundations** *(no new dependencies, no new groups; the seams the later
milestones consume)*

| FR | Component | Group | Source issue |
|---|---|---|---|
| **FR-51** | `DataTransferObject::toArray()` + `JsonSerializable` | `Dto` | #187 |
| **FR-52** | `#[MapFrom]` hydration key mapping | `Dto` | #188 |
| **FR-53** | `Collection<T>` operations (sortBy, groupBy, keyBy, chunk, slice family) | `Dto` | #200 |
| **FR-54** | `Arr` — boundary array toolkit (dot paths, projections, grouping, tabular) | `Support` | #199 |
| **FR-55** | `Dates` + `DateRange` — strict parsing, boundaries, half-open ranges | `Support` | #201 |
| **FR-56** | `Str` batch 1 — mask, truncate, snakeCase, camelCase, isUuid, isUlid | `Support` | #197 |
| **FR-57** | `Str` batch 2 — before/after/between, normalizeEol, codepoint length/substr | `Support` | #207 |
| **FR-58** | `Csv::readAssoc()` — header-mapped rows | `Support` | #208 |

Sequencing inside M15 is load-bearing twice: **FR-56 before FR-52** (the `#[MapFrom]`
class-level convention, if its ADR adopts one, is defined in terms of `Str::snakeCase()`), and
**FR-57 before M16** (the `Length` rule counts codepoints, not bytes). FR-51's round-trip
property (`X::fromArray($x->toArray()) == $x` over the T-01 matrix) is the contract that keeps
export and hydration from drifting; FR-52 additionally binds `toArray()` to *invert* the mapping.

**M16 — Input validation** *(the new group; completes Request → Validation → Dto)*

| FR | Component | Group | Source issue |
|---|---|---|---|
| **FR-59** | `Validator`, `ViolationList`, `Violation` — aggregation semantics | `Validation` | #189 |
| **FR-60** | Rule set v1 — `Required`, `Length`, `Range`, `Pattern`, `OneOf`, `Email` | `Validation` | #189 |

The one deliberate inversion of house style, stated as such: **validation aggregates instead of
first-throwing** — a form needs every violation at once, named per field, so `ViolationList` is
a typed *value* (machine-readable codes; wording is the consumer's, per the i18n exclusion) and
exceptions are reserved for *misuse* of the validator itself (`RetryPolicy`'s
`InvalidArgumentException` precedent). New deptrac layer, `Support`-only edge, proven
discriminating with a planted violation (ADR-0012's discipline). The `Email` rule's relation to
`Mail\EmailAddress` is settled in review finding **A1** below rather than left to the ADR.

**M17 — Formats & the serialization story** *(the codec fan-out; constraint 3 is the design)*

| FR | Component | Group | Source issue |
|---|---|---|---|
| **FR-61** | `Yaml::decode()/encode()` — safe subset over optional `symfony/yaml` | `Support` | #202 |
| **FR-62** | `Ini::decode()` — typed, refusing | `Support` | #203 |
| **FR-63** | `Json::readLines()/writeLines()` — NDJSON streaming | `Support` | #205 |
| **FR-64** | `Serialized::decode()` — object injection refused, allowlist opt-in | `Support` | #204 |
| **FR-65** | `Xml::decode()` — DOCTYPE refused, `LIBXML_NONET` always | `Support` | #192 |

FR-64 and FR-65 are security-relevant format doors (untrusted serialized payloads; XXE and
entity expansion) and carry constraint 8 in full. FR-65 keeps the demand caveat its issue
states — RFC-0002's estate survey surfaced no XML call sites — and is sequenced **last in M17**
so the plan pass can defer it on demand evidence without unthreading anything; accepting it here
records that when XML intake arrives (invoice formats, SOAP peers, feeds), the answer is one
safe door and not a per-project parser.

**M18 — Security hardening & resilience** *(the trust-boundary batch)*

| FR | Component | Group | Source issue |
|---|---|---|---|
| **FR-66** | `Path` — traversal-safe join/within/sanitizeFilename | `Support` | #191 |
| **FR-67** | `Archive::extractZip()` — zip-slip-safe, budget-bounded | `Support` | #206 |
| **FR-68** | `Totp` — RFC 6238/4226 over Hmac + clock | `Security` | #190 |
| **FR-69** | `SignedUrl` — canonicalized signing over Hmac + Url | `Security` | #194 |
| **FR-70** | `RedactingLogger` — secrets masked before fan-out | `Errors` | #193 |
| **FR-71** | Circuit breaker — three-state, CAS-store-backed, opt-in | *ADR decides* | #196 |

FR-67 depends on FR-66 (every entry name passes `Path::join()` before a byte is written) —
that edge is the reason both sit in one milestone. **FR-71 reverses FR-49's stated non-goal**,
and this RFC is the instrument of that reversal: the non-goal's recorded reason — *"that is
shared cross-call state, not a parameter"* — described a library with no shared-state seam, and
item 14.7 shipped exactly that seam
([ADR-0067](../adr/0067-the-bucket-refills-in-whole-tokens-and-the-store-contract-is-tested-twice.md)'s
`RateLimitStore`, compare-and-swap, contract-tested against every shipped store). The reversal
follows #91's own precedent (a deferral lifted when its premise dissolved, RFC-0003's Status
note) and is **annotated at FR-49, never erased** (ADR-0041's rule). What stays open for FR-71's
ADR is placement — `Security` beside the limiter, or the CAS store contract extracted to
`Support` — because layering forbids `Support → Security` and this RFC does not prejudge a
BC-relevant move.

### API contract (`api` / `systemdesign`)

- **Operations** — the FR-51…FR-71 surfaces above, under the existing namespace scheme
  (`D4np\Utils\{Dto,Support,Security,Errors}\` plus new `D4np\Utils\Validation\`). No existing
  signature is touched; `Version.php` and composer identity unchanged.
- **Payloads** — readonly value objects with named constructors (`DateRange`, `ViolationList`,
  `ExtractionBudget`/`ExtractionReport`), the group-wide shape (`SqlStatement`, `PageRequest`
  precedents); streams are `Generator`s (`readAssoc`, `readLines`, `DateRange::iterateBy`);
  static pure functions for the stateless helpers (`Arr`, `Str`, `Path`, the codecs).
- **Error model** — every failure is a typed exception from the existing hierarchy, and each
  new type joins `ExceptionHierarchyTest`'s pinned discovered-class and finality lists in the
  PR that adds it. Expected new types: `YamlException`, `IniException`, `XmlException`,
  `SerializedException`, `ArchiveException`, `PathException` (or `FileException` reuse — each
  landing ADR decides reuse-vs-new against the hierarchy's existing grain); `Totp`/`SignedUrl`
  raise `CryptoException` (FR-48's family); `readAssoc` raises `CsvException`; validation
  violations are **values, not exceptions** (M16 above). The `bool|string` anti-requirement
  (RFC-0002) applies to every verify-shaped operation here.
- **Versioning** — each milestone is releasable as one MINOR under
  [`maintenance.md`](../workflow/maintenance.md)'s decision tree (M15→`v1.2.0` …
  M18→`v1.5.0` as candidates; the tree owns the actual numbers, not this document). A MAJOR
  would be required only to remove or alter a shipped unit — ADR-0059's deprecation window is
  the only exit, so a wrong shape here is expensive; that asymmetry is why the review pass
  below exists.

### Data & schema (`database`)

Omitted — no unit owns persistent state. The circuit breaker's state (FR-71) lives behind the
consumer-provided CAS store, exactly as the rate limiter's does (ADR-0061): the library ships
the algorithm and the contract, never the storage.

### Scalability budgets (`scalability`)

Per [ADR-0040](../adr/0040-install-the-mutation-tester-outside-the-graph-and-keep-nfr07s-own-number.md),
the spec owns its numbers and they are set from reference-runner measurement at implementation —
never from this document, and never from the project's Windows box (overstates CPU-bound work
2×–5×, measured repeatedly: items 10.11, 10.12, 12.3). This RFC names the axes:

- **NFR-16 — streaming memory flatness.** `Csv::readAssoc()` and `Json::readLines()/writeLines()`
  hold peak memory flat while row count scales (NFR-04's methodology, subjects under
  `src/bench/` with the job's existing control subject). This is the property the streaming
  claim *is*; a streaming reader that buffers is a defect wearing an API.
- **FR-52 must hold NFR-01.** `#[MapFrom]` sits inside the hydration path NFR-01 budgets
  (≤ 3× manual construction, ≤ 5 µs warm): the acceptance criterion is that **unattributed
  DTOs measure unchanged within the harness's noise band** on NFR-01's existing subject, and
  mapped shapes are measured and recorded (falling back to the interpreted path is acceptable;
  taxing every unmapped hydration is not). Review finding **A3**.
- **No latency budgets elsewhere, stated rather than implied.** `Arr`/`Collection`/`Dates`/the
  codecs run at boundary or config time, dominated by I/O or one-shot parsing; NFR-14's control
  subject measured 57% of its own subject, and a budget that mostly bounds PHP's dispatch
  asserts nothing (RFC-0003's clock reasoning, reused). If implementation measurement shows a
  unit on a per-row path, it gains a budget then, by the spec's own rules.

### Algorithm sketch (`pseudocode`)

The one non-obvious core, FR-55's strict parse — everything else here is either
standards-defined (TOTP: RFC 6238 test vectors; base32: RFC 4648) or a state machine the ADR
will draw (FR-71):

```
fromFormat(value, format, tz?):
    dt   <- DateTimeImmutable::createFromFormat("!" + format, value, tz)
    errs <- DateTimeImmutable::getLastErrors()
    refuse if dt is false or errs.errors > 0 or errs.warnings > 0
        # rollover ("2026-02-31" -> Mar 3) surfaces ONLY as the warning
        # "The parsed date was invalid" — natively it is not an error at all
    refuse if dt.format(format) != value        # trailing garbage / prefix match
    return dt
```

The double check is load-bearing: `getLastErrors()` catches rollover, the re-format round-trip
catches what a prefix match silently accepted. Mirrored into spec §4 with FR-55.

### Cross-cutting

**Security.** Six units are security-surface (FR-64, FR-65, FR-66, FR-67, FR-68, FR-69) and
FR-70 is data-handling; each carries the protected routing floor, a mandatory ADR (enterprise
posture, `AGENTS.md` §7), ADR-0027 mechanism assertions where behaviour cannot see the property
(comparators, allowlists, parse flags, the `allowed_classes` derivation), and a
`docs/security/threat-model.md` update for each new untrusted-input boundary in the same PR.
Honest limits ship in docblocks, not discovered in incidents: `Path` is lexical (a symlink can
still point out), `Totp` cannot prevent cross-call replay (consumer state), the redactor's
contract is keys not free text, an allowlisted gadget's `__wakeup` still runs.

**Performance.** The NFR-16 axis and the FR-52/NFR-01 constraint above; everything else is
measurement-first with the escalation rule stated.

**Standing exclusions — the second wave of "why not X", recorded citably** (extends RFC-0003's
non-goals, which all stand): **no PSR-14 event dispatcher** (in-process publish–subscribe; the
EIP category is pre-classified out of scope in the patterns catalogue and the PSR-15 middleware
rejection records the same taste — straight-line code a reader can follow); **no Config module**
(`Env` + `Ini` + `Yaml` compose; a config façade is application wiring); **no JWT**
([ADR-0065](../adr/0065-a-detached-signature-over-a-derived-key-with-the-algorithm-never-in-the-token.md)
is designed *against* the `alg`-confusion class — the versioned house token is the answer, and a
consumer forced to interop brings `firebase/php-jwt` themselves); **no caching** (third-party
pick: `symfony/cache`); **no linear algebra** (`Arr` ships tabular transforms — transpose,
column — and `markrogoyski/math-php` joins the third-party picks in FR-54's landing PR, the
`brick/math` pattern); **no country-specific identifier validators** (IBAN/VAT/fiscal-code rules
are a registry-maintenance treadmill; revisit as a rule-pack once FR-59/60 exist and a named
consumer asks — recorded in Alternatives #7); **no MessagePack/protobuf** (extension territory);
**no XLSX** (`phpspreadsheet` territory; `Csv` is this library's tabular door).

## Alternatives

1. **Do nothing — keep the library at its M14 surface.** Rejected: the maintainer's direction
   is explicit and repeated, and the estate keeps paying the hand-rolled versions of exactly
   these twenty (the dot-path helper, the `getLastErrors()` dance, the `unserialize()` call
   with no second argument). An empty backlog was the finding that opened RFC-0003; refusing
   the refill re-creates it.
2. **One milestone with all twenty units.** Rejected on cadence, not size alone: M14 carried
   five units at size L; twenty in one milestone means ~20 sequential PRs (one at a time,
   `AGENTS.md` §6.1) against a single milestone with no intermediate release point, when the
   post-1.0 tree releases in batches (`maintenance.md`). Four milestones give four coherent
   MINORs and four points where the maintainer can stop, reprioritize, or ship.
3. **Adopt the ecosystem instead of building** — `nesbot/carbon` for dates,
   `illuminate/collections` for `Arr`/`Collection`, `symfony/serializer` as the format layer,
   `symfony/yaml` in `require`. Rejected: NFR-08 is the recorded dependency policy (no
   third-party implementation dependencies in the core), and the units' *value* is house
   refusal semantics the ecosystem versions do not carry — Carbon parses what strict parsing
   refuses, `Arr::get` guesses where this one throws, the serializer's format registry is
   constraint 3's named anti-goal. Where the ecosystem *is* the right answer, the third-party
   picks page already says so, and this RFC adds to it rather than competing with it.
4. **A `Serializer` façade / converter registry over the codecs.** Rejected: constraint 3 —
   composition is the converter; a registry adds a stringly-typed dispatch axis, hides which
   codec runs, and destroys the property that `grep Yaml::` finds every YAML consumer.
5. **Defer the Validation group to its own RFC-0005.** Rejected: it is the highest-leverage
   unit for the stated goal (it completes the intake pipeline this library exists to harden),
   its architectural questions (layer, aggregation-not-throwing, the `Email` primitive) are
   answerable now and are answered above and in finding A1; a second RFC cycle would re-pay
   this document's overhead to move one group.
6. **Keep FR-49's circuit-breaker non-goal.** Rejected because its stated premise is now false
   (the CAS seam exists, ADR-0067) — but the *reversal* is the maintainer's to approve, which
   is precisely what approving this RFC does; the non-goal text is annotated, not erased, and
   placement stays open for the ADR (M18 above).
7. **Country-specific validators (IBAN/VAT/fiscal code) in rule set v1.** Deferred with the
   revisit condition recorded: IBAN's mod-97 is stable but the per-country length/format tables
   and VAT grammars are registries that rot; the honest version is a rule-pack maintained
   against a named consumer's need, after FR-59/60 exist. Deferred ≠ rejected — the issue-shaped
   ask, when it comes, lands as a rule-pack candidate citing this line.
8. **A fluent instance wrapper for `Arr`** (chainable `Arr::from($a)->only(...)->get(...)`).
   Rejected: a mutable-looking pipeline over copies invites exactly the aliasing confusion a
   boundary tool must not add, and the chainable typed home already exists — `Collection<T>`
   (FR-53 widens it). `Arr` stays static and pure; two shapes for one need is the estate's
   disease, not its cure.

## Consequences

**Made easier.** The intake pipeline closes end to end (`readAssoc`/`Request` → `Validator` →
`fromArray` → typed domain, and back out through `toArray()` to any codec). Format conversion
becomes composition over one canonical shape. The five most hand-rolled enterprise snippets in
the surveyed estate's class (dot-path access, strict date parsing, safe `unserialize`, TOTP,
path joining) get house answers with named refusals. Every future time-touching API has its
seam; every streaming writer has its atomicity.

**Made harder.** Twenty new public surfaces are twenty more things the freeze protects —
ADR-0059's deprecation window is the only exit, and it is deliberately slow; this is the
argument for the review gate this document just passed through, and for the plan pass keeping
milestone boundaries it can stop at. `ExceptionHierarchyTest`'s pinned lists grow by up to six
types. The `@internal` inventory (ADR-0082) likely grows (a Base32 codec, canonicalization
internals) and each addition updates the pin in the same PR.

**Install footprint: unchanged.** Zero new `require` entries (constraint 2, verified in A2);
three new `suggest` lines. The dist stays 121 files + the new sources.

**Migration path.** None — every unit is additive; a `v1.x` consumer is unaffected until it
opts in. The two caller-visible defaults worth naming now: `Serialized::decode()` refuses *all*
objects by default (the safe direction; the estate's existing payloads need the explicit
allowlist), and `Validator` returns violations rather than throwing (a consumer porting from an
exception-based validator inverts one branch).

**Follow-up roadmap items.** The plan pass (issue to be filed, RFC-0003 → #84's shape) turns
FR-51…FR-71 into numbered M15–M18 items with sizes and routes — the type labels already exist
on all twenty issues, so `route_advice.py` is machine-verifiable from day one this time. The
phase ledger's known gap is re-encountered, not widened: the delivery machine sits at
`scaffold` (only `→ audit` legal), as it has since 2026-08-03, and this design pass — like
RFC-0002's and RFC-0003's — runs under the owner's routing of that gap; `refs.rfcs` gains
`RFC-0004` in the same PR (state-writer step, authority-checked). `rfc_check.py` stays **red on
this document until the Approval record below is filled — that red is the gate working**, not a
defect to silence.

## Approval

*Pending the maintainer's word. On approval, the fenced record below is completed with the
approver role and the ISO date of that word — the marker line the RFC template defines. The
author does not fill it (`AGENTS.md` §6.1). The marker is deliberately **not spelled out in
this prose**: `rfc_check.py` matches the first marker-shaped line in the document, and a
sentence quoting it would satisfy the gate on an unapproved RFC — the same
text-search-finds-the-prose class as BUG-0001 and the `bridge_release_gate` defect, met here a
third time and dodged by describing the tag instead of writing it (item 10.7's rule).*

Reviewers (structured findings, all resolved — **both seats worn by the session agent and
disclosed as such**, the 2026-08-06 plan pass's precedent; an independent human pass supersedes
this table wherever the maintainer wants one):

| # | Seat | Finding (evidence) | Resolution |
|---|---|---|---|
| R1 | reviewer | FR numbering risk: spec r29's tail is FR-50 plus letter-suffixed amendments (FR-40b, FR-48b) — a collision would corrupt the coverage map (`grep -oE "FR-[0-9]+" …` verified) | Next-free confirmed **FR-51**; letters stay reserved for amendments of existing FRs, never new units — stated in the Related header |
| R2 | reviewer | #200 as filed floats reusing `Database\Sort` for `Collection::sortBy()` — `Dto → Database` is a forbidden edge (ADR-0012's layering, deptrac-enforced) | FR-53 carries a local ordering parameter in `Dto`; the landing ADR picks its shape. The RFC states the constraint so the ADR cannot inherit the violation |
| R3 | reviewer | M18 carries six units where M14's size-L carried five — milestone-sprawl risk (ROADMAP M14 precedent) | Recorded for the plan pass, which owns sizing: FR-66/67 (Path+Archive) are the natural split line if the producer wants one; the FR grouping above survives either way |
| A1 | enterprise-architect | FR-60's `Email` rule cannot import `Mail\EmailAddress` (cross-group edge, ADR-0012) and silent re-derivation is how two validators drift apart (item 10.4's two-corpora lesson, cross-group edition) | **Decided here:** v1 re-derives from the same primitive (`filter_var` + FR-43's documented deviations); divergence is pinned by a **shared-corpus parity test** in the test tree (tests may cross groups; production may not) asserting both accept/reject identically; extraction of the primitive into `Support` is revisited only when a third consumer appears |
| A2 | enterprise-architect | Constraint 2's zero-new-`require` claim must be verified per unit, not asserted (NFR-08 is a recorded policy) | Verified: FR-68 uses core `hash_hmac` with a hand-rolled `@internal` Base32 (RFC 4648); FR-61 `symfony/yaml`→`suggest`; FR-67 `ext-zip`→`suggest`; FR-65 `ext-dom`/`ext-libxml`→`suggest`; FR-51…60, 62–64, 66, 69–71 are core-only. Claim holds |
| A3 | enterprise-architect | FR-52 sits inside NFR-01's budgeted hydration path — a mapping-aware metadata walk could tax every unmapped DTO in the estate (NFR-01: ≤ 3× manual, item 3.7's compiled closure) | FR-52's acceptance criteria bind it: unattributed DTOs measure unchanged within the harness noise band on NFR-01's existing subject; mapped shapes may take the interpreted path with `HydrationParityTest` extended. Recorded under Scalability budgets |

```
approved-by: (pending)
```

## References

- Issues [#187](https://github.com/danielPoloWork/egl-util-php/issues/187)–[#194](https://github.com/danielPoloWork/egl-util-php/issues/194),
  [#196](https://github.com/danielPoloWork/egl-util-php/issues/196)–[#197](https://github.com/danielPoloWork/egl-util-php/issues/197),
  [#199](https://github.com/danielPoloWork/egl-util-php/issues/199)–[#208](https://github.com/danielPoloWork/egl-util-php/issues/208)
  (the twenty candidates) · [#195](https://github.com/danielPoloWork/egl-util-php/issues/195)
  (closed invalid — the correction this RFC carries) ·
  [#115](https://github.com/danielPoloWork/egl-util-php/issues/115) (outside this scope)
- [RFC 6238](https://www.rfc-editor.org/rfc/rfc6238) (TOTP) · [RFC 4226](https://www.rfc-editor.org/rfc/rfc4226)
  (HOTP) · [RFC 4648](https://www.rfc-editor.org/rfc/rfc4648) (Base32) ·
  [RFC 9562](https://www.rfc-editor.org/rfc/rfc9562) (UUID formats FR-56 validates)
- `docs/specs/01_spec_utils.md` r29 (FR-01…FR-50, NFR-01…NFR-15, T-01…T-15 — this RFC's
  surface continues at FR-51, NFR-16, T-16)
- [ADR-0005](../adr/0005-atomic-file-writes-with-a-sidecar-lock.md) ·
  [ADR-0008](../adr/0008-dto-hydration-strictness-and-shared-hydrator.md) ·
  [ADR-0012](../adr/0012-enforce-the-layering-rule-by-directory-over-src-main.md) ·
  [ADR-0013](../adr/0013-compile-a-hydration-closure-for-the-scalar-shape.md) ·
  [ADR-0027](../adr/0027-constant-time-comparison-is-asserted-by-mechanism-not-by-timing.md) ·
  [ADR-0037](../adr/0037-disable-phps-escape-character-and-keep-the-formula-guard-opt-in.md) ·
  [ADR-0041](../adr/0041-constrain-sql-text-by-type-and-name-the-one-escape-hatch.md) ·
  [ADR-0042](../adr/0042-trim-is-the-only-default-and-the-transcode-runs-first.md) ·
  [ADR-0054](../adr/0054-authenticated-encryption-with-fixed-lengths-and-a-key-only-secretkey-can-produce.md) ·
  [ADR-0055](../adr/0055-one-ordering-validation-before-filtering-and-a-swallow-only-at-the-leaf.md) ·
  [ADR-0061](../adr/0061-a-token-bucket-behind-a-compare-and-swap-store-and-keys-hashed-at-the-boundary.md) ·
  [ADR-0062](../adr/0062-the-clock-seam-ships-both-halves-and-support-gains-its-first-outward-edge.md) ·
  [ADR-0066](../adr/0066-a-second-seam-for-waiting-and-a-deadline-that-only-bounds-the-loop.md) ·
  [ADR-0082](../adr/0082-pin-the-internal-inventory-so-widening-the-carve-out-is-visible.md) ·
  [ADR-0083](../adr/0083-a-derived-key-id-in-the-aad-and-v1-stays-byte-identical.md) ·
  [ADR-0085](../adr/0085-the-key-id-under-the-mac-because-hmac-has-no-aad.md)
- [`docs/patterns/third-party-picks.md`](../patterns/third-party-picks.md) (the page FR-54's
  math-php row and the standing exclusions extend)
