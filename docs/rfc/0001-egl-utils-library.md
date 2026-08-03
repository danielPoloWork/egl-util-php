# RFC-0001: EGL PHP Utilities Library (`egl/utils`) — foundation design

- **Status:** Accepted
- **Author:** tech-lead (agent-drafted) · **Reviewers:** reviewer, enterprise-architect · **Approver:** tech-lead
- **Date:** 2026-08-03
- **Related:** imported spec [.specs/d4np-php.md](../../.specs/d4np-php.md) (v2.0, reviewed draft) ·
  imported [ADR-001 (DI container)](../../.specs/d4np_php_adr_001_di_container.md) ·
  imported [ADR-002 (HTTP / PSR-7)](../../.specs/d4np_php_adr_002_http_psr7.md) ·
  manifest [orchestrator/project.yaml](../../orchestrator/project.yaml)

> Imported design (interview Q5.0 = import): the reviewed v2.0 specification was authored under the
> project's earlier name `d4np-php`. This RFC validates it into the EADOS pipeline for
> `egl-util-php` with the naming mapping below. Content is carried over, not re-invented; the spec
> document remains the detailed source and is frozen into `docs/specs/` at scaffold with the
> mapping preamble (A-7).

## Naming mapping (import decision, 2026-08-03)

| Surface | Spec (d4np) | This project | Provenance |
|---|---|---|---|
| Repository | `d4np-php` | `egl-util-php` | manifest (init) |
| Composer package | `d4np-php` (informal §3 label; not a valid vendor/name pair) | `egl/utils` | asked |
| PSR-4 root namespace | `D4np\Php\` | `D4np\Utils\` | asked — **deliberate mixed-vendor choice** (see Alternatives #5) |
| PSR-7 bridge package | `d4np/php-psr7-bridge` | `egl/utils-psr7-bridge` | asked |
| Root exception | `D4npException` | `UtilsException` | endorsed at review (R-9): avoids the `D4np\Utils\D4npException` stutter; child exception names carry over unchanged |

## Context

PHP teams at EGL ship both framework-based and native/legacy applications. Recurring needs —
typed data transfer, explicit security mechanisms, safe PDO access, hardened session/CSRF
handling, small-scale DI — are today solved ad hoc per project, with the classic failure modes:
associative-array "DTOs", blocklist sanitizers, string-built SQL, silent PDO error modes.

`egl/utils` is a modern PHP library (**PHP 8.1+**, Composer/Packagist) providing security
helpers, typed DTOs, and a minimal PSR-11 DI container. Design principles (spec §1):

- **Strong typing** — immutable `readonly` DTOs instead of unstructured arrays.
- **Clean code** — strict PSR-1/4/11/12; PHPStan max level as a CI gate.
- **Security by explicit mechanism** — the v1 idea "all I/O sanitizes automatically" is
  withdrawn (the `magic_quotes` anti-pattern). Each security feature names its mechanism, its
  scope, and its test:
  1. Persistence (values): parameterized queries, always.
  2. Persistence (identifiers): allowlist + strict driver quoting (prepared statements do not
     cover table/column names).
  3. Output: context-aware escaping at render time, never input mutilation.
  4. Rich HTML: allowlist sanitization delegated to `symfony/html-sanitizer` (optional dep).

Constraints: **no third-party *implementation* dependencies in the core** — `php>=8.1` +
`ext-pdo` + `ext-fileinfo`, with the interface-only PSR packages `psr/container` and `psr/log`
excepted, since a package implementing PSR-11/PSR-3 necessarily requires them (R-3: an RFC-level
correction of spec §3's literal "zero-dependency" claim, which is mechanically false).
Optional dependencies for rich-HTML sanitization and the PSR-7 bridge. Governance posture
**enterprise** (manifest) — security-relevant decisions carry ADRs (see Follow-up).

## Decision

Ship the library as **six component groups over a Support layer** (spec §3, C4 component view),
25 functional items (spec §2), with the dependency rule *groups depend downward on Support only;
no cross-group imports* — enforced by `deptrac` in CI.

| Group | Namespace | Components (spec §2 items) |
|---|---|---|
| Dto | `D4np\Utils\Dto\` | `DataTransferObject` (1), `WithersTrait` (2), `Collection<T>` (3) |
| Container | `D4np\Utils\Container\` | PSR-11 `Container` (4), `ServiceProvider` (5) — scope per imported ADR-001 |
| Database | `D4np\Utils\Database\` | `DatabaseConnection` (6, pinned safe PDO defaults), `QueryBuilder` (7), `Transaction` (8) |
| Security | `D4np\Utils\Security\` | `Escaper` (9), rich-HTML `Sanitizer::richText()` (9b), `Sanitizer::sqlLikePattern()` (10), `Hash` (11, Argon2id + bcrypt fallback policy) |
| Http | `D4np\Utils\Http\` | `Request` (13), `Response` (14), `Session` (15), `CsrfToken` (12) — PSR-7 via optional bridge per imported ADR-002 |
| Errors | `D4np\Utils\Errors\` | `Result` (16), PSR-3 `Logger` (17), `ExceptionHandler` (18) |
| Support | `D4np\Utils\Support\` | `Str` (19–21), `File` (22–23), `Env` (24), `Json` (25), exception hierarchy, **shared reflection-metadata cache** |

**Placement notes (resolved at review):**

- **`CsrfToken` lives in Http, not Security (R-1).** The spec is internally split — §2 lists
  item 12 under a "Security" heading while §3's C4 view draws it in the Http box. This RFC
  follows §3: `CsrfToken` requires per-session storage (item 12) and therefore sits beside
  `Session`; placing it in Security would force a Security→Http import that the layering rule
  forbids. Recorded here as a resolved spec-internal conflict so deptrac and T-03 stay
  satisfiable.
- **The shared reflection-metadata cache is Support (R-2).** Imported ADR-001 commits to *one*
  metadata cache shared by the DTO hydrator and the Container (NFR-01/NFR-02 hinge on it); under
  the no-cross-group-imports rule its only legal home is Support. This deliberately extends spec
  §3's Support enumeration.
- **Source tree (A-9).** The factory renders `src/main/php/d4np/utils/` (the manifest-derived
  `SRC_MAIN`); `composer.json`'s PSR-4 base dir (`"D4np\\Utils\\": "src/main/php/d4np/utils/"`)
  and the deptrac layer globs are defined against that exact tree, with the group directories
  (`Dto/`, `Container/`, …) case-exact below it — milestone item 1.x pins this so a hand-authored
  `composer.json` cannot drift to a bare `src/`.

### API contract (`api` / `systemdesign`)

- **Operations** — the 25 items of spec §2 are the public surface; signatures follow the spec
  verbatim under the mapped namespace (e.g. `D4np\Utils\Dto\DataTransferObject::fromArray()`,
  `QueryBuilder::orderBy(string $column, Sort $direction)`, `Hash::make()/verify()/needsRehash()`,
  `Session::regenerate()`, `Str::slug()/uuid()/random()`).
- **Payloads** — typed `readonly` promoted-constructor DTOs; **strict hydration by default**
  (unknown keys throw `UnknownKeyException`; mass-assignment safety), `lenient()` opt-in; nested
  DTOs and `Collection<T>` hydrate recursively; `Collection<T>` genericity is
  **static-analysis-level only** (`@template T` + PHPStan max; no runtime generics — stated
  honestly, optional `instanceof` guard flag at runtime).
- **Error model** — one hierarchy consumers can catch coarsely or finely:
  `UtilsException` ← `DatabaseException`, `HydrationException` (← `UnknownKeyException`,
  `TypeMismatchException`, `MissingKeyException`), `HttpException`, `FileException`,
  `JsonException`. Service-level outcomes use `Result` (`map`/`flatMap`/`orElseThrow`) instead
  of boolean/null returns. Two review additions:
  - **`MissingKeyException` (R-4):** thrown in *both* strict and lenient modes when the input
    lacks a declared property that is neither nullable nor defaulted; a nullable or defaulted
    property absent from the input hydrates to `null`/its default. The imported spec was silent
    on the missing-key case; T-01's matrix gains these cases.
  - **`JsonException` shadowing (R-7):** the library `JsonException` wraps and rethrows PHP's
    native `\JsonException` (which is what `JSON_THROW_ON_ERROR`, item 25, raises). The name
    collision is deliberate — same failure domain — and is resolved in consumer-facing docs with
    an import alias example.
- **Versioning** — SemVer on Packagist. **MAJOR** = any BC break of: the PSR-4 namespace, public
  signatures, the exception hierarchy shape, pinned `DatabaseConnection` defaults semantics, or
  strict-mode hydration behavior. Mechanically enforced:
  `roave/backward-compatibility-check` against the previous tag on every release PR;
  deprecations live one full minor before removal (spec §8).

### Data & schema (`database`) — omitted

The library owns no persistent state: `Database` components wrap **consumer-owned** PDO
connections (SQL is a secondary surface in ADR-0004's frame). No entities, no migrations.

### Scalability budgets (`scalability`)

The `software` domain declares no hard NFR axes
([domains/software.yaml](../../.eados-core/orchestrator/domains/software.yaml): all
`hard_budget: false`), but the imported spec commits **numeric** targets (spec §4) — carried
over as stated budgets the audit phase can evaluate, and recorded in the manifest as
`spec.nonfunctional_reqs` NFR-01…NFR-05:

| ID | Budget |
|---|---|
| NFR-01 | DTO hydration (10 scalar props): ≤ 5 µs/DTO warm (cached reflection), ≤ 3× manual assignment |
| NFR-02 | Container: singleton resolve ≤ 2 µs warm; first autowired resolve ≤ 30 µs |
| NFR-03 | QueryBuilder: 5-condition SELECT builds in ≤ 10 µs; 0 queries executed at build time |
| NFR-04 | Memory: hydrating 10 000 DTOs ≤ 16 MB peak delta |
| NFR-05 | `Hash::make` (Argon2id defaults): 50–200 ms on the reference machine — deliberately slow, documented for capacity planning |

Methodology (spec §4): phpbench, 10 iterations × 100 revs, 5% retry threshold, PHP 8.3 CLI with
OPcache + JIT **off** (library-consumer realism), reference machine Ryzen 7 5800X, harness in
`bench/`, nightly CI; a regression > 10% fails the run.

### Algorithm sketch (`pseudocode`)

The one non-obvious core path — DTO hydration with the shared per-class metadata cache
(NFR-01 hinges on it; the cache is Support-layer shared infrastructure per imported ADR-001
and placement note R-2):

```
hydrate(class, data, mode):
    meta ← cache[class]                     # reflection cost paid once per class
      or cache[class] ← reflect(class)      # (props, types, defaults, nested-DTO / Collection<T> markers)
    for each key in data:
        if key ∉ meta.props:
            strict mode → throw UnknownKeyException(path)
            lenient mode → skip
    for each prop in meta.props:
        if prop ∉ data:
            nullable or defaulted → hydrate to null / default value
            otherwise → throw MissingKeyException(path)          # R-4, both modes
        value ← data[prop]
        nested DTO      → hydrate(prop.class, value, mode)      # recurse
        Collection<T>   → map hydrate over elements
        scalar          → type-check; mismatch → throw TypeMismatchException(path)
    return new class(...values)             # readonly promoted constructor
```

### Cross-cutting

**Security** — every §2 security feature carries a per-feature testable criterion (spec §5),
mechanical rather than aspirational: fuzzed value payloads reach the driver only as bound
parameters (query-log assertion); `orderBy("name; DROP TABLE users")` throws
`DatabaseException` (T-02); OWASP XSS cheat-sheet corpus escaped per context (snapshot suite);
DOM-bypass corpus neutralized by the `richText()` profile; session id changes across
`regenerate()` and cookies carry HttpOnly/Secure/SameSite (T-03); CSRF validation is
`hash_equals`-based (timing test) and rejects cross-session tokens; `Hash::verify` works for
argon2id and bcrypt-fallback hashes, `needsRehash` triggers on policy change. The Argon2id
platform constraint is handled explicitly: availability via `defined('PASSWORD_ARGON2ID')`,
`bcryptFallback: true` default logged at WARNING, `false` to fail fast.

**Performance** — the NFR table above; benchmarks are first-class (`bench/`, phpbench, nightly).

**Quality gates** (spec §8) — PHP 8.1/8.2/8.3 matrix with `--prefer-lowest` job; PHPStan max
level (enforces `@template` generics); `deptrac` layer rules; `composer-normalize`;
`composer audit`; PHPUnit coverage ≥ 90% lines; **Infection mutation score ≥ 70%** on
Security/Database/Dto namespaces; **bridge conversion-fidelity contract tests** (headers,
uploaded files, immutability boundaries) in `egl/utils-psr7-bridge`'s own CI (imported ADR-002
commitment, R-5); signed tags; Packagist publish via validated release action. **Psalm is not
adopted** — the profile default pairs PHPStan with Psalm, but the design keeps a single
static-analysis authority (PHPStan max) to avoid double-baseline maintenance; recorded as a
toolchain override for the scaffold interview (A-4).

## Alternatives

1. **Depend on PHP-DI (or Symfony DI / league/container) instead of shipping a container** —
   rejected (imported ADR-001): framework-sized dependency forced on every consumer, including
   apps that already have a container; dependency-conflict surface for negative value. The
   minimal PSR-11 container is a **default, not a lock-in** — everything consumes
   `Psr\Container\ContainerInterface`, so swapping in PHP-DI is a one-line change. The core keeps
   **no implementation dependencies** (interface-only `psr/container` excepted, R-3). Non-goals
   stated: no compilation, no attributes, no lazy proxies, no circular-dependency resolution
   (throws with the dependency path). NFR-02 keeps it honest.
2. **Ship no container ("suggest-only")** — rejected (imported ADR-001): guts the DI promise for
   native-PHP consumers, the stated audience.
3. **Implement PSR-7 directly, or depend on nyholm/psr7 and expose PSR-7 types only** — rejected
   (imported ADR-002): implementing PSR-7 well is a solved project (streams, immutability,
   uploaded files) and re-doing it adds maintenance without differentiation; PSR-7-only forces
   factory wiring and stream handling on framework-less users who want
   `$request->postString('email')`. Chosen: native lightweight wrappers mirroring PSR-7 naming +
   an **optional bridge** (`egl/utils-psr7-bridge`, bidirectional via any PSR-17 factory) as the
   only sanctioned crossing point. The wrappers never grow middleware ambitions — PSR-15 stacks
   via the bridge, per the imported ADR.
4. **Blanket automatic input sanitization** (spec v1's stance) — rejected in the spec's own v2
   review: input mutilation is the `magic_quotes` anti-pattern and a weak XSS defense; replaced
   by the four-mechanism model (parameterized values, identifier allowlist, context-aware output
   escaping, delegated rich-HTML sanitization).
5. **Full-EGL namespace `Egl\Utils\`** — rejected by **maintainer decision at import
   (2026-08-03, precedence layer 1)** in favor of retaining the personal vendor namespace
   `D4np\Utils\` inside the `egl/utils` package. Recorded dissent (tech-lead): the mixed-vendor
   surface (package vendor `egl` ≠ namespace vendor `D4np`) is consumer-visible and expensive to
   reverse — changing the namespace later is a MAJOR break of every consumer `use` statement;
   discoverability suffers (Packagist search vs code search disagree on vendor). Mitigation:
   `composer.json` `autoload.psr-4` and the README state the pairing explicitly, and the BC
   policy pins the namespace as MAJOR-protected surface. The decision stands; this entry is the
   record, and it is transcribed into the generated repo's decision ledger as a seeded ADR (A-2).

## Consequences

**Easier:** typed, mass-assignment-safe data transfer for native and framework apps alike;
security mechanisms that are testable per feature instead of asserted; drop-in PDO safety
(exception mode, `utf8mb4`, real prepares); PSR-11/PSR-7 interop without mandatory
dependencies; migration to mature containers stays one line by construction.

**Harder / accepted costs:** two HTTP vocabularies exist (contained: bridge is the only
crossing, wrapper naming mirrors PSR-7); the custom container will never match compiled
containers on huge graphs (bounded honestly by NFR-02); readonly-clone semantics differ across
PHP 8.1→8.3 and `WithersTrait` must absorb the difference per version; the mixed-vendor naming
(Alternative 5) must be documented wherever consumers first meet the package.

**Manifest write-back (A-1) — done at design entry, not deferred:** the identity facts this RFC
decides are already recorded in [orchestrator/project.yaml](../../orchestrator/project.yaml)
with honest provenance (`language.group_path: d4np`, `group_dotted: d4np`,
`namespace: D4np\Utils`, `lang_standard: PHP 8.1 (minimum …)` — provenance `asked`; the spec
section — provenance `imported`), because the `manifest-valid` gate evaluates the full manifest
before the `init → design` checkpoint can be recorded. The Composer identity is pinned as
milestone-1 item text so item 1.1 cannot mis-create `composer.json`.

**Scaffold handoff (A-3, A-4) — overrides the scaffold interview must record** (the renderer
reads profile + manifest, not spec §8; without these the rendered gates are weaker than the
approved design):

- `ci`: 3-cell matrix **PHP 8.1/8.2/8.3** with an **explicit** toolchain→version map — the
  profile's 2-way ternary (`php.yaml` `setup_steps`) silently maps an added `php-8.1` cell to
  PHP 8.3, so it must be rewritten, not extended — plus the `composer update --prefer-lowest`
  job. *(Factory-side note, advisory: the profile ternary is not extensible to 3+ versions.)*
- `toolchain`: `linter: PHPStan (max level)` (Psalm not adopted — single static-analysis
  authority), `coverage_target: 90` (template default is 80).
- `governance.capabilities`: `bench: true` (phpbench harness + nightly), `packaging: true`
  (Packagist + signed tags).
- `ci.extra_jobs`: deptrac layer check, Infection (≥ 70% on Security/Database/Dto),
  composer-normalize, composer audit.

**Seeded ADRs for the generated repo (A-2)** — the enterprise posture mandates ADRs for
security-relevant decisions; the imported pair (DI container, HTTP/PSR-7) lands in `docs/adr/`
at scaffold, joined by three ADRs this RFC commits to seeding:

1. **Security mechanism model** — the four mechanisms, the identifier allowlist + driver
   quoting, and the pinned PDO defaults (spec §1, items 6–7).
2. **Password-hashing policy** — Argon2id detection, the `bcryptFallback` default and its
   WARNING, `needsRehash` upgrade-on-login (item 11).
3. **Namespace decision** — the Alternative-5 record (decision, dissent, mitigation)
   transcribed so it lives in the repo's own decision ledger, not only in this RFC.

**Follow-up (feeds `/eados plan`):** Milestone 1 is the universal bootstrap (Composer skeleton,
CI, quality gates) with the two pinned items above. Component milestones follow the dependency
order the layering dictates — Support → Dto → Database → Security → Http/Container/Errors →
release engineering — recorded up front as `spec.milestones` 2–7 in the manifest for `/eados
plan` to negotiate. The **PSR-7 bridge** (`egl/utils-psr7-bridge`) is a separate-package
decision (subtree vs second repository — possibly a second EADOS run) **deferred to plan**; its
milestone follows the Http group and carries ADR-002's conversion contract tests (A-8). The
test suites land with their components (T-01…T-05, spec §7 — T-02/T-03 are the
security-critical pair, R-6).

## Approval

approved-by: tech-lead (2026-08-03)

Approval confirmed by the owner (`danielPoloWork`) in the design-phase session of 2026-08-03;
the tech-lead record above encodes that human decision (review protocol: no RFC self-approves).

Reviewers (structured findings addressed): reviewer — resolved (R-1…R-9) ·
enterprise-architect — resolved (A-1…A-9).

Findings resolution map: R-1/R-2 → Decision placement notes; R-3 → Context constraints +
Alternatives #1; R-4/R-7 → API contract error model + pseudocode; R-5 → quality gates; R-6 →
Follow-up wording; R-8/R-9 → naming-mapping table; A-1 → manifest write-back (recorded, rev 1);
A-2 → seeded-ADRs list; A-3/A-4 → scaffold handoff; A-5 → design-phase PR commits RFC +
`.specs/` together (References note); A-6 → `init → design` checkpoint recorded
(`orchestrator/project.yaml`, manifest_rev 1); A-7 → `.specs` link fixes + import preamble;
A-8 → bridge follow-up; A-9 → source-tree placement note.

On acceptance the **state_writer** (`enterprise-architect`, ADR-0025 — not the acting
tech-lead) records `delivery_state.refs.rfcs += 0001`.

## References

- Imported specification: [.specs/d4np-php.md](../../.specs/d4np-php.md) (v2.0, 2026-07-14, reviewed draft)
- Imported decisions: [ADR-001 — minimal PSR-11 container](../../.specs/d4np_php_adr_001_di_container.md) · [ADR-002 — HTTP wrappers + PSR-7 bridge](../../.specs/d4np_php_adr_002_http_psr7.md)
- Committed-revision note (A-5): the design-phase PR commits this RFC **together with** the
  imported `.specs/` sources and the manifest, so the approval binds to a committed spec
  revision rather than untracked files; the `docs/specs/` freeze at scaffold carries the
  naming-mapping preamble (A-7).
- Manifest: [orchestrator/project.yaml](../../orchestrator/project.yaml) (delivery_state: `init → design` recorded 2026-08-03, manifest_rev 1)
- Domain profile: [.eados-core/orchestrator/domains/software.yaml](../../.eados-core/orchestrator/domains/software.yaml)
- Language profile: [.eados-core/orchestrator/profiles/php.yaml](../../.eados-core/orchestrator/profiles/php.yaml)
- Protocol: [.eados-core/orchestrator/os/rfc/review-protocol.md](../../.eados-core/orchestrator/os/rfc/review-protocol.md)
