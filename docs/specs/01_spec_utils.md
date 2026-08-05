# Software Specification: EGL PHP Utility Library (PHP 8.1+)

> Rendered from the intake interview (Phase 5). Frozen contract: diverging implementation
> updates this spec in the same PR or adds an ADR superseding the relevant section.

**Revision r2** — 2026-08-05. See [Revision history](#revision-history).

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
- FR-22..23 File: write()/read() flock-guarded, atomic write via temp+rename; mime() via Fileinfo (never trusts extensions)
- FR-24 Env::get(): env reads with correct boolean coercion ('false' -> false)
- FR-25 Json: encode()/decode() with JSON_THROW_ON_ERROR; library JsonException wraps and rethrows PHP's native \JsonException (RFC-0001 R-7)
- FR-26 Exception hierarchy: UtilsException <- DatabaseException, HydrationException (<- UnknownKeyException, TypeMismatchException, MissingKeyException), HttpException, FileException, JsonException


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

## 5. Public Interface

<!-- The API contract (the design "api" fold): each operation with its payload shapes, the error
     model (the failure taxonomy, not just the happy path), and the versioning / SemVer surface.
     A service/web project may keep the written-out contract under docs/api/ (capabilities.api_spec). -->
Consumers import via `use D4np\Utils\Dto\DataTransferObject;`. The public surface:

- D4np\Utils\Dto\ — DataTransferObject::fromArray()/lenient(), WithersTrait::with(), Collection<T>
- D4np\Utils\Container\ — PSR-11 Container (get/has, autowire, singleton/factory), ServiceProvider
- D4np\Utils\Database\ — DatabaseConnection (pinned PDO defaults), QueryBuilder (fluent, Sort enum), Transaction::run(closure)
- D4np\Utils\Security\ — Escaper::html/attr/js/url, Sanitizer::richText/sqlLikePattern, Hash::make/verify/needsRehash
- D4np\Utils\Http\ — Request (typed superglobal reader), Response, Session::regenerate(), CsrfToken; PSR-7 via optional egl/utils-psr7-bridge
- D4np\Utils\Errors\ — Result::map/flatMap/orElseThrow, PSR-3 Logger, ExceptionHandler
- D4np\Utils\Support\ — Str::slug/uuid/random, File::write/read/mime, Env::get, Json::encode/decode, UtilsException hierarchy


## 6. Verification & Test Strategy

Five suites (spec s7): T-01 DTO hydration matrix (nested, collections, nullables, enums, strict/lenient, withers, missing-key cases per RFC-0001 R-4); T-02 injection suite (fuzzed value payloads reach the driver only as bound parameters via query-log assertion; identifier injection throws DatabaseException; LIKE-wildcard escapes); T-03 session/CSRF integration against a real php -S process (cookie flags HttpOnly/Secure/SameSite, session id changes across regenerate() and the pre-rotation identifier ceases to resolve, constant-time comparison verified by mechanism assertion per ADR-0026 §7 — positively, that `hash_equals()` is the comparator on every secret-comparison path, and negatively, that `==`, `===`, `strcmp()`, `strncmp()` and equivalents are absent from those paths — cross-session token rejection); T-04 transaction semantics (exception -> rollback -> rethrow; savepoint nesting); T-05 property tests (Json round-trips, Str::slug idempotence, Env boolean coercion table). Plus: OWASP XSS cheat-sheet corpus per Escaper context (snapshot suite); DOM-bypass corpus for richText(); Hash argon2id/bcrypt-fallback matrix; bridge conversion-fidelity contract tests in egl/utils-psr7-bridge CI (imported ADR-002). CI proves failure via the NFR-07 gate set.

Toolchain: built with Composer (PSR-4 autoload), tested with PHPUnit (Pest optional), checked with
PHPStan max level (type soundness); PCOV for coverage, coverage target ≥ 90% line. Every functional and
non-functional requirement above maps to a CI gate (see [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml)).


## Revision history

| Rev | Date | Change |
|-----|------|--------|
| r1 | 2026-08-03 | Frozen from the imported `.specs/d4np-php.md` v2.0 via RFC-0001 (naming mapping A-7). |
| r2 | 2026-08-05 | §6/T-03: the `hash_equals` **timing test** is replaced by a **mechanism assertion**. Rationale below; see [ADR-0027](../adr/0027-constant-time-comparison-is-asserted-by-mechanism-not-by-timing.md). |

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
