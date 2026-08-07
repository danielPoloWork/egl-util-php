# Software Specification: EGL PHP Utility Library (PHP 8.1+)

> Rendered from the intake interview (Phase 5). Frozen contract: diverging implementation
> updates this spec in the same PR or adds an ADR superseding the relevant section.

**Revision r14** — 2026-08-07. See [Revision history](#revision-history).

## 1. Objective & Business Context

Provide EGL PHP projects (framework-based and native/legacy) with a modern utilities library — Composer package egl/utils, PSR-4 namespace D4np\Utils\, PHP 8.1+ — offering typed readonly DTOs, explicit-mechanism security helpers, safe PDO access, hardened session/CSRF handling, and a minimal PSR-11 DI container, replacing ad-hoc per-project solutions (associative-array DTOs, blocklist sanitizers, string-built SQL, silent PDO error modes).

## 2. Functional Requirements

- FR-01 DataTransferObject: hydrate typed readonly DTOs from arrays via reflection with per-class metadata caching; strict mode default (unknown key -> UnknownKeyException), lenient() opt-in; missing non-nullable key -> MissingKeyException (RFC-0001 R-4 addition); nested DTOs and Collection<T> hydrate recursively; type mismatch -> TypeMismatchException naming the path
- FR-02 WithersTrait: with(...) wither semantics producing modified clones of readonly DTOs, absorbing the PHP 8.1->8.3 readonly-clone difference per version
- FR-03 Collection<T>: functional array wrapper (map/filter/reduce); genericity static-analysis-level only (@template + PHPStan max); optional runtime instanceof guard flag
- FR-04 Container: minimal PSR-11 container — constructor autowiring (cached reflection), singletons/factories, no compilation; non-goals per imported ADR-001 (no attributes, no lazy proxies, circular deps throw with path)
- FR-05 ServiceProvider: abstract base for registering container definitions
- FR-06 DatabaseConnection: PDO wrapper with pinned safe defaults — ERRMODE_EXCEPTION, utf8mb4 on MySQL, real prepares (EMULATE_PREPARES=false), FETCH_ASSOC
- FR-07 QueryBuilder: fluent SQL builder — values always bound as parameters; identifiers allowlisted (^[A-Za-z_][A-Za-z0-9_]*$) and driver-quoted; ORDER BY direction is an enum; LIMIT/OFFSET cast to non-negative int
- FR-08 Transaction: closure-scoped transactions — automatic rollback on any exception and rethrow; nested calls use savepoints
- FR-09 Escaper: context-aware output escaping — html() (ENT_QUOTES|ENT_SUBSTITUTE, UTF-8), attr(), js() (hex), url() (rawurlencode)
- FR-09b Sanitizer::richText(): user-authored HTML delegated to symfony/html-sanitizer (optional dependency) with a curated allowlist profile; no hand-rolled tag stripper
- FR-10 Sanitizer::sqlLikePattern(): escape %/_/escape-char in user input used inside LIKE patterns (wildcard-injection defense; values still bind as parameters)
- FR-11 Hash: password_hash wrapper, Argon2id default; availability via defined('PASSWORD_ARGON2ID'); bcryptFallback: true default logged at WARNING or false to fail fast; self-describing hashes; needsRehash() upgrades on login
- FR-12 CsrfToken: CSPRNG token generation + constant-time validation (hash_equals), per-session storage, optional per-form scoping (lives in the Http group per RFC-0001 R-1 placement note)
- FR-13 Request: typed superglobal reader ($_GET/$_POST/$_SERVER/$_FILES); optional PSR-7 bridge per imported ADR-002
- FR-14 Response: headers/JSON/status helpers; same bridge
- FR-15 Session: secure session API — cookie_httponly, cookie_secure, cookie_samesite=Lax at start; regenerate() wraps session_regenerate_id(true) for login transitions
- FR-16 Result: success/failure wrapper for application services (map/flatMap/orElseThrow)
- FR-17 Logger: PSR-3 file/console logger
- FR-18 ExceptionHandler: fatal-error capture, JSON problem responses for API calls; never leaks traces in production mode (env-gated)
- FR-19..21 Str: slug() (transliteration via ext-intl when present), uuid() (v4 from random_bytes), random() (CSPRNG alphanumeric tokens)
- FR-22..23 File: write()/read() flock-guarded, atomic write via temp+rename; writeStream() streams to the temp file under the same discipline, for producers whose output must not be buffered (r5, spec NFR-12); update() performs a read-modify-write under ONE exclusive lock, which read()+write() cannot express (r6, ADR-0038); mime() via Fileinfo (never trusts extensions)
- FR-24 Env::get(): env reads with correct boolean coercion ('false' -> false)
- FR-25 Json: encode()/decode() with JSON_THROW_ON_ERROR; library JsonException wraps and rethrows PHP's native \JsonException (RFC-0001 R-7)
- FR-26 Exception hierarchy: UtilsException <- DatabaseException, HydrationException (<- UnknownKeyException, TypeMismatchException, MissingKeyException), HttpException, FileException, JsonException

**r3 addendum (RFC-0002)** — normative detail in
[RFC-0002](../rfc/0002-application-layer-groups-from-legacy-intake.md); one error-model rule
spans all of it: **no silent sentinel returns** — every new failure path throws a typed
exception in the FR-26 hierarchy (new: InvalidUrlException, CsvException [added r5],
SequenceExhaustedException,
HttpClientException, RouteNotFoundException, MethodNotAllowedException, CryptoException,
MailException).

- FR-27 Url: parse/normalize/build value object; absolute URLs only; refuses C0/DEL control characters up front (parse_url() rewrites them to "_" rather than rejecting them — ADR-0036); refuses a scheme downgrade (https->http, wss->ws, ftps/sftp->ftp; an unknown target scheme is allowed through — recorded limit); query preserved byte-exact until edited, composed per RFC 3986, null values refused at any depth
- FR-28 Csv + Delimiter enum: streaming write/read (memory O(row)), typed failures (never boolean), atomic write via File::writeStream(); PHP's non-RFC-4180 backslash escape DISABLED on every call — the default corrupts any field ending in a backslash (ADR-0037); a single empty field is written as "" (fputcsv emits a bare newline, which is lost on read) and a zero-column row is refused; blank lines skipped on read, a quoted empty field is not; formula-guard opt-in (default off — input-mutilation lesson, spec §1), leaders = OWASP's `=` `+` `-` `@` plus tab and CR
- FR-29 CsvSerializable: csvHeader()/csvRow() contract, with the pairing ENFORCED — Csv::write() takes the header from the first item and refuses any row whose width disagrees (ADR-0037)
- FR-30 Lookup: immutable code→label map; label() throws on a missing key, labelOr()/tryLabel() tolerant
- FR-31 Str additions: collapseWhitespace(), nullIfBlank(), transcode() (strict default, lossy opt-in), multibyte-safe padLeft()/padRight(), shortClassName(), pascalCase()
- FR-32 FileSequence: rolling counter with an explicit cap (SequenceExhaustedException); never wraps silently. The whole read-modify-write runs under ONE exclusive lock via File::update() — read()+write() as two separately locked calls loses an increment, which for a sequence is a duplicate identifier (ADR-0038). A corrupt state file is refused, not reset (resetting re-issues the whole window) and is left on disk as evidence; an absent or blank file is a legitimate fresh start. The window is a caller-supplied opaque string (no timezone decision inside the library); recorded limit: windows cannot be ordered, so ANY window change resets — callers must supply a monotonically advancing window. peek()/remaining() are advisory and unlocked.
- FR-33 SqlStatement (Database): immutable SQL+params value — the only shape connection-side execution accepts; hand-written dialect SQL always travels with its binds. Enforced by TYPE, not by convention (r8, ADR-0041): private constructor, three named entry points — literal() taking a `literal-string` (PHPStan max refuses interpolated or concatenated text, and still permits hand-written dialect SQL with placeholders), fromQueryBuilder() taking the builder object so no SQL text crosses as a bare argument, and composed() as the single named escape hatch for runtime-assembled text, with zero in-library uses so `grep composed(` is the review list
- FR-34 Repository (Persistence): abstract base — fetchAll/fetchOne/execute/withTransaction; rows normalized (FR-36) then hydrated via the shared Hydrator; every failure throws DatabaseException. Realized (r10, ADR-0043) with NO try/catch anywhere in the class: the requirement is satisfied by omission, since DatabaseConnection, the hydrator and RowNormalizer each raise a typed failure that must propagate untouched — asserted directly against the class's own source, because an absence is what a suite loses unnoticed. Hydration stays STRICT (ADR-0008), so a projected column the DTO does not declare raises and SELECT * into a typed DTO fails by design; hydrate() is a protected, non-final seam for a subclass wanting lenient. Normalization is OPT-IN (?RowNormalizer = null — the question ADR-0042 deferred): without one, rows hydrate exactly as the driver returned them. execute() returns the affected-row COUNT, never a boolean: 0 is a fact only the caller can interpret. withTransaction() delegates to Transaction (ADR-0016) and passes the repository to the closure
- FR-35 TableGateway (Persistence): Table Data Gateway over one table — find/all/findBy/findOneBy/insert/update/updateBy/delete/deleteBy, with identifiers allowlisted and values bound by construction. **Corrected (r11, ADR-0044): "over QueryBuilder" was not implementable — QueryBuilder is SELECT-only and always was.** Reads compose through QueryBuilder; writes through the new FR-33b MutationBuilder; both share one Identifier, so there is one allowlist rather than two copies. Extends Repository (FR-34), inheriting hydration, opt-in normalization, transactions and the no-catch property. Three behaviours are normative: empty criteria are REFUSED on every filtered operation (an empty array is what an unvalidated request filter collapses to; all() is the named whole-table read), reads PROJECT THE DTO rather than SELECT * (strict hydration, ADR-0008), and the table name is allowlisted at CONSTRUCTION as well as per statement (fail fast at wiring time, ADR-0022). Non-goals: no JOIN, aggregates, upsert, identity map, change tracking or lazy loading — RFC-0002 Alternative #1
- FR-33b Identifier + MutationBuilder (Database, r11, ADR-0044): the FR-07 allowlist and per-driver quoting extracted into one class both builders share — a copied allowlist is one edit from two rules, and the weaker decides — plus INSERT/UPDATE/DELETE composition as named constructors, values bound, `null` criteria rendering IS NULL, and UNQUALIFIED WRITES REFUSED (an UPDATE or DELETE with empty criteria applies to every row). Enters SqlStatement through a fourth named door, fromMutation(), taking the builder object; composed() keeps its zero in-library uses and therefore keeps its meaning as the review list (ADR-0041)
- FR-36 RowNormalizer (Persistence): strict/lossy charset transcode + trim + empty→null as one explicit, testable policy object. Defaults are asymmetric by decision (r9, ADR-0042): trim ON (fixed-width CHAR padding is a storage artifact), transcode/collapseWhitespace/blankToNull OFF (each changes data, and spec §1 forbids a library rewriting a consumer's values unasked). Step order is FIXED, not configurable: transcode first (trimming bytes not yet in the target encoding is only safe for an ASCII-compatible source), then trim/collapse, then blank→null last so it judges the value the earlier steps produced. Strict by default: an unconvertible value raises DatabaseException naming the COLUMN; $lossy = true opts into dropping. Keys and non-string values (int/float/bool/null, BLOB resources) are never touched
- FR-37 HttpClient: stream-context transport (no ext-curl), JSON/raw bodies, TLS options stated explicitly in every context (never inherited from the process default), a per-phase timeout AND a wall-clock ceiling on the whole exchange, redirects off by default, http/https only, outbound header injection refused, HttpClientException for a request that produces no response (any status the origin produced is returned as an HttpResponse); deliberately not PSR-18 (native wrappers + optional bridge, RFC-0001 Alt. #3). **Revised at r14 (item 11.1, ADR-0049)** — r3 said "explicit connect/read timeouts"; PHP's http wrapper exposes one `timeout` knob that covers connect *and* each read, re-arming per phase, so separate values are not available and a per-phase value alone does not bound a request. The two limits above are what the wrapper can actually deliver
- FR-38 Router: method+path matching with {param} extraction; 404 vs 405 with Allow; callable handlers; non-goals: middleware, route caching, attribute discovery
- FR-39 ApiEnvelope: readonly envelope (status, code, messages, data) with outcome constructors (ok/created/updated/deleted/empty/invalid/notFound/failed/caught); message strings caller-supplied — localization stays app-side
- FR-40 Crypto + SecretKey: AES-256-GCM, versioned v1. base64url compact token; decrypt() throws CryptoException on any failure; ext-openssl suggested with constructor refusal when absent; #[SensitiveParameter] on secret-bearing signatures (inert on the 8.1 floor, effective 8.2+)
- FR-41 Level + LevelFilteredLogger: backed enum mapping to PSR-3 level strings with ordering; min-level PSR-3 decorator
- FR-42 MultiLogger + LoggerFactory: PSR-3 fan-out over N loggers; one config array → channel map; no Monolog dependency (NFR-08)
- FR-43 EmailAddress + MailMessage: validated address value object; readonly message; CR/LF/NUL in any header-bound value refused at construction (SMTP header-injection defense)
- FR-44 Mailer + NativeMailer: transport interface + mail() implementation configured explicitly via constructor (no global ini_set); failures throw MailException; non-goal: an SMTP client implementation


## 3. Non-Functional Requirements

<!-- Scalability / load budgets belong here as NUMBERS, not adjectives (the design "scalability"
     fold): a value per hard NFR axis — throughput / concurrency, p99 latency, memory ceiling,
     target FPS, cold-start budget — each phrased so CI could prove a violation. -->
- NFR-01 DTO hydration (10 scalar props): <= 5 us/DTO warm (cached reflection) and <= 3x manual constructor assignment
- NFR-02 Container: singleton resolve <= 2 us warm; first autowired resolve <= 30 us
- NFR-03 QueryBuilder: 5-condition SELECT builds in <= 10 us; 0 queries executed at build time
- NFR-04 Memory: hydrating 10 000 DTOs <= 16 MB peak delta
- NFR-05 Hash::make (Argon2id defaults): 50-200 ms on the reference machine (deliberately slow; documented for capacity planning)
- NFR-06 Benchmark methodology: phpbench, 10 iterations x 100 revs, 5% retry threshold, PHP 8.3 CLI with OPcache+JIT off, reference machine Ryzen 7 5800X, harness in bench/, nightly CI; regression > 10% fails
- NFR-07 Quality gates: PHPUnit line coverage >= 90%; Infection mutation score >= 70% on Security/Database/Dto namespaces; PHPStan max level; deptrac layer rules; composer-normalize; composer audit; roave/backward-compatibility-check on release PRs
- NFR-08 Dependency policy: no third-party implementation dependencies in the core (php>=8.1 + ext-pdo + ext-fileinfo; interface-only psr/container and psr/log excepted — RFC-0001 R-3 correction); symfony/html-sanitizer and the PSR-7 bridge are optional

**r3 addendum (RFC-0002)** — advisory until first measured under the NFR-06/ADR-0030 harness:

- NFR-09 Repository/TableGateway: fetch + normalize + hydrate 100 rows of a 4-column row DTO <= 2.5x a hand-written PDO loop doing the same work. **Revised from 1.5x at r13 (item 10.10, ADR-0046): the original figure was unsatisfiable without violating NFR-01.** It demanded hydration cost <= 1.91x manual construction, where NFR-01 permits 3x and the measured, already-optimized figure is 2.40x (item 7.1, ADR-0013). The row width is now part of the requirement because the ratio depends on it — a narrower row amortizes the hydrator's fixed per-call dispatch over less work, so a 4-column row sits at a harder point of the curve than NFR-01's own 10-property subject
- NFR-10 FileSequence::next() <= 200 us on local disk, lock included
- NFR-11 Router dispatch <= 5 us against a 50-route table; ApiEnvelope construction <= 2 us
- NFR-12 Csv: 10 000 x 10 write <= 150 ms, memory O(row) (streaming, never a full-table buffer)
- NFR-13 Crypto: 1 KiB encrypt+decrypt round-trip <= 60 us
- NFR-14 Logger fan-out: a level-suppressed record costs <= 0.5 us
- Declared unbudgeted (NFR-05's rationale): HttpClient and NativeMailer latency — network/MTA-dominated; their budgets are the T-07/T-10 correctness suites


## 4. Logical Architecture & Core Algorithm

<!-- For a non-obvious core algorithm, include a short LANGUAGE-FREE pseudocode sketch (control
     flow + invariants) alongside the prose + diagram (the design "pseudocode" fold); skip it when
     the approach is standard. If the design owns persistent state, capture the data model here —
     entities, relations, normal form, migration policy — within ADR-0004's secondary-SQL frame. -->
Six component groups over a Support layer (C4 component view, spec s3), rule: groups depend
downward on Support only, no cross-group imports — enforced by deptrac in CI.
Groups (PSR-4 sub-namespaces of D4np\Utils\): Dto (DataTransferObject, WithersTrait,
Collection<T>), Container (PSR-11 Container, ServiceProvider), Database (DatabaseConnection,
QueryBuilder, Transaction), Security (Escaper, Sanitizer, Hash), Http (Request, Response,
Session, CsrfToken — placement per RFC-0001 R-1: CsrfToken needs per-session storage),
Errors (Result, Logger, ExceptionHandler), Support (Str, File, Env, Json, exception
hierarchy, shared reflection-metadata cache — the hydrator and the Container both consume it;
RFC-0001 R-2). Source tree: PSR-4 base dir is the rendered src/main/php/d4np/utils/ and
deptrac layer globs are defined against that exact tree (RFC-0001 A-9). Mixed-vendor naming
(package egl/utils, namespace D4np\Utils\) is a recorded maintainer decision (RFC-0001
Alternatives #5) and must be stated in composer.json and the README.

**r3 (RFC-0002):** two groups join the component view — **Persistence** (`Repository`,
`TableGateway`, `RowNormalizer`, consuming `Database\SqlStatement`) and **Mail**
(Support-only edge) — plus additions inside Support/Database/Http/Security/Errors. The
dependency rule gains its **first two named cross-group edges**, `Persistence→Database` and
`Persistence→Dto`, allowlisted in deptrac, proved by planted violations, and recorded by ADR
at implementation (RFC-0002 P-1; ADR-0021's named-layer precedent). The `Result`→`ApiEnvelope`
mapping is deliberately app-side glue: an `Http→Errors` import would breach the rule.

## 5. Public Interface

<!-- The API contract (the design "api" fold): each operation with its payload shapes, the error
     model (the failure taxonomy, not just the happy path), and the versioning / SemVer surface.
     A service/web project may keep the written-out contract under docs/api/ (capabilities.api_spec). -->
Consumers import via `use D4np\Utils\Dto\DataTransferObject;`. The public surface:

- D4np\Utils\Dto\ — DataTransferObject::fromArray()/lenient(), WithersTrait::with(), Collection<T>
- D4np\Utils\Container\ — PSR-11 Container (get/has, autowire, singleton/factory), ServiceProvider
- D4np\Utils\Database\ — DatabaseConnection (pinned PDO defaults; `select`/`selectOne`/`execute` take a `SqlStatement`, r7 ADR-0039), QueryBuilder (fluent, Sort enum), Transaction::run(closure)
- D4np\Utils\Security\ — Escaper::html/attr/js/url, Sanitizer::richText/sqlLikePattern, Hash::make/verify/needsRehash
- D4np\Utils\Http\ — Request (typed superglobal reader), Response, Session::regenerate(), CsrfToken; PSR-7 via optional egl/utils-psr7-bridge
- D4np\Utils\Errors\ — Result::map/flatMap/orElseThrow, PSR-3 Logger, ExceptionHandler
- D4np\Utils\Support\ — Str::slug/uuid/random, File::write/read/mime, Env::get, Json::encode/decode, UtilsException hierarchy

r3 (RFC-0002) additions:

- D4np\Utils\Persistence\ — Repository (fetchAll/fetchOne/execute/withTransaction), TableGateway (find/all/findBy/findOneBy/insert/update/updateBy/delete/deleteBy, plus the protected query() seam), RowNormalizer
- D4np\Utils\Database\ — + Identifier (allowlist + driver quoting), MutationBuilder (insert/update/delete), SqlStatement::fromMutation() (r11)
- D4np\Utils\Mail\ — EmailAddress, MailMessage, Mailer, NativeMailer
- D4np\Utils\Database\ — + SqlStatement::literal()/fromQueryBuilder()/composed() (r7: the only shape `DatabaseConnection`'s query methods accept, ADR-0039; r8: private constructor, text constrained by `literal-string`, ADR-0041) · D4np\Utils\Http\ — + HttpClient, Router, ApiEnvelope · D4np\Utils\Security\ — + Crypto, SecretKey · D4np\Utils\Errors\ — + Level, LevelFilteredLogger, MultiLogger, LoggerFactory · D4np\Utils\Support\ — + Url, Csv, Delimiter, CsvSerializable, Lookup, FileSequence, SequenceExhaustedException, File::writeStream(), File::update(), Str additions (FR-31)


## 6. Verification & Test Strategy

Five suites (spec s7): T-01 DTO hydration matrix (nested, collections, nullables, enums, strict/lenient, withers, missing-key cases per RFC-0001 R-4); T-02 injection suite (fuzzed value payloads reach the driver only as bound parameters via query-log assertion; identifier injection throws DatabaseException; LIKE-wildcard escapes); T-03 session/CSRF integration against a real php -S process (cookie flags HttpOnly/Secure/SameSite, session id changes across regenerate() and the pre-rotation identifier ceases to resolve, constant-time comparison verified by mechanism assertion per ADR-0026 §7 — positively, that `hash_equals()` is the comparator on every secret-comparison path, and negatively, that `==`, `===`, `strcmp()`, `strncmp()` and equivalents are absent from those paths — cross-session token rejection); T-04 transaction semantics (exception -> rollback -> rethrow; savepoint nesting); T-05 property tests (Json round-trips, Str::slug idempotence, Env boolean coercion table). Plus: OWASP XSS cheat-sheet corpus per Escaper context (snapshot suite); DOM-bypass corpus for richText(); Hash argon2id/bcrypt-fallback matrix; bridge conversion-fidelity contract tests in egl/utils-psr7-bridge CI (imported ADR-002). CI proves failure via the NFR-07 gate set.

**r3 (RFC-0002)** adds nine suites: T-07 HttpClient against a live `php -S` origin (T-03's
process discipline); T-08 Csv property/round-trip + formula-guard corpus, both flag states;
T-09 Crypto vectors — tamper, wrong key, truncation, nonce uniqueness across 10^5 tokens,
version-prefix handling; T-10 mail header-injection corpus; T-11 router matrix
(404/405/Allow/params); T-12 logger routing matrix; T-13 gateway/statement injection —
ADR-0017's 29-payload corpus re-run through Repository/TableGateway with the
placeholder-only PDO-boundary assertion; T-14 FileSequence under concurrent processes (no
duplicates, cap enforced); T-15 RowNormalizer policy table.

**T-13, as delivered (r12, item 10.5)** — two legs, not one. The **value leg** is the corpus
above through every value-accepting gateway path (`insert`, `find`, `findBy`, `findOneBy`,
`update`, `updateBy`, `deleteBy`, `MutationBuilder` directly, `Repository::fetchAll` with
hand-written SQL, inside `withTransaction`, and with a `RowNormalizer` configured), plus the
consequence assertions a boundary check cannot make: the payload round-trips through hydration
intact, a tautology criterion matches and deletes nothing, and **the two parameter groups of an
`UPDATE` bind in order** (`SET` before `WHERE` — swapped, the statement still runs and writes the
criterion into the column, which no syntax-level assertion would see). The **identifier leg** runs
the hostile-identifier corpus over every surface where a column name can arrive from a caller —
criteria keys and value keys on each operation, plus the table name — and asserts not only the
refusal but that **nothing was prepared**: a refusal issued after the statement reached the driver
is a refusal that arrived too late, and a round-trip assertion cannot tell the two apart. Both
corpora live in one fixture shared by T-02, T-13 and the builder suites.

Toolchain: built with Composer (PSR-4 autoload), tested with PHPUnit (Pest optional), checked with
PHPStan max level (type soundness); PCOV for coverage, coverage target ≥ 90% line. Every functional and
non-functional requirement above maps to a CI gate (see [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml)).


## Revision history

| Rev | Date | Change |
|-----|------|--------|
| r1 | 2026-08-03 | Frozen from the imported `.specs/d4np-php.md` v2.0 via RFC-0001 (naming mapping A-7). |
| r2 | 2026-08-05 | §6/T-03: the `hash_equals` **timing test** is replaced by a **mechanism assertion**. Rationale below; see [ADR-0027](../adr/0027-constant-time-comparison-is-asserted-by-mechanism-not-by-timing.md). |
| r14 | 2026-08-07 | §2/FR-37: the transport requirement restated to what PHP's stream wrapper can deliver (item 11.1, **ADR-0049**). "Explicit connect/read timeouts" was not implementable — measured, the wrapper's single `timeout` covers connect *and* each read, re-arming every time bytes arrive, so it bounds no request on its own; FR-37 now asks for a per-phase timeout **and** a wall-clock ceiling. The same revision writes down what was implicit: TLS options stated rather than inherited (a fresh context carries none, and `stream_context_set_default()` decides them), redirects off by default, http/https only, and a response returned for every status the origin produced. |
| r13 | 2026-08-07 | §3: **NFR-09's budget revised, 1.5x → 2.5x**, and its row shape made explicit (item 10.10, maintainer's decision between this and re-opening hydration optimization). The original figure contradicted NFR-01 on the same axis: NFR-09's scope *contains* hydration, yet 1.5x demanded hydration at <= **1.91x** manual construction while NFR-01 permits **3x** and item 7.1 measured **2.40x** after item 3.7 spent a `standard/high` effort reaching it. Measured five times on CI at **1.71–1.85x**. Derivation, alternatives (2.0x rejected as reproducing the thin-margin mistake item 9.6 named; 3.0x rejected as only reachable if fetch were free) and the accepted cost are in [ADR-0046](../adr/0046-nfr09s-budget-contradicted-nfr01-on-the-same-axis.md). |
| r12 | 2026-08-06 | §6: **T-13 stated as delivered** (item 10.5) — the suite has an identifier leg the one-line description did not mention, asserting that a hostile column name is refused **before anything is prepared**, and consequence assertions the boundary check cannot make (round-trip through hydration, a tautology that matches nothing, and `SET`-before-`WHERE` parameter order). No requirement changed; the spec now describes what exists. No new ADR: the boundary decision is [ADR-0017](../adr/0017-prove-binding-at-the-pdo-boundary-and-defer-t02s-like-leg.md)'s and this applies it. |
| r11 | 2026-08-06 | §2/§5: **FR-35 corrected** — its "composed exclusively through QueryBuilder" (carried from RFC-0002) was not implementable, because `QueryBuilder` builds `SELECT` and only `SELECT`. Reads keep it; writes get **FR-33b** `MutationBuilder`, and the FR-07 allowlist is extracted into a shared `Identifier` so one rule serves both. Adds `SqlStatement::fromMutation()` as a fourth named door, leaving `composed()` at zero in-library uses. FR-35's normative behaviours recorded: empty criteria refused, the DTO projected rather than `SELECT *`, the table allowlisted at construction. Honest limit recorded in the ADR: on SQLite a DTO/table mismatch is caught by strict hydration rather than the driver, and is invisible against an empty table. See [ADR-0044](../adr/0044-the-write-builder-querybuilder-never-had-and-one-allowlist-for-both.md). |
| r10 | 2026-08-06 | §2/§3: FR-34 stated to the precision item 10.3 implemented (no catch anywhere — the requirement met by omission and asserted against the source; strict hydration kept with a protected seam; opt-in normalization, settling ADR-0042's deferral; a row count rather than a boolean). §3's dependency rule gains its **first two named cross-group edges**, `Persistence→Database` and `Persistence→Dto` — granted, not a general relaxation, and proved in three directions (both grants live, a non-granted edge refused, the inversion refused). See [ADR-0043](../adr/0043-two-named-edges-out-of-persistence-and-no-catch-at-all.md). |
| r9 | 2026-08-06 | §2/§3: FR-36 stated to the precision item 10.2 implemented (the asymmetric defaults and why, the fixed step order, the column-naming strict failure, what is never touched); §3's `Persistence` group arrives with a **Support-only** edge, the two cross-group edges of RFC-0002 P-1 deliberately deferred to item 10.3 and **proved closed** by a planted `Persistence → Database` violation. Additive; see [ADR-0042](../adr/0042-trim-is-the-only-default-and-the-transcode-runs-first.md). |
| r8 | 2026-08-06 | §2/§5: FR-33's guarantee moves from convention to **type** (item 10.7) — `SqlStatement`'s constructor becomes private behind `literal()` (a `literal-string`, enforced by the PHPStan max level CI already runs), `fromQueryBuilder()` and `composed()`. Verified by planting four interpolation mistakes (all rejected) against four legitimate shapes including hand-written dialect SQL (all accepted). A second pre-1.0 break to the same class, migrated in the same PR. See [ADR-0041](../adr/0041-constrain-sql-text-by-type-and-name-the-one-escape-hatch.md), which amends ADR-0039. |
| r7 | 2026-08-06 | §2/§5: FR-33 realized as the only shape `DatabaseConnection::select()`/`selectOne()`/`execute()` accept — the `(string, array)` pair is retired in favor of one `SqlStatement` argument (item 10.1, opens Milestone 10). A pre-1.0 breaking change to the `Database` group's public signature, permitted and migrated in the same PR. See [ADR-0039](../adr/0039-sql-text-and-its-parameters-become-one-value-not-two-arguments.md). |
| r6 | 2026-08-06 | §2/§6: FR-32 stated to the precision item 9.5 implemented (one lock across the read-modify-write, corrupt state refused rather than reset, the caller-supplied window and its recorded ordering limit); FR-22/23 gains `File::update()`, without which a safe counter cannot be built from this library's primitives; §6 gains suite **T-14** (multi-process concurrency: each number issued exactly once, cap holds). Additive; see [ADR-0038](../adr/0038-one-lock-across-the-read-and-the-write-and-a-sequence-that-refuses-to-wrap.md). |
| r5 | 2026-08-06 | §2: FR-28/FR-29 stated to the precision item 9.4 implemented (PHP's backslash escape disabled, the single-empty-field and zero-column shapes, blank-line handling, the enforced header/row pairing, the guard's leader set); FR-22/23 gains `File::writeStream()`, without which a streaming CSV could not honour NFR-12; `CsvException` added to the exception enumeration. Additive; see [ADR-0037](../adr/0037-disable-phps-escape-character-and-keep-the-formula-guard-opt-in.md). |
| r4 | 2026-08-06 | §2: FR-27 stated to the precision item 9.3 implemented (control-character refusal, absolute-only, the named downgrade pairs and their recorded limit, query preservation), and `InvalidUrlException` added to the r3 exception enumeration, which had not anticipated it. Additive; see [ADR-0036](../adr/0036-refuse-the-downgrade-and-the-characters-parse-url-launders.md). |
| r3 | 2026-08-06 | §2–§6: the RFC-0002 surface — FR-27…FR-44, NFR-09…NFR-14, suites T-07…T-15, the Persistence/Mail groups and the two named layering edges. Additive; no existing requirement changed. Source: [RFC-0002](../rfc/0002-application-layer-groups-from-legacy-intake.md) (approved 2026-08-05, PR #49); roadmap: M9–M12. |

### r2 rationale — why T-03 asserts a mechanism instead of measuring time

The original requirement asked for a timing test. It was measured before being changed, and the
signal such a test depends on is not there to be measured.

A timing test distinguishes `hash_equals()` from `===` by exploiting the fact that `===`
short-circuits on the first differing byte, so an early difference should be measurably faster
than a late one. On 64-character tokens, 2,000,000 iterations × 5 rounds, PHP 8.3.1:

| scenario | median ns/op | within-scenario spread |
|---|---|---|
| `===`, differing at byte 0 | 101.517 | 2.63 ns |
| `===`, differing at byte 63 | 104.352 | 2.12 ns |
| `hash_equals()`, differing at byte 0 | 232.103 | 38.22 ns |
| `hash_equals()`, differing at byte 63 | 227.929 | 29.85 ns |

The gradient the test needs — `===` late minus early — is **+2.8 ns/op**, against a worst
within-scenario noise of **38 ns/op**: the signal sits roughly **13× below the noise floor** on an
idle developer machine. `hash_equals()`'s own gradient comes out **negative** (−4.2 ns/op), which is
noise with a sign. Over HTTP, as T-03 runs, 2.8 ns sits **six orders of magnitude** below request
latency; shared-vCPU CI runners are noisier still.

**Scoping.** The constant-time property *of `hash_equals()` itself* is PHP's contract, verified
upstream in PHP's own test suite; re-deriving it here would be testing someone else's implementation
through a worse instrument. The property that exists at *this* layer is **which comparator the code
invokes** — and that is decidable exactly, from the source, with no measurement at all. The
mechanism assertion tests that property, deterministically, in both directions.

**Rejected alternatives.** Asserting that `hash_equals()` is measurably *slower* than `===` (a 2.3×
gap, comfortably above noise) tests an implementation artifact with an inverted failure profile: red
on a legitimate PHP optimisation, green on a slow but non-constant-time comparator. A full
statistical (dudect-style) test applies the right technique at the wrong abstraction layer — at this
signal-to-noise ratio its discriminative power is zero, so it would be either flaky or tuned into
vacuity.

T-03 therefore ships with its behavioural suite and no timing test, and this is an amendment rather
than a standing deviation: there is nothing left to track.
