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
  [2026-08-04 — The same shape as NFR-01, found in a different class](docs/journal/2026/08/2026-08-04-nfr03-querybuilder-bench.md).

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
- [x] 1.10 Decide and record the **CI action-pinning policy** as an ADR, then make
      `.github/workflows/*.yml` consistent with it. Today the same action is pinned two ways in
      one file: the template-generated steps use a commit SHA with a version comment
      (`actions/checkout@3d3c42e5… # v7.0.1`) while the manifest-authored quality jobs use a
      floating tag (`actions/checkout@v7`). Under the enterprise posture a supply-chain choice
      is a security-relevant decision and needs an ADR (§7) — including whether
      `shivammathur/setup-php@v2` stays deliberately tag-pinned and Dependabot-managed.
      Checked first (lesson L-0004): no ADR in this repo decides it — EADOS's own ADR-0009
      governs the factory, not this self-governing repository — so it is genuinely open, not a
      re-discovered trade-off — route: frontier-reasoning / extra (adr, decision-heavy)
      *(route corrected when the item was taken: it was filed as `standard / medium`, but an
      item whose deliverable IS a decision is decision-heavy by definition — `os/routing`
      resolves `label:adr` to frontier-reasoning/extra. Settled by ADR-0003.)*
- [x] 1.11 Gate the ADR-0003 pinning policy mechanically instead of by review: assert every
      `uses:` in `.github/workflows/*.yml` matches `@[0-9a-f]{40} # <version>`, and that the
      version comment resolves to that SHA upstream (lesson L-0011 — a label nobody resolves
      lies for as long as nobody resolves it). Prove the check can fail before trusting it —
      route: standard / medium

---

## Milestone 2 — Support layer (`v0.2.0`) · size: M

The foundation every group depends on — the deptrac-legal bottom layer (RFC-0001).

- [x] 2.1 Exception hierarchy: `UtilsException` root + Database/Hydration/Http/File/Json
      children, incl. `MissingKeyException` (RFC-0001) (sets-pattern) —
      route: frontier-reasoning / high · **ADR-0004**
- [x] 2.2 `Str`: `slug()` with ext-intl transliteration fallback, `uuid()` v4, `random()`
      CSPRNG (RFC-0001) — route: fast / low
- [x] 2.3 `File`: flock-guarded `write()`/`read()`, atomic write via temp+rename, Fileinfo
      `mime()` (RFC-0001) (severity:medium — concurrency/atomicity semantics) —
      route: standard / medium · **ADR-0005**
- [x] 2.4 `Env::get()` with boolean coercion; `Json::encode()/decode()` wrapping native
      `\JsonException` (RFC-0001 R-7) — route: fast / low
- [x] 2.5 Shared reflection-metadata cache, consumed by the Dto hydrator and the Container
      (RFC-0001 R-2) (sets-pattern) — route: frontier-reasoning / high · **ADR-0006**
- [x] 2.6 T-05 property tests: Json round-trips, slug idempotence, Env coercion table
      (RFC-0001) — route: fast / low. All three landed with their own items (2.2, 2.4);
      this tags them `#[Group('T-05')]` so `vendor/bin/phpunit --group T-05` runs
      spec §7's named suite as a runnable, countable unit rather than a docblock claim.
- [x] 2.7 Measure and enforce the coverage floor. `AGENTS.md` §10 and spec NFR-07 both state
      **≥ 90% line coverage**, and nothing checked it: the `build` job set up `pcov` but ran
      `vendor/bin/phpunit` with no `--coverage` flag and no threshold, so the number was neither
      produced nor compared. PHPUnit 10 has no fail-under option (a dozen `--fail-on-*` switches,
      no coverage threshold), so this needed a Clover report plus `tools/coverage_gate.py`.
      Settled by **ADR-0007**, which also finalizes what `AGENTS.md` §10 deferred: the figure is
      **total** line coverage, not per-diff, and the tool says so on every run. Proved the gate
      can fail on all five paths — route: standard / medium

---

## Milestone 3 — DTO & data mapping (`v0.3.0`) · size: L

Typed, mass-assignment-safe data transfer (RFC-0001).

- [x] 3.1 `DataTransferObject`: strict/lenient hydration, `MissingKeyException` semantics,
      nested DTOs, `Collection<T>` properties (RFC-0001 R-4) (sets-pattern) —
      route: frontier-reasoning / high · **ADR-0008**. *`Collection<T>` hydration is NOT in
      this item: `Collection` itself is 3.3, and ADR-0006 deliberately placed the docblock
      generic parser it needs there. Everything else — strict/lenient, R-4 optionality, nested
      DTOs, path-carrying type mismatches — is done and covered by the T-01 suite.*
- [x] 3.2 `WithersTrait` with per-version readonly-clone handling 8.1→8.3 (RFC-0001)
      (severity:medium) — route: standard / medium · **ADR-0009**. *No per-version branch was
      needed: measured, PHP 8.3's readonly amendment only allows reassignment **inside**
      `__clone()`, still an error on 8.1/8.2, while rebuilding through the constructor works
      identically on all three — and additionally preserves constructor validation, which a
      clone bypasses.*
- [x] 3.3 `Collection<T>` with `@template` discipline enforced by PHPStan max (RFC-0001)
      (severity:medium) — route: standard / medium · **ADR-0010**. *Also closes the
      `Collection<T>` hydration gap item 3.1 deferred here — via a `#[CollectionOf]` attribute
      rather than the docblock parser ADR-0006 anticipated, because a docblock yields an
      unresolvable alias token while an attribute argument arrives already resolved by PHP.*
- [x] 3.4 T-01 hydration matrix suite (RFC-0001) — route: fast / low. *Most of the matrix
      (nested, nullables, strict/lenient, withers, missing-key, collections) already existed,
      landed with items 3.1–3.3. The one gap: spec §7 names "enums" and nothing had hydrated
      one. Closed here — a backed enum resolves from its scalar value via `tryFrom()`; a pure
      enum has no scalar to resolve from and stays instance-only, verified by planting a
      pure-enum fixture and confirming the distinction is enforced.*
- [x] 3.5 phpbench: NFR-01 hydration + NFR-04 memory benchmarks (RFC-0001) (step:optimize) —
      route: fast / medium · **ADR-0011**. *`HydrationBench`/`MemoryBench` measure both NFRs for
      the first time. NFR-04's 16 MiB budget is a real, enforced `@Assert` (comfortable
      headroom). NFR-01's ≤3× ratio can't be an `@Assert` — phpbench's `baseline` means a
      previous tagged run, not a sibling subject — so `tools/bench_ratio_gate.py` reports it
      standalone instead. Measured ratio: **~15.4×**, well over budget; shipped non-blocking per
      the maintainer's explicit choice, with the gap filed as item 3.7. Absolute-µs/nightly
      regression tracking stays item 7.1's job, unchanged.*
- [x] 3.6 Add `deptrac.yaml` and turn on the layering gate. RFC-0001's dependency rule — *groups
      depend downward on Support only; no cross-group imports* — has had nothing enforcing it:
      the CI `layering` job self-skips until the config lands (the step-level guard from item
      1.9). Until item 3.1 there was only one group, so the rule was vacuous; now that `Dto`
      depends on `Support` it is a real constraint with a real direction, and the next four
      milestones each add a group that could violate it. Prove the gate can fail by planting a
      cross-group import — route: standard / medium · **ADR-0012**. *Layers collected by
      `directory` (bound to the tree RFC-0001 §A-9 fixes, not a second name-regex vocabulary),
      over `src/main` only. Proved in all three directions: `Support→Dto` (the inversion ADR-0006
      and ADR-0010 designed around) fails, `Http→Dto` (peer) fails, `Http→Support` (allowed)
      passes — so the gate distinguishes rather than just rejecting. 0 violations, **0 uncovered**,
      33 allowed. deptrac held at `^4.4` by the 8.1 platform pin; 4.7 needs PHP 8.2.*
- [x] 3.7 Close NFR-01's ratio gap: hydration currently measures ~15.4× the cost of manual
      constructor assignment against a ≤3× budget (item 3.5, **ADR-0011**). Likely a
      compiled/cached-closure hydration strategy — generate and cache a per-class hydration
      closure alongside `ClassMetadata` instead of walking `ParameterMetadata` and coercing on
      every call — evaluated on its own merits with its own measurement-first discipline —
      route: standard / high · **ADR-0013**. *Closed: **15.40× → 2.74×** (14.155 µs → 2.511 µs),
      meeting both halves of NFR-01, recorded in
      [`docs/benchmarks/`](docs/benchmarks/2026/08/nfr01-hydration-compiled-closure.md). Measured
      four approaches first: the budget is **unreachable** by tuning the interpreted loop (best
      case 4.80×), so a generated closure it is — but scoped narrowly to the all-scalar shape
      NFR-01 actually names, with the interpreter kept for nested DTOs, collections, enums, unions
      and defaults, and `HydrationParityTest` holding the two to identical observable behavior.
      NFR-04 improved for free (149.7 ms → 37.8 ms). Honest limit: only the eligible shape is
      fast.*

---

## Milestone 4 — Database (`v0.4.0`) · size: L

Safe-by-default PDO access (RFC-0001).

- [x] 4.1 `DatabaseConnection` with pinned defaults: ERRMODE_EXCEPTION, utf8mb4, real prepares,
      FETCH_ASSOC (RFC-0001) (severity:high — core guarantee) — route: standard / high ·
      **ADR-0014**. *Wraps a **consumer-owned** PDO per RFC-0001, and **refuses** a connection that
      will not take a pinned default rather than degrading silently — `PDO::setAttribute()` signals
      refusal by returning `false`, not by throwing, so the natural implementation would let a
      security default fail invisibly. `false` is disambiguated by reading the attribute back:
      SQLite has no emulation concept (fine), a driver still emulating is refused. Order is
      load-bearing: real prepares must be off before `SET NAMES utf8mb4`, because `SET NAMES` is
      only safe once there is no client-side escaping left to fool. Honest gap: the MySQL-only
      `SET NAMES` path is unexecuted by the suite (no MySQL in CI) — T-02 (item 4.4) owns that.*
- [x] 4.2 `QueryBuilder`: bound values, identifier allowlist + driver quoting, `Sort` enum,
      int-cast LIMIT/OFFSET (RFC-0001) (security — protected floor) —
      route: frontier-reasoning / extra · **ADR-0015**. *Route mismatch accepted by the maintainer
      and recorded (`record_run.py --route-mismatch "frontier-reasoning/extra=standard"`).
      **Finding: FR-07's allowlist `^[A-Za-z_][A-Za-z0-9_]*$` transcribed literally is a bypass** —
      PCRE's `$` matches before a trailing newline, so `"id\n"` rendered as
      `SELECT "id\n" FROM "users"`. Anchored with `\z`; the spec's intent implemented rather than
      its notation copied. Also adds `Operator` as an enum — FR-07 closes the ORDER BY keyword for
      a reason that applies identically to comparison operators, which it does not mention. 17
      hostile payloads × 4 identifier surfaces; suite proved non-vacuous with 4 planted defects.
      Closes half of item 4.1's declared gap: `SET NAMES utf8mb4` is now asserted issued for MySQL
      and not for others.*
- [x] 4.3 `Transaction`: closure scope, rollback+rethrow, savepoints (RFC-0001)
      (severity:medium) — route: standard / medium · **ADR-0016**. *Savepoints are not an
      optimisation but the only mechanism: a nested `beginTransaction()` **throws** (probed), it
      does not nest or no-op. Catches `Throwable`, not `Exception` — a `TypeError` leaves the same
      half-written state. A failing rollback is swallowed so the closure's exception survives:
      PHP has no suppressed-exception mechanism, so the choice is strictly between losing the
      cause and losing the cleanup failure. Savepoint names are generated from a monotonic
      counter, never caller-influenced. Documented caveat no wrapper can fix: MySQL DDL causes an
      implicit commit mid-closure.*
- [x] 4.4 T-02 injection suite (values / identifiers / LIKE wildcards) + T-04 transaction
      semantics (RFC-0001) (security — the protected floor holds in the test step) —
      route: frontier-reasoning / extra · **ADR-0017**. *Route mismatch accepted and recorded.
      T-02's **query-log** clause was the missing piece and is not ceremony: measured, the
      round-trip assertions items 4.1-4.3 had **miss a correctly-escaping interpolation in 28 of
      29 cases**, while the query-log assertion catches it in 28 of 29. Proof point is the PDO
      boundary via `ATTR_STATEMENT_CLASS`, which is sufficient *because* ADR-0014 pins real
      prepares — with no client-side interpolation, placeholder-only text at that boundary is
      placeholder-only text on the wire. 29 payloads × 6 value paths. **T-02 is NOT complete:**
      its LIKE-wildcard leg needs `Sanitizer::sqlLikePattern()` (FR-10, item 5.2) and a test
      asserts that gap explicitly rather than leaving silence. T-04 needed no new tests — item 4.3
      delivered it; verified against the spec text rather than padded.*
- [x] 4.5 phpbench: NFR-03 build-time benchmark (RFC-0001) (step:optimize) — route: fast / medium
      · **ADR-0018**. *`QueryBuilderBench` measures the timing half (5-condition SELECT); the
      "0 queries" half is a direct assertion (`testBuildingNeverRunsAQuery`, reusing item 4.4's
      `QueryLog` fixture) rather than a benchmark, since timing cannot prove an absence. Measured:
      **~23µs against the ≤10µs budget**, attributed to ~1µs per identifier quoted (allowlist +
      driver-quote, ADR-0015) across 12 identifiers, plus per-call cloning from the same ADR's
      immutability. Same shape as item 3.5's NFR-01 finding; handled the same way per ADR-0011's
      precedent — shipped non-blocking, real number recorded, absolute enforcement stays item
      7.1's job, gap filed as item 4.6 rather than fixed under this item's `fast/medium` route.*
- [ ] 4.6 Close NFR-03's build-time gap: a 5-condition `QueryBuilder` SELECT currently measures
      ~23µs against a ≤10µs budget (item 4.5, **ADR-0018**). Most plausibly caching the `driver()`
      value the constructor already resolves once, rather than the repeated `PDO::getAttribute()`
      call currently paid per identifier quoted — without weakening the allowlist (ADR-0015) or the
      immutability guarantee. Needs its own measure-first pass — route: TBD (route at pickup)

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
| §2 | Functional items 1–25 (+9b) | 2.1–2.5, 3.1–3.3, 4.1–4.3, 5.1–5.3, 6.1–6.2, 6.4–6.5 | 🚧 |
| §3 | Architecture & layering (deptrac) | 1.1, 1.6, 2.1, 2.5 | ⏳ |
| §4 | NFR budgets & benchmark methodology | 3.5, 4.5, 5.5, 6.4, 7.1 | ⏳ |
| §5 | Security test criteria | 4.4, 5.4, 5.5, 6.3 | ⏳ |
| §6 | API example / public interface | 1.6, 3.1 | ⏳ |
| §7 | Verification & test strategy | 1.2, 2.6, 3.1, 3.4, 4.4, 6.3 | 🚧 |
| §8 | CI/CD & release engineering | 1.4, 1.7, 7.1–7.3 | ⏳ |
| §9 | Decision log (imported + seeded ADRs) | 2.1, 5.3, 7.4 | 🚧 |
