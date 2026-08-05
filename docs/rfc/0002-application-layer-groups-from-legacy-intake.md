# RFC-0002: Application-layer utility groups from legacy intake

- **Status:** In review
- **Author:** tech-lead (agent-drafted) · **Reviewers:** reviewer, enterprise-architect
  (cross-cutting: two new groups and a new deptrac layer) · **Approver:** tech-lead — the
  approval record is added only on the maintainer's decision at PR review (AGENTS.md §6.1;
  no RFC self-approves)
- **Date:** 2026-08-05
- **Related:** [RFC-0001](0001-egl-utils-library.md) · frozen spec
  [`docs/specs/01_spec_utils.md`](../specs/01_spec_utils.md) ·
  [ADR-0015](../adr/0015-identifier-allowlist-closed-keywords-and-the-anchor-hole.md) (identifier
  allowlist), [ADR-0017](../adr/0017-prove-binding-at-the-pdo-boundary-and-defer-t02s-like-leg.md)
  (query-log injection proof), [ADR-0021](../adr/0021-delegate-rich-html-and-escape-like-wildcards-with-a-portable-character.md)
  (optional-dependency layer precedent), [ADR-0025](../adr/0025-typed-http-wrappers-that-refuse-rather-than-coerce.md)
  (refuse-don't-coerce), [ADR-0005](../adr/0005-atomic-file-writes-with-a-sidecar-lock.md)
  (locked atomic file writes) · ROADMAP item 7.4 (in flight; independent — see Consequences)

> **Intake provenance.** This RFC generalizes patterns surveyed in two production PHP
> applications from the library's target estate — a web console and the database-facing API
> service it calls, together ~150 hand-authored classes plus hand-vendored third-party
> libraries (a logging library, a spreadsheet library, PSR interface sets). The survey
> material is **private, is not committed to this repository, and never will be**; every
> observation below is aggregated and anonymized — counts and shapes, no identifiers, no
> schema or host names, no domain vocabulary. The counts are reproducible by the maintainer
> against the private tree, not by CI; they are evidence of scale, load-bearing for
> prioritization only.

## Context

The two surveyed applications are disciplined in shape — a constant layering (controller →
service interface/implementation → data access → query factory → DTO/builder → enum) that
both apps follow recognizably — and ad hoc in mechanism. Every cross-cutting concern is
solved locally, and the failure modes are the exact ones spec §1 names as the library's
reason to exist. Measured (`certain` — counted by grep over the private tree, 2026-08-05):

| Observation (aggregated) | Count |
|---|---|
| SQL built by string-interpolating request-derived values inside query-factory classes | 199 interpolation sites |
| Bound parameters passed to any statement execution in the DB-side app | **0** (44 bare `execute()` calls) |
| `catch (Throwable)` blocks that swallow the failure and return `[]` / `false` / `-1` | 74 (DB-side app alone) |
| Manual `beginTransaction`/`commit`/`rollBack` sites (begin outside `try`, unguarded rollback) | 53 |
| Per-level logger factory calls — eight static logger properties per service class | 160 across 20 services |
| Hand-built call-site trace lines (`__NAMESPACE__` / `__CLASS__` / `__FUNCTION__`) | 236 |
| Response-envelope constructions, across **three** coexisting envelope implementations | 232+ |
| Classes hard-wiring collaborators via `new *Impl()` (no DI seam) | 27 |
| Copies of one row-cleanup pipeline (legacy-charset transcode + trim + empty→null) | 17 |
| Front-controller `index.php` copies deployed folder-per-endpoint | 37 |

Beyond the counts: an AES-CBC cipher helper with **no authentication tag** whose decrypt
returns `bool|string`; an HTTP helper that **rewrites every URL to `http://`** and reads
with no timeout; `mail()` configured through global `ini_set()` with no header-injection
defense; a file-based daily ID counter duplicated per deploy folder with a hand-checked
INT4 cap; `date_default_timezone_set()` called as a side effect inside query factories.

Two forces make this an RFC now rather than opportunistic items later. First, the spec's
own objective — "replacing ad-hoc per-project solutions" — now has its concrete case: the
estate these helpers were written for. Second, the estate is being modernized
incrementally; without library counterparts, each fix gets re-solved per application (a
third envelope implementation already exists), and the window in which one shared answer
is cheaper than N local ones is closing.

Constraints carried over unchanged from RFC-0001: **no third-party implementation
dependencies in the core** (NFR-08), PHP 8.1 floor, the deptrac dependency rule, the
enterprise governance posture (every security-relevant decision lands as an ADR).

## Decision

Extend the library with **two new groups — `Persistence` and `Mail` — and targeted
additions to `Support`, `Database`, `Http`, `Security`, and `Errors`**. The intake's layer
vocabulary (query / DAO / CRUD / DTO / builder / service / controller) is answered with
**contracts and mechanics, never per-entity code generation**: the library supplies the
machinery a layer repeats, the application keeps its thin per-entity classes.

### The per-layer mapping (`systemdesign`)

| Legacy layer | Library counterpart | Status |
|---|---|---|
| Query-factory classes | `Database\SqlStatement` (FR-33, new) for hand-written SQL; existing `QueryBuilder` for dynamic composition | new |
| DAO classes | `Persistence\Repository` (FR-34, new) — fetch/execute/transaction mechanics over `DatabaseConnection` + `Transaction`, rows hydrated by the existing `Hydrator` | new |
| CRUD implementations | `Persistence\TableGateway` (FR-35, new) — *Table Data Gateway*: select/insert/update/delete composed on `QueryBuilder`, injection-proof by construction | new |
| DTOs (and entity-style objects) | `Dto` group as shipped in M3 — `DataTransferObject`, strict/lenient hydration, `WithersTrait`, `Collection<T>`. The intake shows **no distinct entity-class family**; an "entity" here is a DTO with identity, served by `TableGateway` + readonly DTO. If review wants a named split, it is a naming decision, not a new mechanism | covered |
| Builders (fluent, one per DTO) | **superseded** — hydration from arrays plus withers replaces the 25-field builder/getter pairs; deliberately not replicated (Alternatives #3) | superseded |
| Services | `Container`/`ServiceProvider` (M6) end the 27 `new *Impl()` sites; `Result` (M6) ends boolean returns. No new class — a worked target example lands in `docs/patterns/` | covered |
| Controllers / folder-per-endpoint front controllers | `Http\Router` (FR-38) + `Http\ApiEnvelope` (FR-39) + existing `Request`/`Response`; a ~20-line endpoint-kernel example in `docs/patterns/` replaces the 37 copied `index.php` files. Controllers themselves stay thin and app-side | new + covered |

### New components by group

**`Persistence` (new group).**
- **FR-34 `Repository`** — the abstract data-access base: `fetchAll(SqlStatement $q, string $dtoClass): array`, `fetchOne(...): ?T`, `execute(SqlStatement $q): int`, `withTransaction(Closure $fn): mixed` (delegating to `Transaction`, ADR-0016 semantics). Every failure throws `DatabaseException`; there is **no silent `[]`/`false`/`-1` path** — the intake's 74 swallowed catches are the anti-requirement.
- **FR-35 `TableGateway`** — Table Data Gateway over one table: constructor takes the connection, the table identifier, the DTO class, and the key column(s); operations compose exclusively through `QueryBuilder`, so identifiers pass the ADR-0015 allowlist and values always bind. Replaces per-entity CRUD boilerplate; per-entity *contracts* remain app-side interfaces.
- **FR-36 `RowNormalizer`** — the 17-times-copied row cleanup as one explicit policy object: charset transcode (strict by default; lossy is an opt-in flag, because silently dropping bytes is data loss — the same honesty rule as ADR-0019's substitution behavior), trim, configurable empty-string→`null`. Applied by `Repository` between fetch and hydration.
- **Placement note P-1 (layering).** `Persistence` needs the edges `Persistence→Database` and `Persistence→Dto` (plus `Support`). RFC-0001's rule — *groups depend downward on Support only* — is deliberately extended by **two named, allowlisted edges**, recorded in an ADR at implementation and proved by planted violations, exactly as ADR-0021 did for the `HtmlSanitizer` layer. Alternatives to the edge (gateway returns raw arrays; caller-injected hydration closure) are recorded in Alternatives #12.

**`Database` addition.**
- **FR-33 `SqlStatement`** — an immutable value of SQL text plus its bound parameters. Hand-written SQL exists legitimately (the surveyed estate uses dialect syntax — e.g. positional substring predicates — that `QueryBuilder` deliberately does not model); the defect was that its *values traveled interpolated*. `SqlStatement` makes text-plus-binds the only shape `Repository` will execute, so the safe path is the only path. Placement in `Database` adds no new deptrac edge.

**`Support` additions.**
- **FR-27 `Url`** — parse/normalize/build value object; refuses scheme downgrade on rebuild (the intake's helper force-rewrote every URL to `http://`); query composition without hand-concatenation.
- **FR-28 `Csv` + `Delimiter` enum** — streaming write/read with typed `FileException`/`CsvException` failures (never boolean), delimiter as an enum (ADR-0015's validated-string lesson), atomic write via `File` (ADR-0005). Optional `guardFormulas` flag, **default off** (Cross-cutting).
- **FR-29 `CsvSerializable`** — `csvHeader(): list<string>` / `csvRow(): list<scalar|null>`, the intake's interface generalized; `Dto` documentation shows the reflective default via `ClassMetadata`.
- **FR-30 `Lookup`** — immutable code→label map with an **explicit missing-key policy** (`label()` throws; `labelOr()`/`tryLabel()` for the tolerant cases). Replaces the estate's silent `"missing: X"` sentinel strings, which leak placeholder text into UI and reports.
- **FR-31 `Str` additions** — `collapseWhitespace()`, `nullIfBlank()`, `transcode()` (strict default, lossy opt-in), **multibyte-safe** `padLeft()`/`padRight()` (`str_pad` counts bytes, not characters), `shortClassName()`, `pascalCase()`.
- **FR-32 `FileSequence`** — rolling (e.g. daily) counter persisted to a lock-guarded state file with an explicit **cap policy** (`SequenceExhaustedException` at the cap, never a wrapped duplicate). Generalizes the estate's per-deploy-folder counter files; `File`'s flock discipline (ADR-0005) is the mechanism.

**`Http` additions.**
- **FR-37 `HttpClient`** — stream-context client (no `ext-curl`: the target estate demonstrably runs without it), JSON and raw bodies, **TLS verification on by default**, explicit connect/read timeouts (no unbounded reads), typed `HttpClientException extends HttpException` carrying status and a bounded body excerpt. Deliberately **not PSR-18**: PSR-18 requires PSR-7 objects, and this library's HTTP stance is native wrappers + optional bridge (RFC-0001 Alternative #3, item 7.4). Whatever 7.4 decides about bridge packaging, this client's shape is unaffected.
- **FR-38 `Router`** — minimal front-controller matcher: method + literal or `{param}` path → `callable`; distinguishes **404 (no path) from 405 (path, wrong method — with `Allow` set)**; parameters extracted as strings. Non-goals, stated: no middleware pipeline, no route caching, no attribute discovery (Alternatives #7).
- **FR-39 `ApiEnvelope`** — one readonly envelope value with a fixed JSON shape (`status`, `code`, `messages`, `data`) and named constructors for the outcome taxonomy the estate spread across three implementations: `ok / created / updated / deleted / empty / invalid / notFound / failed / caught`. Message *strings* are caller-supplied — localization catalogs stay app-side. The `Result`→`ApiEnvelope` mapping is a documented pattern, **not** a library method: `Http` importing `Errors` would breach the layering rule for one line of app glue.

**`Security` addition.**
- **FR-40 `Crypto` + `SecretKey`** — authenticated encryption, **AES-256-GCM**, versioned compact token (`v1.` + base64url(nonce‖ciphertext‖tag) — URL-safe, preserving the estate's use of encrypted URL parameters). `decrypt()` **throws `CryptoException`** on any failure — wrong key, tampered tag, malformed token; the intake's `bool|string` return is the anti-requirement. `SecretKey::generate()/fromBase64()` with `#[SensitiveParameter]` on every secret-bearing signature (native on PHP 8.2+; an inert, harmless attribute on the 8.1 floor — stated, not hidden). `ext-openssl` is **suggested, not required**: construction refuses with a clear message when absent, the ADR-0021/ADR-0022 fail-fast pattern. Replaces unauthenticated CBC (malleable ciphertexts; no integrity), a **security-relevant decision that carries its own ADR** at implementation.

**`Errors` additions.**
- **FR-41 `Level` enum + `LevelFilteredLogger`** — a backed enum mapping to PSR-3 level strings with an ordering (`includes()`), and a PSR-3 decorator that drops records below a floor.
- **FR-42 `MultiLogger` + `LoggerFactory`** — PSR-3 fan-out over N loggers, and a factory that builds a channel map (`name → target stream/file + floor + enabled`) from one config array. Together they collapse the estate's eight-static-loggers-per-class pattern (160 factory calls) into one injected `LoggerInterface` per class. **No Monolog dependency** — NFR-08's interface-only rule holds; anyone wanting Monolog wires it *behind* PSR-3.

**`Mail` (new group).**
- **FR-43 `EmailAddress` + `MailMessage`** — validated address value object; readonly message (from/to/cc/bcc/reply-to, subject, text and/or HTML). **Any CR/LF/NUL in a header-bound value is refused at construction** — ADR-0025's response-splitting stance applied to SMTP header injection; by the time a message exists, it is sendable.
- **FR-44 `Mailer` interface + `NativeMailer`** — transport contract plus a `mail()` implementation configured **explicitly through its constructor** (no global `ini_set()` mutation); failures throw `MailException`. Non-goal, stated: no SMTP client implementation (Alternatives #6b) — `Mailer` is the seam a symfony/mailer-backed adapter plugs into app-side.

### API contract (`api` / `systemdesign`)

- **Operations** — the FR-27…FR-44 surfaces above, under the existing namespace scheme
  (`D4np\Utils\Persistence\TableGateway`, `D4np\Utils\Mail\MailMessage`, …); signatures
  final at implementation, shapes fixed here.
- **Payloads** — readonly values throughout (`SqlStatement`, `ApiEnvelope`, `MailMessage`,
  `SecretKey`, `Lookup`); services consume and return DTOs/`Result` exactly as M3/M6 set up.
- **Error model** — one rule, inherited from the intake's strongest lesson: **no silent
  sentinel returns.** Every new failure path throws a typed exception in the existing
  hierarchy: `CsvException` (Support), `SequenceExhaustedException` (Support),
  `HttpClientException`, `RouteNotFoundException`, `MethodNotAllowedException` (Http, under
  `HttpException`), `CryptoException` (Security-local, ADR-0028 placement precedent),
  `MailException` (Mail-local), `DatabaseException` unchanged for Persistence.
- **Versioning** — additive pre-1.0 MINORs; on release each new group joins the
  BC-protected surface (namespace, signatures, exception shape) under the ADR-0031 gate.
  Nothing here changes an existing public signature.

### Data & schema (`database`) — omitted

Unchanged from RFC-0001: the library owns no persistent state. (`FileSequence` writes a
consumer-designated state file; its one-line format is documented and versioned with the
class, not a schema.)

### Scalability budgets (`scalability`)

Advisory budgets, firmed under the NFR-06/ADR-0030 harness when each lands:

| ID | Budget |
|---|---|
| NFR-09 | `Repository`/`TableGateway`: fetch + normalize + hydrate 100 rows ≤ 1.5× a hand-written PDO loop doing the same work |
| NFR-10 | `FileSequence::next()` ≤ 200 µs on local disk, lock included |
| NFR-11 | `Router` dispatch ≤ 5 µs against a 50-route table; `ApiEnvelope` construction ≤ 2 µs |
| NFR-12 | `Csv`: 10 000 × 10 write ≤ 150 ms, memory O(row) (streaming, never a full-table buffer) |
| NFR-13 | `Crypto`: 1 KiB encrypt+decrypt round-trip ≤ 60 µs |
| NFR-14 | Logger fan-out: a level-suppressed record costs ≤ 0.5 µs |

`HttpClient` and `NativeMailer` carry **no latency budget, deliberately** — both are
network/MTA-dominated, and a wall-clock number would measure the wire, not the library
(NFR-05's rationale, applied). Their budgets are correctness suites (T-07, T-10).

### Algorithm sketch (`pseudocode`)

The one non-obvious pipeline — `Repository::fetchAll`, where the intake's three duplicated
steps (fetch loop, cleanup, manual builder mapping) become one path:

```
fetchAll(stmt, dtoClass):
    rows ← connection.run(stmt.sql, stmt.params)     # binds only; no interpolation exists
    for row in rows:
        row ← normalizer.apply(row)                  # transcode/trim/null policy, one place
        yield hydrator.hydrate(dtoClass, row)        # ADR-0008/0013 machinery, cache shared
    # any driver failure → DatabaseException; no path returns a sentinel
```

and `FileSequence::next()` — the correctness of which is the lock, not the arithmetic:

```
next():
    acquire exclusive flock on state file            # ADR-0005 discipline
    (window, n) ← parse(state) or (current, 0)
    if window ≠ current: n ← 0                       # rolling reset
    n ← n + 1
    if n > cap: release; throw SequenceExhausted     # never wrap silently
    write "window|n" atomically; release; return n
```

### Cross-cutting

**Security** (each lands with its own ADR under the enterprise posture):
- **Persistence closes an interpolation class**: with `SqlStatement` + `TableGateway`, the
  library-side persistence path has *no API that accepts SQL with inlined values*; T-13
  re-runs ADR-0017's 29-payload corpus through the gateway paths and asserts
  placeholder-only text at the PDO boundary via the existing `QueryLog` fixture.
- **AEAD, not CBC**: tampering is detected, not decrypted; version prefix enables rotation.
- **TLS by default + scheme-downgrade refusal** in `HttpClient`/`Url`.
- **Header-injection refused at construction** in `Mail` (and already in `Response`).
- **CSV formula guard is opt-in, default off**: neutralizing `= + - @` prefixes mutates
  data — the spec §1 input-mutilation lesson; the guard exists because exports do get
  opened in spreadsheets, but silently altering round-tripped data is the worse default.
  The flag's two states are both tested (T-08).
- `#[SensitiveParameter]` on secret-bearing signatures; inert on 8.1, effective on 8.2+.

**Performance** — the NFR table; benches join the ADR-0030 same-runner harness.

**Quality gates** — unchanged and apply in full (matrix, PHPStan max, ≥90% coverage,
deptrac with the new named edges, mutation on touched security namespaces). New suites:
T-07 `HttpClient` against a live `php -S` (T-03's process discipline); T-08 Csv
property/round-trip + formula-guard corpus; T-09 Crypto vectors (tamper, wrong key, nonce
uniqueness across 10⁵ tokens); T-10 mail header-injection corpus; T-11 router matrix
(404/405/`Allow`/params); T-12 logger routing matrix; T-13 gateway injection suite;
T-14 `FileSequence` under concurrent processes (no duplicates, cap enforced); T-15
`RowNormalizer` policy table.

## Alternatives

1. **Doctrine DBAL / an ORM** for the persistence layer — rejected: framework-sized
   implementation dependency (NFR-08); the target estate needs safe mechanics under its
   existing SQL, not an abstraction migration. `QueryBuilder` + `SqlStatement` +
   `TableGateway` cover the surveyed access patterns.
2. **Guzzle / PSR-18 client** — rejected: PSR-18 requires PSR-7 payloads, forcing the
   bridge (7.4) into every consumer; `ext-curl` is absent from part of the estate. The
   native stream client mirrors the wrappers' philosophy; a PSR-18 adapter remains possible
   app-side.
3. **Replicate the estate's per-DTO fluent builders** — rejected: 25 nullable fields ×
   getter+wither pairs per class is the boilerplate hydration was built to delete;
   `fromArray()` + named arguments + `WithersTrait` express the same construction with
   compile-checked completeness (R-4 missing-key semantics).
4. **A per-entity code generator** (generate DAO/CRUD/DTO triples) — rejected: generated
   code is owned code; the estate's copy-paste triplets *are* hand-run generation and their
   drift is the evidence. Mechanics in the library, thin contracts in the app.
5. **Depend on Monolog** for channels — rejected: NFR-08 interface-only rule; the factory
   builds on the library's own PSR-3 `Logger`, and Monolog remains pluggable behind the
   same interface.
6. **(a) defuse/php-encryption** — mature, but an implementation dependency in the core;
   rejected on NFR-08, recorded as the recommended app-side alternative where policy allows
   dependencies. **(b) libsodium (`XChaCha20-Poly1305`)** — bundled since PHP 7.2 but
   disableable and absent from part of the target estate's builds, while `ext-openssl` is
   demonstrably present there (the current cipher helper uses it). AES-256-GCM over
   OpenSSL chosen; revisit trigger recorded: if the estate's builds standardize on sodium,
   a `v2.` token version can switch primitives without breaking `v1.` decryption.
7. **A fuller router** (middleware/PSR-15, route caching, attribute discovery) — rejected:
   PSR-15 requires PSR-7 (the bridge is the sanctioned crossing, RFC-0001 Alt. #3); caching
   optimizes a 37-route worst case that measures in microseconds (NFR-11 keeps it honest).
8. **CSV formula-guard default ON** — rejected: silent data mutation as a default is the
   `magic_quotes` shape again (spec §1); opt-in with documentation.
9. **A `Config` component** — **deferred, not rejected**: the estate's constants-class +
   `Env` (FR-24) cover current need; re-open on evidence of layered/environment config
   drift. Recorded so the next surveyor does not re-derive it.
10. **Domain helpers from the intake's util classes** (fixed-position code parsing,
    plant-dialect format conversions) — rejected: domain-specific by construction; they
    stay in the applications. Only their generic substrate (`Str` additions,
    `RowNormalizer`) generalizes.
11. **A generic "remote envelope protocol" client** (typed reader for the estate's
    internal `label`/`body` response protocol) — rejected: the protocol is estate-private;
    `HttpClient` + a thin app-side adapter covers it without freezing a private wire shape
    into a public library.
12. **Layering-pure Persistence** (gateway returns raw arrays, or takes a caller-supplied
    hydration closure) — rejected as the default: it re-opens the gap the group exists to
    close (every caller re-writes the normalize+hydrate loop). The two named deptrac edges,
    ADR-recorded and violation-proved, are the smaller honesty cost. The closure-injection
    variant is kept as the documented escape hatch for exotic mappings.

## Consequences

**Easier:** the surveyed estate can migrate strangler-style, one layer at a time, each step
independently shippable — `Support` utilities first (pure additions), then `Persistence`
under the existing SQL (ending value interpolation without a rewrite), then
`Router`/`ApiEnvelope` collapsing the per-endpoint folders, then `Crypto`/`Mail` swaps.
Every mechanism arrives tested to this repo's bar — the estate's 74 silent catches and 199
interpolation sites become library-impossible shapes.

**Harder / accepted costs:** two more groups to hold at the quality bar; the layering rule
gains its first two cross-group edges (contained: named, ADR-recorded, deptrac-proved);
`Persistence` couples to `Dto`'s hydration surface, so hydration BC changes now have a
second in-repo consumer; `Mail` ships a deliberately modest transport and will be asked
why it is not an SMTP client (the ADR answers).

**Follow-up (for the `plan` phase, after approval):**
- Amend spec to r3: FR-27…FR-44, NFR-09…NFR-14, suites T-07…T-15 (same-PR spec amendments
  per §7 discipline, item 6.3 precedent).
- Roadmap: advisory grouping into three-to-four milestones — *Support & values*,
  *Persistence*, *Http application layer*, *Security & channels (Crypto, logging, Mail)* —
  **numbers assigned by the plan phase** after the in-flight bridge milestone (item 7.4's
  outcome) takes its slot. Security-tagged items route frontier per `os/routing`.
- ADRs at implementation: Persistence layering edges; SqlStatement-only execution;
  AEAD/`Crypto`; `Mail` header-injection refusal; `HttpClient` TLS/scheme policy; CSV
  formula-guard default. Patterns catalogue: *Table Data Gateway* (adopt), *Front
  Controller* (adopt, via `Router`), with the §8 ADR+code discipline.
- **Governance note (recorded, not resolved here):** the manifest sits at
  `delivery_state.phase: scaffold`, whose only legal transition today is `→ audit`; the
  phase model has no design re-entry edge. This RFC is therefore a documentation artifact
  under AGENTS.md §7's "a genuinely new capability is planned first", and the maintainer
  routes the formal phase (audit first, or a recorded ledger addendum) — no manifest write
  happens in this PR.

## Approval

Filled by the approver **after** review — pending the maintainer's decision; deliberately
absent from this draft (`rfc_check` is expected red on exactly this field until then):

```
approved-by:
```

Reviewers (structured findings addressed): reviewer — ▢ · enterprise-architect — ▢.

## References

- [RFC-0001](0001-egl-utils-library.md) — foundation design; the constraints inherited here
- [`docs/specs/01_spec_utils.md`](../specs/01_spec_utils.md) — the frozen spec this RFC extends toward r3
- ADR-0005, ADR-0015, ADR-0016, ADR-0017, ADR-0019, ADR-0021, ADR-0022, ADR-0025, ADR-0028,
  ADR-0030, ADR-0031 — the precedents each new mechanism leans on
- OWASP Cheat Sheet Series: *CSV Injection*, *HTTP Header Injection* (public references for
  T-08/T-10 corpora)
- PSR-3 (logging), PSR-11 (container) — the interface-only dependencies unchanged by this RFC
