# Roadmap — egl-util-php

The project's plan as a numbered, checkbox-driven list. When an item completes in a PR,
flip its checkbox (`- [ ]` → `- [x]`) **in the same PR**. New work goes at the bottom of
its section with a fresh `<milestone>.<task>` number; never renumber.

Negotiated at the `plan` phase (2026-08-03) from **RFC-0001** — the only approved RFC
([docs/rfc/0001-egl-utils-library.md](docs/rfc/0001-egl-utils-library.md)) — by the
`product-manager` (priorities) / `tech-lead` (sizes + routes) / `producer` (reconciliation)
protocol; scope confirmed in full by the owner. Milestones are **sequential** (solo-owner
capacity; no parallel streams; no calendar dates by decision).

- **Versioning start:** pre-1.0 milestone-driven — one minor per milestone
  (M1 → `v0.1.0` … M7 → `v0.7.0`); the **1.0.0 decision is a dedicated post-M7
  API-freeze review**, not an automatic bump.
- **Session journal:** see [`docs/journal/`](docs/journal/). Latest checkpoint:
  [2026-08-03 — EADOS pipeline run and the Composer build system](docs/journal/2026/08/2026-08-03-pipeline-bootstrap-and-build-system.md).

## Model & effort routing (advisory)

An item may carry an advisory **route** — `route: <tier> / <effort>` — derived from its intake
signals through the `os/routing` policy's only-raise resolution (ADR-0017: start at the floor;
matched signals only ever raise, never lower). Tiers, cheapest → most capable:
`fast`, `standard`, `frontier-reasoning`. Efforts: `low`, `medium`, `high`, `extra`, `max`.
An item with no route takes the floor (`fast / low`). The route *recommends*; **the human keeps
final model authority** — switch with your host's own model control, never mid-session by the
agent. Signals in parentheses are asserted from RFC-0001/spec today and firm up as tracker
labels when items are filed as issues.

Tiers map to concrete models only through the dated catalog (as of **2026-07-27**;
a stale date is the review cue):

| Tier | Model (host: claude-code / Anthropic) |
|---|---|
| frontier-reasoning | Fable 5 |
| standard | Opus 5 |
| fast | Sonnet 5 |

Where the EADOS core is vendored (`.eados-core/`), the authoritative per-issue call once tracker
labels exist is `python .eados-core/tools/route_advice.py --issue <N>`.

---

## Milestone 1 — Project bootstrap & CI (`v0.1.0`) · size: M

The thinnest slice that compiles, tests, and ships under the full quality bar (RFC-0001).

- [x] 1.1 Lay down the build system (Composer, PSR-4 autoload) and a buildable skeleton under
      `src/main/php/d4np/utils/` (RFC-0001) — route: standard / medium
- [x] 1.2 Wire the test framework (PHPUnit) with one passing smoke test under
      `src/test/php/d4np/utils/` (RFC-0001) — route: fast / low
- [x] 1.3 Add formatter + linter configs (PHP-CS-Fixer PSR-12; PHPStan max level — Psalm not
      adopted per RFC-0001) at the repo root — route: fast / low
- [x] 1.4 Stand up the CI matrix (Linux: PHP 8.1, 8.2, 8.3) with build + test + format + lint
      (RFC-0001) — route: standard / medium
- [x] 1.5 Seed the version constant (`public const VERSION = 'X.Y.Z'`) in `Version.php`
      (RFC-0001) — route: fast / low
- [x] 1.6 Pin composer.json identity: name `egl/utils`, PSR-4 `D4np\Utils\` →
      `src/main/php/d4np/utils/`, require `php>=8.1` + `ext-pdo` + `ext-fileinfo` +
      `psr/container` + `psr/log` (RFC-0001 naming mapping + R-3) — route: standard / medium
      *(same file as 1.1: the identity IS the content of the composer.json 1.1 creates)*
- [x] 1.7 CI toolchain→version map written explicitly (the profile's 2-way ternary must not
      silently map 8.1 to 8.3 — RFC-0001 A-3) plus the `composer update --prefer-lowest` job —
      route: standard / medium
- [x] 1.8 Fix the doubled `version_file` path in `tools/consistency_lint.py` `CONFIG`
      (`src/main/php/d4np/utils/src/main/php/d4np/utils/Version.php` — the scaffold manifest
      passed a full path where the template prepends `SRC_MAIN`). Benign while the file is
      absent, but it silently disarms `version-lockstep` the moment 1.5 lands — the lesson
      L-0008 failure class. Fix with 1.5 and prove the gate can fail — route: standard / medium
- [x] 1.9 Harden the `benchmark / reproducible perf` CI job, which went red on item 1.1 and is
      **not** fixed by 1.2/1.3: (a) it runs `vendor/bin/phpbench`, a dev dependency no M1 item
      introduces — give it the same step-level config guard as `layering`/`mutation` so it
      self-enables when the benchmark suite lands (3.5 / 7.1); (b) its `php-version` expression
      reads `matrix.toolchain` but the job declares **no matrix**, so the ternary silently falls
      through to `'8.3'` — a rendering artifact of the profile setup steps being injected into a
      non-matrix job. Correct by hand (ADR-0003: this repo is never re-rendered) —
      route: standard / medium
- [ ] 1.10 Decide and record the **CI action-pinning policy** as an ADR, then make
      `.github/workflows/*.yml` consistent with it. Today the same action is pinned two ways in
      one file: the template-generated steps use a commit SHA with a version comment
      (`actions/checkout@3d3c42e5… # v7.0.1`) while the manifest-authored quality jobs use a
      floating tag (`actions/checkout@v7`). Under the enterprise posture a supply-chain choice
      is a security-relevant decision and needs an ADR (§7) — including whether
      `shivammathur/setup-php@v2` stays deliberately tag-pinned and Dependabot-managed.
      Checked first (lesson L-0004): no ADR in this repo decides it — EADOS's own ADR-0009
      governs the factory, not this self-governing repository — so it is genuinely open, not a
      re-discovered trade-off — route: standard / medium

---

## Milestone 2 — Support layer (`v0.2.0`) · size: M

The foundation every group depends on — the deptrac-legal bottom layer (RFC-0001).

- [ ] 2.1 Exception hierarchy: `UtilsException` root + Database/Hydration/Http/File/Json
      children, incl. `MissingKeyException` (RFC-0001) (sets-pattern) —
      route: frontier-reasoning / high
- [ ] 2.2 `Str`: `slug()` with ext-intl transliteration fallback, `uuid()` v4, `random()`
      CSPRNG (RFC-0001) — route: fast / low
- [ ] 2.3 `File`: flock-guarded `write()`/`read()`, atomic write via temp+rename, Fileinfo
      `mime()` (RFC-0001) (severity:medium — concurrency/atomicity semantics) —
      route: standard / medium
- [ ] 2.4 `Env::get()` with boolean coercion; `Json::encode()/decode()` wrapping native
      `\JsonException` (RFC-0001 R-7) — route: fast / low
- [ ] 2.5 Shared reflection-metadata cache, consumed by the Dto hydrator and the Container
      (RFC-0001 R-2) (sets-pattern) — route: frontier-reasoning / high
- [ ] 2.6 T-05 property tests: Json round-trips, slug idempotence, Env coercion table
      (RFC-0001) — route: fast / low

---

## Milestone 3 — DTO & data mapping (`v0.3.0`) · size: L

Typed, mass-assignment-safe data transfer (RFC-0001).

- [ ] 3.1 `DataTransferObject`: strict/lenient hydration, `MissingKeyException` semantics,
      nested DTOs, `Collection<T>` properties (RFC-0001 R-4) (sets-pattern) —
      route: frontier-reasoning / high
- [ ] 3.2 `WithersTrait` with per-version readonly-clone handling 8.1→8.3 (RFC-0001)
      (severity:medium) — route: standard / medium
- [ ] 3.3 `Collection<T>` with `@template` discipline enforced by PHPStan max (RFC-0001)
      (severity:medium) — route: standard / medium
- [ ] 3.4 T-01 hydration matrix suite (RFC-0001) — route: fast / low
- [ ] 3.5 phpbench: NFR-01 hydration + NFR-04 memory benchmarks (RFC-0001) (step:optimize) —
      route: fast / medium

---

## Milestone 4 — Database (`v0.4.0`) · size: L

Safe-by-default PDO access (RFC-0001).

- [ ] 4.1 `DatabaseConnection` with pinned defaults: ERRMODE_EXCEPTION, utf8mb4, real prepares,
      FETCH_ASSOC (RFC-0001) (severity:high — core guarantee) — route: standard / high
- [ ] 4.2 `QueryBuilder`: bound values, identifier allowlist + driver quoting, `Sort` enum,
      int-cast LIMIT/OFFSET (RFC-0001) (security — protected floor) —
      route: frontier-reasoning / extra
- [ ] 4.3 `Transaction`: closure scope, rollback+rethrow, savepoints (RFC-0001)
      (severity:medium) — route: standard / medium
- [ ] 4.4 T-02 injection suite (values / identifiers / LIKE wildcards) + T-04 transaction
      semantics (RFC-0001) (security — the protected floor holds in the test step) —
      route: frontier-reasoning / extra
- [ ] 4.5 phpbench: NFR-03 build-time benchmark (RFC-0001) (step:optimize) — route: fast / medium

---

## Milestone 5 — Security (`v0.5.0`) · size: M

Explicit-mechanism security helpers (RFC-0001; every item carries the protected `security` floor).

- [ ] 5.1 `Escaper`: html/attr/js/url context escaping (RFC-0001) (security) —
      route: frontier-reasoning / extra
- [ ] 5.2 `Sanitizer::richText()` over symfony/html-sanitizer (optional dep) +
      `sqlLikePattern()` (RFC-0001) (security) — route: frontier-reasoning / extra
- [ ] 5.3 `Hash`: Argon2id default, `bcryptFallback` policy, `needsRehash()` (RFC-0001)
      (security) — route: frontier-reasoning / extra
- [ ] 5.4 OWASP XSS corpus snapshot suite + DOM-bypass corpus for `richText()` (RFC-0001)
      (security) — route: frontier-reasoning / extra
- [ ] 5.5 Hash matrix tests (argon2id/bcrypt fallback, rehash triggers) + NFR-05 timing
      (RFC-0001) (security) — route: frontier-reasoning / extra

---

## Milestone 6 — HTTP, container, errors (`v0.6.0`) · size: L

The application-facing groups (RFC-0001).

- [ ] 6.1 `Request`/`Response` typed wrappers, PSR-7-mirroring naming (RFC-0001)
      (severity:medium) — route: standard / medium
- [ ] 6.2 `Session` hardening + `regenerate()`; `CsrfToken` — CSPRNG, `hash_equals`, per-form
      scoping; Http placement per RFC-0001 R-1 (security) — route: frontier-reasoning / extra
- [ ] 6.3 T-03 session/CSRF integration suite against a real `php -S` process (RFC-0001)
      (security) — route: frontier-reasoning / extra
- [ ] 6.4 `Container` (PSR-11) + `ServiceProvider`; NFR-02 benchmarks (RFC-0001, imported
      ADR-001) (severity:medium) — route: standard / medium
- [ ] 6.5 `Result`, PSR-3 `Logger`, `ExceptionHandler` with env-gated trace policy (RFC-0001)
      (severity:medium) — route: standard / medium

---

## Milestone 7 — Release engineering & bridge (`v0.7.0`) · size: M

Ship `egl/utils` and settle the bridge (RFC-0001). The **1.0.0 decision** follows as a
dedicated post-M7 API-freeze review.

- [ ] 7.1 phpbench nightly CI harness (NFR-06 methodology) with >10% regression failure
      (RFC-0001) (severity:medium) — route: standard / medium
- [ ] 7.2 `roave/backward-compatibility-check` wired to release PRs; deprecation policy
      documented (RFC-0001) (severity:medium) — route: standard / medium
- [ ] 7.3 Signed tags → validated release action → Packagist publish (RFC-0001)
      (severity:high — supply-chain surface) — route: standard / high
- [ ] 7.4 `egl/utils-psr7-bridge` packaging decision: subtree vs second repository (possibly a
      second EADOS run) + ADR-002's conversion contract tests (RFC-0001 A-8)
      (adr, decision-heavy) — route: frontier-reasoning / extra

---

## Spec Coverage Map

Tracks which spec section is fulfilled by which roadmap item(s). Sections follow the frozen
spec shape (scaffold renders `docs/specs/`; source: `.specs/d4np-php.md` v2.0 via RFC-0001).
Legend: ⏳ not started · 🚧 in progress · ✅ done · ❎ N/A.

| Spec § | Requirement | Roadmap items | Status |
|--------|-------------|---------------|--------|
| §1 | Objective & design philosophy | 1.1, 1.6 | ⏳ |
| §2 | Functional items 1–25 (+9b) | 2.1–2.5, 3.1–3.3, 4.1–4.3, 5.1–5.3, 6.1–6.2, 6.4–6.5 | ⏳ |
| §3 | Architecture & layering (deptrac) | 1.1, 1.6, 2.1, 2.5 | ⏳ |
| §4 | NFR budgets & benchmark methodology | 3.5, 4.5, 5.5, 6.4, 7.1 | ⏳ |
| §5 | Security test criteria | 4.4, 5.4, 5.5, 6.3 | ⏳ |
| §6 | API example / public interface | 1.6, 3.1 | ⏳ |
| §7 | Verification & test strategy | 1.2, 2.6, 3.4, 4.4, 6.3 | ⏳ |
| §8 | CI/CD & release engineering | 1.4, 1.7, 7.1–7.3 | ⏳ |
| §9 | Decision log (imported + seeded ADRs) | 2.1, 5.3, 7.4 | ⏳ |
