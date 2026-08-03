# Software Specification: d4np-php (PHP Enterprise Utilities Library)

> **Imported into `egl-util-php`** (EADOS design phase, 2026-08-03). The naming in this document
> reflects the original project name `d4np-php`; the authoritative rename record is the naming
> mapping table of [RFC-0001](../docs/rfc/0001-egl-utils-library.md) (package `egl/utils`,
> PSR-4 root `D4np\Utils\`, bridge `egl/utils-psr7-bridge`, root exception `UtilsException`).

| | |
|---|---|
| **Version** | 2.0 (addresses spec-review issue #11) |
| **Date** | 2026-07-14 |
| **Status** | Reviewed draft |
| **ADRs** | [ADR-001: DI container](d4np_php_adr_001_di_container.md) · [ADR-002: HTTP wrappers vs PSR-7](d4np_php_adr_002_http_psr7.md) |

## 1. Description & Design Philosophy
`d4np-php` is a modern PHP library (**PHP 8.1+**, Composer/Packagist, vendor namespace `D4np\Php\`) providing security helpers, typed DTOs, and a minimal PSR-11 DI container for framework-based and native projects.

Design principles:
* **Strong typing:** immutable `readonly` DTOs instead of unstructured associative arrays.
* **Clean code:** strict PSR-1/4/11/12 compliance; PHPStan at max level as a CI gate (§8).
* **Security by explicit mechanism** *(v1's "all I/O sanitizes automatically" is withdrawn — blanket input sanitization is the magic_quotes anti-pattern)*. The security model, stated precisely:
  1. **Persistence (values):** parameterized queries, always (items 6–7).
  2. **Persistence (identifiers):** allowlist + strict quoting, because prepared statements do not cover table/column names (item 7).
  3. **Output:** context-aware escaping at render time (item 9), never input mutilation.
  4. **Rich HTML:** allowlist sanitization delegated to a proven sanitizer (item 9b).

Each security feature in §2 names its mechanism, its scope, and its test (§7).

---

## 2. Functional Specification (25 items)

### DTO & Data Mapping
1. **`DataTransferObject`** — hydrates typed DTOs from associative arrays via Reflection with **per-class metadata caching** (reflection cost paid once per class, NFR-01). **Contract (v1 unspecified):** properties are `readonly` (promoted constructor); **strict mode by default** — unknown keys throw `UnknownKeyException` (mass-assignment safety for a security-oriented library); `lenient()` opt-in ignores extras; nested DTOs and `Collection<T>` properties hydrate recursively; type mismatches throw `TypeMismatchException` naming the path.
2. **`WithersTrait`** — *(rescoped: v1's `ReadOnlyPropertyTrait` "for earlier PHP versions" contradicted the 8.1+ floor where `readonly` is native)*: `with(...)` wither semantics producing modified **clones** of readonly DTOs — the actual 8.1-era gap (readonly properties cannot be reassigned during `clone` until PHP 8.3's readonly-clone change, which the trait absorbs per version).
3. **`Collection<T>`** — functional array wrapper (`map`, `filter`, `reduce`). **Genericity is static-analysis-level only** *(stated explicitly — PHP has no runtime generics)*: `@template T` docblocks enforced by PHPStan max level in CI; at runtime the collection checks nothing beyond an optional `instanceof` guard flag.

### Dependency Injection
4. **`Container`** — minimal PSR-11 container: constructor autowiring, singletons/factories, no compilation — scope limits and the build-vs-adopt decision recorded in [ADR-001](adr/d4np_php_adr_001_di_container.md).
5. **`ServiceProvider`** — abstract base for registering definitions.

### Database & PDO
6. **`DatabaseConnection`** — PDO wrapper with **pinned safe defaults** *(v1's "silent error mode" contradicted item 8's exception-driven rollback and reverses the PHP 8.0 default; v1's "UTF-8" charset is a 3-byte subset on MySQL)*:

   | Option | Value | Why |
   |---|---|---|
   | `ATTR_ERRMODE` | `ERRMODE_EXCEPTION` | failures must throw — item 8's rollback depends on it; PHP 8.0+ default restored |
   | charset (MySQL) | **`utf8mb4`** | MySQL `utf8` cannot store all of Unicode (4-byte code points) |
   | `ATTR_EMULATE_PREPARES` | `false` | real server-side prepares; types preserved |
   | `ATTR_DEFAULT_FETCH_MODE` | `FETCH_ASSOC` | explicit, no dual-key waste |
7. **`QueryBuilder`** — fluent SQL builder. **Injection defense fully scoped** *(v1 claimed prepared statements prevent SQL injection — they cover values only)*: values bind as parameters; **identifiers** (table/column names) must match `^[A-Za-z_][A-Za-z0-9_]*$` and are driver-quoted (backticks/double quotes); `ORDER BY` direction is an enum (`Sort::Asc|Desc`), never a string; `LIMIT`/`OFFSET` cast to non-negative int. §7 T-02 proves identifier-injection attempts are rejected.
8. **`Transaction`** — closure-scoped transactions with automatic rollback on any exception (consistent with item 6's error mode) and rethrow; nested calls use savepoints.

### Security
9. **`Escaper`** — *(replaces v1's blocklist `Sanitizer::html()`, a weak XSS defense)*: context-aware **output escaping** — `html()` (`htmlspecialchars` `ENT_QUOTES|ENT_SUBSTITUTE`, UTF-8), `attr()`, `js()` (hex-encoding), `url()` (`rawurlencode`). Scope: rendering untrusted text into each context.
   **9b. Rich-HTML sanitization** — for user-authored HTML, the library **delegates** to `symfony/html-sanitizer` (optional dependency, W3C-spec-based allowlist) behind `Sanitizer::richText()` with a curated profile; no hand-rolled tag stripper exists in the package.
10. **`Sanitizer::sqlLikePattern()`** — escapes `%`/`_`/escape-char in user input used inside `LIKE` patterns (wildcard-injection defense; values still bind as parameters — this only neutralizes wildcards).
11. **`Hash::make()` / `Hash::verify()`** — `password_hash` wrapper, **Argon2id default**. **Platform constraint handled** *(v1 omitted it)*: Argon2id requires PHP built with libargon2 — availability detected via `defined('PASSWORD_ARGON2ID')`; policy: explicit `bcryptFallback: true` default (logged at WARNING) or `false` to fail fast; hashes are self-describing so `verify` works regardless; `needsRehash()` upgrades on login.
12. **`CsrfToken`** — CSPRNG token generation + constant-time validation (`hash_equals`), per-session storage, optional per-form scoping.

### HTTP & Session
13. **`Request`** — typed superglobal reader (`$_GET/$_POST/$_SERVER/$_FILES`); optional **PSR-7 bridge** per [ADR-002](adr/d4np_php_adr_002_http_psr7.md).
14. **`Response`** — headers/JSON/status helpers; same bridge.
15. **`Session`** — secure session API: `cookie_httponly`, `cookie_secure`, `cookie_samesite=Lax` set at start; `regenerate()` wraps `session_regenerate_id(true)` for login transitions (fixation defense — testable criterion in T-03).

### Errors & Logging
16. **`Result`** — success/failure wrapper for application services (`map/flatMap/orElseThrow`).
17. **`Logger`** — PSR-3 file/console logger.
18. **`ExceptionHandler`** — fatal-error capture, JSON problem responses for API calls; never leaks traces in production mode (env-gated).
**Exception hierarchy (referenced across 16–18, 25 — v1 never defined it):** `D4npException` ← `DatabaseException`, `HydrationException` (← `UnknownKeyException`, `TypeMismatchException`), `HttpException`, `FileException`, `JsonException`.

### String & File Utilities
19. **`Str::slug()`** — URL-friendly slugs (transliteration via `ext-intl` when present).
20. **`Str::uuid()`** — UUID v4 from `random_bytes` (CSPRNG).
21. **`Str::random()`** — CSPRNG alphanumeric tokens.
22. **`File::write()` / `File::read()`** — `flock`-guarded I/O; atomic write via temp + `rename`.
23. **`File::mime()`** — Fileinfo-based MIME detection (never trusts extensions).
24. **`Env::get()`** — env reads with correct boolean coercion (`"false"` → `false`).
25. **`Json::encode()` / `Json::decode()`** — wrappers with `JSON_THROW_ON_ERROR`, typed `JsonException`.

---

## 3. Architecture (C4 Component View)
```
 ┌─ d4np-php (Composer package, namespace D4np\Php\) ────────────────────────┐
 │                                                                           │
 │  Http: Request ─ Response ─ Session ─ CsrfToken     ──►  Support          │
 │  Database: DatabaseConnection ─ QueryBuilder ─       ──►  Support          │
 │            Transaction                                                    │
 │  Security: Escaper ─ Sanitizer ─ Hash               ──►  Support          │
 │  Dto: DataTransferObject ─ WithersTrait ─           ──►  Support          │
 │       Collection<T>                                                       │
 │  Container: Container(PSR-11) ─ ServiceProvider     ──►  Support          │
 │  Errors: Result ─ Logger(PSR-3) ─ ExceptionHandler  ──►  Support          │
 │                                                                           │
 │  Support (bottom layer): Str ─ File ─ Env ─ Json ─ exception hierarchy    │
 │                                                                           │
 │  Optional deps: symfony/html-sanitizer (9b) · psr/http-message bridge     │
 │  (ADR-002) — core requires only php>=8.1 + ext-pdo + ext-fileinfo         │
 │  Rule: groups depend downward on Support only; no cross-group imports     │
 │  (enforced by deptrac in CI)                                              │
 └───────────────────────────────────────────────────────────────────────────┘
```

---

## 4. Non-Functional Requirements & Benchmark Methodology
**Methodology:** phpbench (10 iterations × 100 revs, retry-threshold 5%), PHP 8.3 CLI with OPcache + JIT off (library-consumer realism), reference machine Ryzen 7 5800X; harness in `bench/`; nightly CI, > 10% regression fails.

| ID | Target |
|---|---|
| NFR-01 | DTO hydration (10 scalar props): ≤ 5 µs/DTO warm (cached reflection) and ≤ 3× manual constructor assignment |
| NFR-02 | Container: singleton resolve ≤ 2 µs warm; first autowired resolve ≤ 30 µs |
| NFR-03 | QueryBuilder: SELECT with 5 conditions builds in ≤ 10 µs, 0 queries executed at build time |
| NFR-04 | Memory: hydrating 10 000 DTOs ≤ 16 MB peak delta |
| NFR-05 | `Hash::make` Argon2id defaults complete in 50–200 ms on the reference machine (deliberately slow; documented for capacity planning) |

---

## 5. Security Test Criteria (one per feature, per acceptance criteria)
| Feature | Testable criterion |
|---|---|
| QueryBuilder values | fuzzed value payloads (`' OR 1=1--`) reach the driver only as bound parameters (query-log assertion) |
| QueryBuilder identifiers (T-02) | `orderBy("name; DROP TABLE users")` throws `DatabaseException`; only allowlisted identifiers pass |
| Escaper | OWASP XSS cheat-sheet corpus escaped per context (snapshot suite) |
| `Sanitizer::richText()` | DOM-based bypass corpus (event handlers, `javascript:` URIs, SVG) neutralized by the profile |
| Session (T-03) | session id changes across `regenerate()`; cookies carry HttpOnly/Secure/SameSite in integration test |
| CSRF | token validation is `hash_equals`-based (timing test) and rejects cross-session tokens |
| Hash | verify works for both argon2id and bcrypt-fallback hashes; `needsRehash` triggers on policy change |

---

## 6. API Example (DTO — consistent with the §1 immutability philosophy; v1's example used mutable props, an app namespace, and undefined extra-key behavior)
```php
use D4np\Php\Dto\DataTransferObject;
use D4np\Php\Dto\UnknownKeyException;

final class UserDto extends DataTransferObject
{
    public function __construct(
        public readonly string $email,
        public readonly string $name,
    ) {}
}

$data = ['email' => 'daniel@polo.com', 'name' => 'Daniel', 'role' => 'admin'];

try {
    $userDto = UserDto::fromArray($data);          // strict mode (default)
} catch (UnknownKeyException $e) {
    // 'role' is not declared on UserDto -> rejected: mass-assignment safety.
}

$userDto = UserDto::lenient()->fromArray($data);   // opt-in: unknown keys ignored
echo $userDto->email;                              // daniel@polo.com
```

---

## 7. Verification & Test Strategy
* **T-01 DTO suite:** hydration matrix (nested DTOs, collections, nullables, enums), strict/lenient behavior, wither clones.
* **T-02 injection suite:** value + identifier + LIKE-wildcard injection attempts (see §5).
* **T-03 session/CSRF integration:** real `php -S` process, cookie-flag and regeneration assertions.
* **T-04 transaction semantics:** exception → rollback → rethrow; savepoint nesting.
* **T-05 property tests:** `Json` round-trips, `Str::slug` idempotence, `Env` boolean coercion table.

## 8. CI/CD & Release Engineering
* **Matrix:** PHP 8.1 / 8.2 / 8.3 (floor documented in `composer.json`), lowest + highest dependency sets (`composer update --prefer-lowest` job).
* **Static gates:** PHPStan **max level** (enforcing `@template` generics of item 3), `deptrac` layer rules (§3), `composer-normalize`, `composer audit`.
* **Quality gates:** PHPUnit coverage ≥ 90% lines; **Infection mutation score ≥ 70%** on Security/Database/Dto namespaces.
* **BC policy:** SemVer; `roave/backward-compatibility-check` against the previous tag on every release PR; deprecations live one minor before removal.
* **Release flow:** tagged release → GitHub Action validates gates → Packagist webhook publish; signed tags.

---

## 9. Decision Log
* [ADR-001 — Ship a minimal PSR-11 container instead of depending on PHP-DI](d4np_php_adr_001_di_container.md)
* [ADR-002 — Native lightweight HTTP wrappers with optional PSR-7 bridge](d4np_php_adr_002_http_psr7.md)
