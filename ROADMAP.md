# Roadmap — egl-util-php

The project's plan as a numbered, checkbox-driven list. When an item completes in a PR,
flip its checkbox (`- [ ]` → `- [x]`) **in the same PR**. New work goes at the bottom of
its section with a fresh `<milestone>.<task>` number; never renumber.

Negotiated at the `plan` phase (2026-08-03) from **RFC-0001** — the only approved RFC
([docs/rfc/0001-egl-utils-library.md](docs/rfc/0001-egl-utils-library.md)) — by the
`product-manager` (priorities) / `tech-lead` (sizes + routes) / `producer` (reconciliation)
protocol; scope confirmed in full by the owner. Milestones are **sequential** (solo-owner
capacity; no parallel streams; no calendar dates by decision).

Extended at a second `plan` pass (2026-08-06) from **RFC-0002**
([docs/rfc/0002-application-layer-groups-from-legacy-intake.md](docs/rfc/0002-application-layer-groups-from-legacy-intake.md),
approved 2026-08-05 at PR #49) by the same three-role protocol — all roles worn by the
session agent, per-artifact authority checked and recorded in the journal; scope = RFC-0002's
decision in full, owner-confirmed by the RFC approval and the explicit plan instruction.
**M9–M12 map to core versions `v0.8.0`–`v0.11.0`** (M8 was bridge-scoped and consumed no core
minor); the standing post-M7 **1.0.0 API-freeze review** may re-map them to 1.x MINORs — the
items are additive either way, so the mapping shifts without rework.

- **Versioning start:** pre-1.0 milestone-driven — one minor per milestone
  (M1 → `v0.1.0` … M7 → `v0.7.0`); the **1.0.0 decision is a dedicated post-M7
  API-freeze review**, not an automatic bump.
- **Session journal:** see [`docs/journal/`](docs/journal/). Latest checkpoint:
  [2026-08-06 — Measuring the overhead a gateway is honest about](docs/journal/2026/08/2026-08-06-gateway-bench.md).

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
- [x] 4.6 Close NFR-03's build-time gap: a 5-condition `QueryBuilder` SELECT currently measures
      ~23µs against a ≤10µs budget (item 4.5, **ADR-0018**) — route: fast / medium (run at
      frontier: the change touches ADR-0015's allowlist, so a perf edit here has a security
      failure mode; mismatch recorded) · **ADR-0020**. ***The premise was wrong on both counts,
      and profiling first is what found it.*** *The named hypothesis (`driver()` lookups) is
      ~1.5-2.5µs of ~23µs, not the gap. More importantly the **workload was wrong**: item 4.5's
      subject added a 5-column `select()`, `orderBy`, `limit` and `offset` on top of its five
      conditions — twelve identifiers where NFR-03 names five conditions — so ~2/3 of the reported
      gap was benchmark scope, not builder cost. Subject corrected (heavier shape **kept and still
      published**, so this is not a benchmark narrowed until it passed). Two changes: driver
      resolved once per builder (7→1 and 13→1 lookups, pinned by an exact **count** since the
      saving is sub-noise) and concatenation over `sprintf`. **14.430 → 12.979µs (−10.1%).**
      **NFR-03 is still NOT met** — ~30% over, vs the 2.3× reported. Residual deferred to item 7.1
      because the instruments disagree by ~3.8µs (phpbench 12.979 vs in-process 9.246; an empty
      phpbench subject costs 0.079µs, so not harness overhead) — more than the ~3.0µs overage, so
      whether NFR-03 passes depends on which instrument is authoritative, which is NFR-06/7.1's
      question.*

---

## Milestone 5 — Security (`v0.5.0`) · size: M

Explicit-mechanism security helpers (RFC-0001; every item carries the protected `security` floor).

- [x] 5.1 `Escaper`: html/attr/js/url context escaping (RFC-0001) (security) —
      route: frontier-reasoning / extra · **ADR-0019**. *Four methods, deliberately no general
      `escape()` — a wrapper that guessed the context is the one that gets used in a `<script>`
      block. **`attr()` assumes the attribute is UNQUOTED** (`&#xHH;` for every non-alphanumeric
      ASCII): an escaper cannot see its call site, and the two assumptions are asymmetric —
      assuming quoted is wrong toward an XSS hole, assuming unquoted is wrong toward verbosity.
      `js()` escapes `/` (`</script>` ends the element regardless of JS string state) and
      U+2028/U+2029 (JS line terminators pre-ES2019). Probed: without `ENT_SUBSTITUTE`,
      `htmlspecialchars()` returns **`''`** for invalid UTF-8 — total silent data loss — so all
      four substitute U+FFFD identically, via PCRE rather than `mbstring` (undeclared dependency,
      and it substitutes `?` not U+FFFD). Suite proved non-vacuous with 7 planted defects. OWASP
      corpus snapshot stays item 5.4.*
- [x] 5.2 `Sanitizer::richText()` over symfony/html-sanitizer (optional dep) +
      `sqlLikePattern()` (RFC-0001) (security) — route: frontier-reasoning / extra ·
      **ADR-0021**. ***Closes spec §7's T-02 suite***, whose third leg item 4.4 deferred here.
      *Escape character is **`!`, not `\`** — probed: `ESCAPE ''` is a **parse error** on SQLite,
      so a backslash needs per-driver spelling. Bigger trap: an escaped pattern **without** an
      `ESCAPE` clause silently matches **nothing** on SQLite while working by accident on
      MySQL/Postgres, so `QueryBuilder::whereLike()` emits the clause — and that silent failure is
      itself now a test. `richText()` **throws** when the optional package is absent rather than
      returning input unsanitized (verified behaviourally), and keeps Symfony types out of its
      signature so "optional" holds at the API boundary. First third-party production dependency:
      took the layering gate to `Uncovered: 6`, resolved by giving it its **own deptrac layer only
      `Security` may reach** (planted `Http→HtmlSanitizer` to confirm). Honest gap: the
      missing-dependency path is probe-verified but has no permanent test, since the package is a
      dev dependency.*
- [x] 5.3 `Hash`: Argon2id default, `bcryptFallback` policy, `needsRehash()` (RFC-0001)
      (security) — route: frontier-reasoning / extra *(run at standard tier; mismatch accepted by
      the maintainer and recorded)* · **ADR-0022**. *Probed: **`PASSWORD_DEFAULT` is bcrypt**
      (`'2y'`) even where Argon2id is available — code reaching for it expecting "whatever is
      strongest" silently gets the weaker algorithm, which is why FR-11 names Argon2id. An
      instance, not a static helper like `Escaper`/`Sanitizer`: it carries a **policy** and a
      **collaborator**, and security configuration that can change mid-request is the wrong shape.
      Fallback decided **once at construction** — `false` refuses to construct (fail fast means at
      wiring time, not first registration), `true` logs one WARNING rather than one per hash. The
      fallback is also a **value** (`algorithm()`), so a deployment without a logger can still
      detect it. The fallback branch is unreachable in-process (`defined()` cannot be un-defined),
      which a probe **passing** exposed and the coverage gate then forced to a real fix: the policy
      is extracted as `selectAlgorithm()` — a pure function taking availability as an argument — so
      the refusal, the bcrypt selection and the WARNING **level** are all asserted. The seam exposes
      the **decision, not the weak algorithm**: there is still no way to hash with bcrypt on a
      capable build. Item 5.5 keeps NFR-05 timing and the wider matrix.*
- [x] 5.4 OWASP XSS corpus snapshot suite + DOM-bypass corpus for `richText()` (RFC-0001)
      (security) — route: frontier-reasoning / extra *(run at standard tier; mismatch recorded)* ·
      **ADR-0023**. *Snapshot **and** invariants, never one alone: a snapshot proves stability, not
      safety — a snapshot of broken output is a valid snapshot. Re-recording is deliberate
      (`UPDATE_SNAPSHOTS=1`), never automatic, because an assertion that repairs itself is not an
      assertion. For mXSS the load-bearing check is **idempotence**: mutation payloads are inert
      when parsed once and executable after re-parse, so "no `<script>` in the output" cannot see
      them — instability under re-parse is the signature. Also a "destroys everything" assertion,
      since `return '';` passes every security check. **Corrects ADR-0021**: a probe allowing
      `javascript:` **passed** — symfony refuses it unconditionally — so the allowlist is the sole
      barrier only for `data:`, defence-in-depth for `javascript:`. 1010 tests, `--group T-06` 386.*
- [x] 5.5 Hash matrix tests (argon2id/bcrypt fallback, rehash triggers) + NFR-05 timing
      (RFC-0001) (security) — route: frontier-reasoning / extra *(run at standard tier; mismatch
      recorded)* · **ADR-0024**. ***Closes Milestone 5.*** *NFR-05 is a **range**, unlike every
      other budget here — falling *below* 50ms would be the serious failure, since it means the work
      factor is inadequate. So it is split by what each half can prove: the **security** half is
      asserted as the **work factor** (`memory_cost`/`time_cost` vs OWASP's floor), which is
      machine-independent and catches what a stopwatch cannot — a stopwatch cannot tell weak
      parameters on fast hardware from strong ones on slow. The **capacity** half is a benchmark
      asserting nothing. Measured `make()` **349ms vs the 50-200ms range** — over, and deliberately
      **not** fixed: the parameters are PHP's defaults and clear OWASP's floor, so lowering them
      would trade security for latency. Also measures `verify()` (~348ms), which NFR-05 does not
      budget but which runs on **every login** and is what actually caps auth throughput.
      Documented PHP quirk: `needsRehash()` is `true` for **stronger** parameters too, so a hardened
      hash is silently downgraded on next login.*

---

## Milestone 6 — HTTP, container, errors (`v0.6.0`) · size: L

The application-facing groups (RFC-0001).

- [x] 6.1 `Request`/`Response` typed wrappers, PSR-7-mirroring naming (RFC-0001)
      (severity:medium) — route: standard / medium · **ADR-0025**. *Opens Milestone 6. **The typed
      accessors refuse rather than coerce**, which is the security decision here: `?email[]=x` gives
      the same key a different PHP type, chosen by the client, and a `(string)` cast yields the
      literal `"Array"` while `implode()` invents a value nobody sent — both turn attacker-chosen
      *shape* into a trusted value. Scalar accessors return their default instead;
      `queryList()`/`postList()` exist for when a list is genuinely expected. `FILTER_VALIDATE_INT`
      not a cast (`(int) "12abc"` is 12). Headers from `$_SERVER` since `getallheaders()` is
      Apache-only; `isSecure()` ignores `X-Forwarded-Proto` (client-supplied absent a trusted
      proxy) but does handle the `'off'` string. `Response` refuses CR/LF/NUL in header values
      (response splitting) **at set time, not send time**, and stores names case-insensitively so a
      duplicate `Content-Type` cannot be smuggled past a proxy. PHPStan caught a type annotation
      that was a lie: `?0=zero` yields an **integer** key, so superglobals are `array<array-key>`.*
- [x] 6.2 `Session` hardening + `regenerate()`; `CsrfToken` — CSPRNG, `hash_equals`, per-form
      scoping; Http placement per RFC-0001 R-1 (security) — route: frontier-reasoning / extra
      *(run at standard tier; mismatch recorded)* · **ADR-0026**. *One probe shaped the item: PHP
      will not run a session in CLI — `session_start()`, `session_set_cookie_params()` and
      `session_regenerate_id()` **all return `false`** — so the cookie policy is exposed as a pure
      **value** (`cookieParams()`) and `CsrfToken` takes a `SessionStore` seam, or FR-15's flags
      and all of CSRF validation would have had no unit assertion at all. **The finding:** replacing
      `hash_equals()` with `===` **passed the entire suite** — they return identical values and
      differ only in timing, so no behavioural test can see it. Now asserted as a **mechanism**
      (item 4.6's pattern). `SameSite` became an enum after PHPStan rejected a validated string —
      ADR-0015's reasoning, reached from the other direction. **Then the coverage gate found the
      same shape a second time:** `start()` must apply the cookie parameters **before** starting,
      and both orderings produce a working session — the wrong one just ships a cookie with none of
      FR-15's flags. Nothing observable separates them, so the session functions moved behind a
      `SessionApi` seam and a fake asserts the call sequence (**§8**). Honest gap remaining is
      genuinely behavioural — a real cookie, a real identifier — and item 6.3 owns it.*
- [x] 6.3 T-03 session/CSRF integration suite against a real `php -S` process (RFC-0001)
      (security) — route: frontier-reasoning / extra *(run at standard tier; mismatch recorded)* ·
      **ADR-0027**, **spec r2**. *17 behavioural tests against a live server, which is where
      everything ADR-0026 could not reach finally gets exercised — the flags on a real `Set-Cookie`,
      a real identifier rotating, cross-session token rejection. Two probe findings shaped it:
      **`Secure` is emitted over plain HTTP** (PHP writes the attribute unconditionally and leaves
      enforcement to the browser), which is what makes the suite possible without TLS; and reading a
      live process's pipe blocks forever, so the server's output goes to a file. **The spec was
      amended, with the maintainer's authorisation:** §7 asked for a `hash_equals` **timing test**,
      and measuring first showed the signal it needs is **+2.8 ns/op against 38 ns/op of noise** —
      ~13× below the noise floor locally, six orders of magnitude below request latency over HTTP.
      T-03 r2 now requires the **mechanism assertion** instead, stated positively and negatively
      across every secret-comparison path, with a registry that guards its own completeness. No
      standing deviation. **No production code changed in this item.***
- [x] 6.4 `Container` (PSR-11) + `ServiceProvider`; NFR-02 benchmarks (RFC-0001, imported
      ADR-001) (severity:medium) — route: standard / medium *(session model matched the route —
      no mismatch)* · **ADR-0028**. *Constructor autowiring over the shared `ReflectionCache`,
      singleton/factory/bind definitions, and imported ADR-001's non-goals enforced as refusals —
      unbound interfaces, abstract classes, built-in and untyped parameters, and union types are all
      declined with a message naming the parameter and the class. **NFR-02 met: 0.173 µs warm
      (≤ 2), 18.593 µs first autowired (≤ 30).** Getting there needed two benchmark fixes: phpbench
      runs `beforeMethods` once per *iteration*, so a cold subject must build its container inside
      the subject; and the first revolution was paying Composer's autoloading, which phpbench
      smeared across all revs — the tell was **93 µs at 200 revs vs 26 µs at 2000 for identical
      work**. Container exceptions live in the `Container` group, not `Support`, because `Support`
      depends on nothing and PSR-11 would have broken that (5 deptrac violations, probed). `get()`
      carries a **conditional return type** PSR-11 lacks, so consumers at PHPStan max are not taxed
      at every call site.*
- [x] 6.5 `Result`, PSR-3 `Logger`, `ExceptionHandler` with env-gated trace policy (RFC-0001)
      (severity:medium) — route: standard / medium *(session model matched the route)* ·
      **ADR-0029**. *Two decisions settled by probing, both defaults losing data silently:
      `file_put_contents()` with `LOCK_EX` on a `php://` stream returns **`false` and writes
      nothing**, so a logger locking every write would discard every console record — real files get
      the lock, streams do not, with a functional test over `php://output`; and a `Throwable` in a log
      context **encodes to `{}`** because `json_encode()` only sees public properties, so throwables
      are walked explicitly. `ExceptionHandler` does the opposite deliberately: production withholds
      the **message as well as the trace** (a message names schemas and paths just as effectively) and
      emits a reference that also lands in the log — **stricter than FR-18's letter, recorded as
      such**. `problem()` is a pure value because `http_response_code()` warns inside PHPUnit under
      `failOnWarning`. `Result` failures carry a `Throwable` so `orElseThrow()` rethrows the original
      instance with its original trace, and `map()` deliberately does **not** catch — `Result::try()`
      is the named opt-in.*

---

## Milestone 7 — Release engineering & bridge (`v0.7.0`) · size: M

Ship `egl/utils` and settle the bridge (RFC-0001). The **1.0.0 decision** follows as a
dedicated post-M7 API-freeze review.

- [x] 7.1 phpbench nightly CI harness (NFR-06 methodology) with >10% regression failure
      (RFC-0001) (severity:medium) — route: standard / medium *(session model matched the route)* ·
      **ADR-0030**, **benchmark record**. *NFR-06's gate could not be built as written, and the
      evidence was already in this repo's CI history: across nine `master` runs with `QueryBuilder`
      and its bench **provably unchanged**, one subject ranged **2.684–3.767 µs — 40.4% peak to
      peak**. A nightly 10% comparison would have failed most nights on nothing. Five consecutive
      passes inside **one** job spread **0.4–1.5%**, so the gate measures base and HEAD on the same
      runner via `git worktree` — the threshold was never the problem, the comparison was. Two gates:
      relative (>10%) and absolute ceilings, the latter because twenty commits at +9% each pass every
      relative check and still double the runtime. **All three deferred budgets are met** — NFR-01
      **0.958 µs / 2.40×**, NFR-03 **3.776 µs**, NFR-05 **148.3 ms** — and not because anything got
      faster: the earlier over-budget numbers came from `--php-disable-ini` on Windows, which discards
      the whole `php.ini`, not NFR-06's environment. The environment is now **asserted** rather than
      configured and hoped for. Still not the named reference CPU; NFR-04 stays ungated (a memory
      delta phpbench's `mem_peak` does not report) — both named, not quietly skipped.*
- [x] 7.2 `roave/backward-compatibility-check` wired to release PRs; deprecation policy
      documented (RFC-0001) (severity:medium) — route: standard / medium *(session model matched
      the route)* · **ADR-0031**. *Three obstacles, each found by trying: the tool's own PHP floor
      is **above this library's** (8.2+ from 8.7.0, later 8.3+/8.4+, against our pinned 8.1.34), so
      it is installed into a **throwaway project** rather than `composer.json` — the 8.1 matrix cell,
      `--prefer-lowest` and NFR-08 all stay untouched; upstream **ships no PHAR** any more; and it
      **hard-fails with zero tags** (`Could not detect any released versions`), which is exactly the
      state of this repo, so the job skips on a declared condition and self-enables at the first tag
      (lesson L-0010). A release PR is detected from a **`Version.php` diff**, not a label a
      maintainer could forget. The substance is `tools/bc_gate.py`: the checker cannot say whether a
      break is *allowed in this bump*, and pre-1.0 that matters — SemVer §4 permits a break in
      `0.7 → 0.8` and forbids the same break in `0.7.0 → 0.7.1`. **Also resolved a contradiction
      already in the repo:** `maintenance.md` said deprecations last "the rest of the current MAJOR
      line", the imported spec §8 says "one minor" — the spec wins, restated as **one full published
      MINOR**, with the pre-1.0 case it previously lacked.*
- [x] 7.3 Signed tags → validated release action → Packagist publish (RFC-0001)
      (severity:high — supply-chain surface) — route: standard / high *(session model matched the
      route)* · **ADR-0032**. *`release.yml` drafted whatever was tagged, checking only that
      `composer install` succeeded. Now three jobs: the tag must be **annotated and signed** (asked
      of GitHub's own verification, so no key material reaches the runner and no keyring goes stale
      on rotation), must **agree with the tree it points at**, and the tagged tree must pass on
      **8.1/8.2/8.3** — a tag can point at a commit CI never ran. Nothing is drafted until all of it
      passes, because a draft is publishable. `tools/release_gate.py` closes a hole no lint can
      reach: `consistency_lint` runs on a working copy and has **no tag**, so `git tag -a v0.2.0` on
      a tree whose constant says `0.1.0` ships a release that installs as one version and reports
      itself as another with nothing inside the tree disagreeing. **Found the item 1.9 defect in a
      second place** — `matrix.toolchain` referenced in a job with no matrix, silently falling
      through to '8.3'; the matrix is restored rather than the expression hardcoded, since the
      release must be tested on the 8.1 floor. Packagist **pulls** via its own integration rather
      than being pushed from CI: no Packagist token here, and AGENTS.md §11's line intact. Two
      one-time maintainer prerequisites are documented — a signing key on the GitHub account and the
      Packagist integration — and **the first release cannot succeed without them**.*
- [x] 7.4 `egl/utils-psr7-bridge` packaging decision: subtree vs second repository (possibly a
      second EADOS run) + ADR-002's conversion contract tests (RFC-0001 A-8)
      (adr, decision-heavy) — route: frontier-reasoning / extra *(run at the routed tier — the
      maintainer switched the session to Fable 5 rather than accepting a sixth mismatch)* ·
      **ADR-0033**, **spec 02 r1**. *Two findings reframed A-8's wording before options could be
      weighed: Packagist needs `composer.json` at a repository root, so a second repository exists
      under **every** option and the real question is authored vs **generated**; and the maintainer
      struck a phantom cost from the analysis — EADOS is an external generation tool, not repository
      governance, so "duplicating it" never belonged on the ledger. Decision: canonical source under
      **`packages/utils-psr7-bridge/`** in this monorepo (own composer.json, namespace
      `D4np\Utils\Bridge\Psr7\`, tests, static analysis, changelog); the split repository is a
      **generated, read-only publication target** — no PRs, no authored commits; **independent
      versioning by design, not inheritance**: `utils-psr7-bridge-vX.Y.Z` tags translate to
      `vX.Y.Z` on the split repo, signed at the source and verified before splitting (ADR-0032's
      mechanism). The load-bearing property is same-PR integration: a core change that breaks the
      conversion contract fails in the PR that introduces it — with the flip side named, a
      **release-mode** re-test against the released core before any bridge tag ships, because the
      committed constraint is a claim PR-mode evidence cannot support. Imported ADR-002's
      conversion contract is now **BFR-01…BFR-22**, including the two traps that make it more than
      ceremony: multiple `Set-Cookie` headers are refused rather than comma-joined (RFC 6265), and
      uploaded files cross the `$_FILES` ↔ stream boundary with no stream access on a failed
      upload. **A decision, not an implementation** — the ADR-002 contract *tests* move to item
      8.2, which is why the Spec Coverage Map's §7 row reopens. Milestone 8 carries the build.*

---

## Milestone 8 — PSR-7 bridge (`utils-psr7-bridge-v0.1.0`) · size: M

The implementation of ADR-0033's decision, specified end to end by
[`docs/specs/02_spec_psr7_bridge.md`](docs/specs/02_spec_psr7_bridge.md). **Bridge-scoped:** this
milestone versions under the bridge's own tag line (`utils-psr7-bridge-v0.1.0`), not the core's —
the core's minor-per-milestone rule (AGENTS.md §11) applies to milestones that change the core
package, and the core-side work here (a CI job, docs) is chore-level. The post-M7 `1.0.0`
API-freeze review for the **core** is unaffected by this milestone.

- [x] 8.1 Scaffold `packages/utils-psr7-bridge/` per spec 02 §2 (composer.json, PSR-4, quality
      bar, in-package changelog) + the self-enabling `bridge-contract` CI job (PR mode: path
      repository injected in the CI workspace only; guard on the package's composer.json existing,
      lesson L-0010) + the core deptrac rule that a core → `D4np\Utils\Bridge\` import is a build
      failure — route: standard / medium *(session model matched the route)*. *Worked in an
      isolated `git worktree`, since this checkout is shared with parallel sessions. **The deptrac
      rule fired on nothing at first:** an unused `use D4np\Utils\Bridge\…` produced **0
      violations** — deptrac resolves *type dependencies*, not imports — and only a real type
      reference triggers `Response must not depend on Psr7Bridge`. Third verification this session
      that itself needed verifying. **`^0.7` is declared against a core release that does not
      exist** (`VERSION` is `0.0.0`, no tag), so a standalone install genuinely cannot resolve
      today — kept, because it is true and a weaker constraint would be resolvable and wrong; the
      README says so. PR mode verified end to end: `egl/utils` resolves with source type **`path`**
      from the working tree, and the CI job now asserts that type, since a quiet fallback to a
      published core would make the same-PR guarantee a fiction with every test still green.
      Running PR mode locally **mutated the committed manifest** exactly as spec §6 forbids, so the
      boundary invariants are asserted from the **core's** suite (runs on every PR, not only when
      the package's job does) — planting the mutations fails two by name.*
- [x] 8.2 `Psr7Bridge` converters + the full **T-B** contract suite: every clause of spec 02
      §4–§5 (BFR-01…BFR-22) tested against **two** PSR-17 implementations (nyholm/psr7,
      guzzlehttp/psr7), each refusal and fidelity clause probe-verified by planting the defect it
      claims to catch (severity:high — the conversion contract is the package's entire value) —
      route: standard / high *(session model matched the route)* · **ADR-0034**, **spec 02 r2**.
      *Blocked at the first line: **BFR-04…BFR-07 were not implementable**. Every core collection
      reader was key-scoped (`queryString($k)`, `postList($k)`, `cookie($k)`, `file($k)`) and only
      `headers()` returned a whole collection — POST and `$_FILES` recoverable from nothing else,
      and re-parsing `uri()`/the `Cookie` header would have introduced a second parsing path beside
      PHP's own. Put to the maintainer with four alternatives; they chose to widen the core, so
      `Request` gained `queryAll()`, `postAll()`, `cookieAll()`, `uploadedFiles()` (**ADR-0034**,
      additive, no BC break, and not a retreat from ADR-0025 — that rule governs **scalar** reads,
      and a whole-collection reader promises no conversion so it cannot convert wrongly). 65 tests /
      202 assertions across both implementations. **Five defects planted, all caught on both
      vendors:** the `Set-Cookie` refusal removed (2 failures), a failed upload's stream opened (2
      errors), an object parsed body accepted (2), a nested upload tree accepted (2), the body
      rewind dropped (2). Spec 02 → r2: BFR-07's "lazily wraps `tmp_name`" corrected, since
      eagerness is the PSR-17 factory's business and neither vendor defers.*
- [x] 8.3 Publication pipeline per spec 02 §6: signed-source-tag verification (ADR-0032 reused),
      **release-mode** contract run against the released core, `git subtree split` + translated
      tag push to the read-only split repository; verify the `v*.*.*` / `utils-psr7-bridge-v*`
      tag-grammar isolation with a real tag before the first publication; one-time maintainer
      actions documented (create the split repo, register `egl/utils-psr7-bridge` on Packagist)
      (severity:high — supply-chain surface) — route: standard / high *(session model matched the
      route)* · **ADR-0035**, **spec 02 r3**. *Two details could not be built as written, for
      opposite reasons. **Tag-grammar isolation**: r1 planned to verify GitHub's glob with a
      throwaway tag — a side effect on a public repository to establish a fact that expires the day
      GitHub changes its matcher. Both workflows now **guard their own ref shape** and refuse a tag
      that is not theirs, which is stronger and makes the glob irrelevant; verified in both
      directions locally. **Release mode**: the standing L-0010 pattern says skip-and-self-enable
      when a gate cannot run yet, and that is **wrong here** — release mode is the only evidence for
      the package's central published claim, so skipping would not defer a check, it would publish
      an unverified package. It is a hard requirement, so **no bridge version can be published until
      the core has a release**; the failure says exactly that. `bridge_release_gate.py` anchors a
      bridge tag to the package changelog's `## [X.Y.Z]`, since a Composer library carries no
      version constant for `release_gate.py` to check. **Most of this pipeline has never run and
      cannot until a core release exists** — third item running (7.2, 7.3, 8.3) whose first real run
      is its first real use; named in the ADR, not implied to be fine.*

---

## Milestone 9 — Support & values (`v0.8.0`) · size: M

The foundation additions RFC-0002's later groups consume (`Str::transcode` feeds
`RowNormalizer`, `File`'s lock discipline feeds `FileSequence`); pure additive Support
surface (RFC-0002 FR-27…FR-32).

- [x] 9.1 `Str` additions: `collapseWhitespace()`, `nullIfBlank()`, `transcode()` (strict by
      default, lossy opt-in), multibyte-safe `padLeft()`/`padRight()`, `shortClassName()`,
      `pascalCase()` (RFC-0002 FR-31) — size: S · route: fast / low *(run at frontier;
      mismatch recorded)*. *Three of the item's own first-run test failures each taught
      something now pinned from both sides: `str_pad('héllo', 7)` emits **7 bytes rendering
      as 6 characters** (the byte-count defect these methods exist to fix); `pascalCase()`
      is **deliberately not idempotent** (an already-Pascal input is one word — `strtolower`
      flattens it, asserted as documented behavior); and an anonymous class's runtime name
      embeds the defining **file path, backslash-separated on Windows**, so
      `shortClassName()` answers the literal `class@anonymous` instead of a
      platform-dependent path fragment. `transcode()` distinguishes unknown-encoding from
      unconvertible-data by probing the pair on the empty string (iconv reports both as
      `false`); ext-iconv is `suggest`ed with the ADR-0021/0022 refusal pattern — the guard
      branch is probe-verified, not permanently tested (5.2's standing precedent). Padding
      follows PHP 8.3 `mb_str_pad()` semantics (native migration stays behavior-neutral),
      counting code points via PCRE — no mbstring dependency. **No new exception type:**
      strict `transcode()` failures throw `UtilsException` with precise messages — spec r3's
      exception enumeration is the contract; a finer type waits for a consumer who needs the
      distinct catch.*
- [x] 9.2 `Lookup`: immutable code→label map with an explicit missing-key policy — `label()`
      throws, `labelOr()`/`tryLabel()` for the tolerant reads; replaces silent sentinel
      strings (RFC-0002 FR-30) — size: XS · route: fast / low. *Shipped as written, first
      run green: `OutOfBoundsException` (the SPL type PHP itself uses for a bounded lookup
      miss) rather than a new library exception — spec r3 does not name one for FR-30, and
      the item's own precedent (9.1) is to add a type only when a consumer needs the
      distinct catch. `array_key_exists()`, not `??`/`isset()`, is the presence check
      throughout: a code deliberately mapped to `''` must read as present, which `??` would
      silently treat as absent — pinned by its own test.*
- [x] 9.3 `Url` value object: parse/normalize/build, query composition, **scheme-downgrade
      refusal on rebuild** (RFC-0002 FR-27) (security) — size: S ·
      route: frontier-reasoning / extra *(run at standard tier; mismatch recorded)* ·
      **ADR-0036**, **spec r4**. ***The probe changed the item.*** *`parse_url()` does not
      reject control characters — it **launders** them, rewriting each to `_`, so
      `https://example.com\n/evil` parses successfully with the host `example.com_`. Code
      that validates the parsed components and then forwards the original string is checking
      a value the caller never sent, and CR/LF is exactly the payload that matters once a URL
      reaches a request line. Refused up front instead, in `parse()` and in every wither, so
      **input and parsed value are the same string**. `FILTER_VALIDATE_URL` would have caught
      that one case and was rejected on its own probe: it also rejects valid IDN hosts. Two
      more probe findings shaped the class: `parse_url('not a url')` **succeeds**
      (`['path' => …]`), so absoluteness is checked separately; and `http_build_query()`
      **drops null values silently**, so they are refused — **PHPStan found that guard
      incomplete**, since the acceptable value shape is recursive and cannot be typed
      honestly, which made the runtime walk the whole enforcement; it now descends and names
      the offender's dotted path. The downgrade refusal is the second line of defence and the
      weaker one — the object **carries** its scheme through every recomposition, making the
      estate's actual defect (rebuilding with a hardcoded `http://`) structurally unreachable.
      Recorded limit: an **unknown** target scheme is allowed through, with a test pinning it.
      An untouched query is preserved **byte-exact** — re-encoding one nobody edited would
      invalidate any signature over it. 82 tests; **suite proved non-vacuous with 11 planted
      defects**, all caught. The pre-existing `ExceptionHierarchyTest` registry caught
      `InvalidUrlException` joining the family — the completeness guard doing its job — and
      spec r3's exception enumeration, which had not anticipated the type, is amended to r4
      rather than left to drift.*
- [x] 9.4 `Csv` streaming write/read + `Delimiter` enum + `CsvSerializable`; typed failures
      (never boolean), atomic write via `File`; formula-guard **opt-in, default off**, both
      flag states tested (RFC-0002 FR-28/FR-29; T-08) (security) — size: M ·
      route: frontier-reasoning / extra *(run at standard tier; mismatch recorded)* ·
      **ADR-0037**, **spec r5**. ***The probe found data corruption in the obvious
      implementation.*** *PHP's CSV functions default to a backslash `$escape` that RFC 4180
      does not define, and it does not merely format differently: a field ending in a
      backslash escapes the closing quote, so `['ends with \', 'next']` comes back as **one**
      field having swallowed the delimiter and the newline. Every call now passes
      `escape: ''`; the native corruption is pinned by its own test beside the fix, so the
      workaround cannot later read as arbitrary (PHP 8.4 deprecates the default for the same
      reason). **Two more shapes `fputcsv()` cannot express**, both silent losses: a single
      empty field emits a bare newline that reads back as nothing, so `""` is written
      explicitly; and a zero-column row — which has no CSV representation at all — is refused
      rather than written as a line that disappears. Blank lines are skipped on read, a
      quoted empty field is not, and the distinction is asserted. **The formula guard stays
      off by default**: it changes the exported value, so a guarded file no longer
      round-trips — asserted as a test, because that cost is the reason the default is off
      (spec §1). `CsvSerializable`'s pairing, which the estate's interface could only request
      in prose, is enforced: header from the first item, every row checked against its width.
      NFR-12's `memory O(row)` needed a streaming atomic write, so **`File` gains
      `writeStream()`** rather than `Csv` reimplementing ADR-0005's discipline in a second
      place — it adds `fflush()` before the rename, without which buffered bytes would make
      the "complete or previous" promise a lie. 92 tests; **suite proved non-vacuous with 12
      planted defects**, all caught. PHPStan also refused a `@throws` it could not trace
      through the writer callback, which was fixed the honest way — `writeStream()` now
      documents `@throws Throwable`, the propagation contract `Transaction::run()` already
      had.*
- [x] 9.5 `FileSequence`: rolling lock-guarded counter with an explicit cap policy
      (`SequenceExhaustedException`, never a silent wrap) + T-14 multi-process concurrency
      suite (RFC-0002 FR-32; T-14) (severity:medium — concurrency/atomicity) — size: S ·
      route: standard / medium *(session model matched the route — no mismatch)* ·
      **ADR-0038**, **spec r6**. ***The item's real problem was not the counter.*** *A
      sequence is a **read-modify-write**, and this library's primitives could not express one
      safely: `read()` takes a shared lock and `write()` an exclusive one, so composing them
      lets two processes both read `5` and both write `6` — and a lost increment in a sequence
      is a **duplicate identifier**. `File` therefore gains `update()`, which holds one
      exclusive lock across both halves and calls the mutator **before** writing, so a
      refusal leaves the file untouched. Following ADR-0037's precedent rather than
      re-deciding it: `FileSequence` opening its own `flock()` would have put ADR-0005's
      discipline in a second place. **T-14 is the load-bearing suite and it is real** — four
      separate PHP processes drawing 30 numbers each, asserting the union is exactly 1..120
      with no duplicate and no gap; everything inside one process shares a lock owner, so an
      in-process "concurrency" test would pass against an implementation with no locking at
      all. Proved by planting the split-lock race: **T-14 catches it**. Three refusals decided
      against their tempting alternatives: the cap **refuses instead of wrapping** (wrapping
      re-issues live identifiers, silently, at peak load); a **corrupt state file is refused
      and left on disk** as evidence (resetting is the reflex and it re-issues the entire
      window), while an absent or blank file — what `touch` and deploy scripts leave — is a
      legitimate fresh start; and the **window stays a caller-supplied opaque string**, so no
      timezone decision happens inside the library (the estate's helper called
      `date_default_timezone_set()` as a side effect of minting an id). Recorded limit: opaque
      windows cannot be ordered, so any change resets — a lexicographic guard was considered
      and rejected because it silently breaks unpadded numeric windows. 41 tests; **suite
      proved non-vacuous with 8 planted defects**, all caught.*
- [x] 9.6 phpbench: NFR-12 (Csv streaming) + NFR-10 (FileSequence) wired into the ADR-0030
      same-runner harness (RFC-0002) (step:optimize) — route: fast / medium. **Closes
      Milestone 9.** *`FileSequenceBench::benchSequenceNext()` and
      `CsvBench::benchWriteTenThousandByTen()` follow the established shape exactly:
      `Revs(1)` for Csv (10 000 rows already is NFR-12's unit, `MemoryBench`'s precedent),
      1000 same-file revolutions for FileSequence since `next()`'s cost is dominated by the
      lock-and-rewrite, not by the counter's value — unlike `ContainerBench`'s cold subject,
      no per-revolution freshness is needed. Both wired into `ci.yml`/`nightly.yml`'s
      existing `bench_budget_gate.py` call (`benchSequenceNext<=200`,
      `benchWriteTenThousandByTen<=150000`, phpbench's native µs). NFR-12's other clause —
      "memory O(row)" — is `Csv::write()`/`File::writeStream()`'s streaming-by-construction,
      proven by `CsvRoundTripTest`, not by a benchmark that cannot see an absence (item 4.5's
      "0 queries" precedent). **Honest limit:** this developer machine could not produce a
      trustworthy number for either subject — direct timing (bypassing phpbench's own broken
      environment-detection capture on this box, a pre-existing quirk) measured
      `benchSequenceNext` at **~49.8 ms**, roughly 250× the budget, on Windows/NTFS with the
      antivirus-scanned temp directory `File::update()`'s lock-create/rename cycle runs
      through. ADR-0030 already named this exact machine unreliable for benchmark numbers
      (`--php-disable-ini` there, extension-load warnings corrupting phpbench's own capture
      here); repeating the workaround was rejected for the same reason ADR-0030 rejected it.
      The budgets are therefore verified by CI's Linux runner, not locally — the same
      division of labor as every other absolute NFR in this project. **CI's own numbers**
      (this PR's `benchmark` job, `ubuntu-24.04`): `benchSequenceNext` **182.981 µs**
      against ≤ 200 µs — **passes, but with the thinnest headroom of any gated NFR in this
      project** (~9%, against NFR-01's 2.6×, NFR-03's 3.4×, NFR-05's 2.4×); named rather
      than left implicit, since ADR-0030's 40%-peak-to-peak cross-runner noise finding is
      larger than this margin, and a future PR narrowing it further is worth watching for,
      not assuming away. `benchWriteTenThousandByTen` **20.213 ms** against ≤ 150 ms —
      **7.4× headroom**, comfortable.*

---

## Milestone 10 — Persistence (`v0.9.0`) · size: L

RFC-0002's centerpiece: the layer that makes value-interpolated SQL a library-impossible
shape (the surveyed estate measured 199 interpolation sites against 0 bound parameters).
First two named cross-group deptrac edges (RFC-0002 P-1).

- [x] 10.1 `Database\SqlStatement`: immutable SQL+binds value; connection-side execution
      accepts **only** statements — text never travels without its parameters
      (RFC-0002 FR-33) (security — the protected floor) —
      route: frontier-reasoning / extra · **ADR-0039**, **spec r7**. *Opens Milestone 10.
      **What this does and does not close, stated plainly**: every existing call site was
      already binding real parameters (ADR-0014's real prepares) — a `(string, array)`
      signature never let a value reach the driver unbound. What it changes is where a
      reviewer has to look: after this item, `DatabaseConnection::select()`/`selectOne()`/
      `execute()` accept **only** a `SqlStatement`, so text and parameters cannot be two
      separately-assembled variables at the one true boundary to the driver — the estate's
      199-interpolation/0-binding failure mode, generalized as a type rather than left as a
      discipline every future call site has to re-earn. `QueryBuilder::get()`/`first()` and
      every test in `Database`/`Security` migrated in this item; a pre-1.0 breaking change
      to a public signature, permitted under SemVer §4 and `tools/bc_gate.py`. No new
      deptrac edge — `SqlStatement` stays in `Database`, where `Persistence` (10.2–10.4)
      will consume it as one of its own two named edges.*
- [x] 10.2 `Persistence\RowNormalizer` policy object (strict/lossy transcode, trim,
      empty→`null`) + T-15 policy table + the `Persistence` deptrac layer (edge to Support
      only at this point) (RFC-0002 FR-36; T-15) (severity:medium) —
      route: standard / medium · **ADR-0042**, **spec r9**. *The seventeen copies of this
      pipeline collapse to one object, and "explicit" (FR-36's word) forced a question the
      estate never had to answer: **which steps happen when the caller says nothing?** Three of
      the four change data, so they are **opt-in**, and only `trim` defaults on — it is the one
      step whose *absence* surprises people, since trailing spaces from a fixed-width `CHAR`
      are storage, not content. The closest call was defaulting everything off: maximally
      honest, and useless enough to invite every consumer to re-derive the same one-line
      config. **The estate's ordering was a latent bug inherited by nobody**: it trimmed
      *before* transcoding, harmless for its single-byte source and destructive for any
      multibyte one, so the order here is fixed — transcode, then trim/collapse, then
      blank→`null` last (a `CHAR(20)` of spaces is blank *after* trimming). Strict by default:
      an unconvertible value raises `DatabaseException` **naming the column**, the reverse of
      the estate's silent `//IGNORE`. T-15 is a 26-row policy table pinning the two cases a
      hand-rolled version gets wrong — **`'0'` is not blank** (`empty()` disagrees, and a flag
      column is where that lands) and non-string values pass by identity, so a BLOB resource is
      never fed to `iconv()`. The `Persistence` layer arrives **Support-only** and is
      **proved closed**: a planted `Persistence → Database` type dependency is rejected
      (`RowNormalizer must not depend on SqlStatement`) — exactly the edge item 10.3 must argue
      for, rather than one granted early for code that does not exist yet.*
- [x] 10.3 `Persistence\Repository` base gateway (`fetchAll`/`fetchOne`/`execute`/
      `withTransaction`; rows normalized then hydrated via the shared Hydrator; every failure
      throws — no sentinel returns) + the two named cross-group edges
      (Persistence→Database, Persistence→Dto) proved by planted violations
      (RFC-0002 FR-34, P-1) (severity:high; adr — the layering-rule extension) —
      route: frontier-reasoning / extra · **ADR-0043**, **spec r10**. *The first and only
      exception to RFC-0001's "groups depend downward on Support only" — `Repository` exists
      precisely to sit between two siblings (a `SqlStatement` in, a hydrated DTO out), so a
      version obeying the rule could not be written; RFC-0002 P-1's two alternatives both push
      the normalize-then-hydrate loop back into every caller, which is the seventeen-copies
      problem restated. Granted as **two named edges** on ADR-0021's precedent, not as a
      relaxation, and **proved in three directions**: both grants live (0 violations, 192
      allowed), a **non**-granted edge refused (`Repository must not depend on Result`), and the
      **inversion** refused (`Hydrator must not depend on RowNormalizer`) — the middle one being
      what distinguishes a grant from an opening. **FR-34's "every failure throws" is satisfied
      by omission**: there is no `try`/`catch` in the class at all, because
      `DatabaseConnection`, the hydrator and `RowNormalizer` each already raise a typed failure
      that must pass through. An absence is what a suite loses unnoticed — a re-added catch
      keeps every happy-path test green — so it is asserted against the class's own source, and
      that assertion was **proved non-vacuous** by planting the estate's exact sentinel
      (`catch (DatabaseException) { return -1; }`) and watching it fail. Two smaller decisions:
      hydration stays **strict**, so `SELECT *` into a typed DTO fails by design with
      `hydrate()` left protected and non-final as the lenient seam; and normalization is
      **opt-in**, settling the question ADR-0042 deferred — ADR-0042 answers "what does a
      normalizer do", this answers "did you ask for one".*
- [x] 10.4 `Persistence\TableGateway`: Table Data Gateway over `QueryBuilder` —
      select/insert/update/delete with allowlisted identifiers and bound values by
      construction + patterns-catalogue adoption entry (RFC-0002 FR-35) (security) —
      size: M · route: frontier-reasoning / extra *(run at standard tier; mismatch accepted by
      the maintainer and recorded)* · **ADR-0044**, **spec r11**, **patterns catalogue: first
      entry**. ***The item as written could not be built: `QueryBuilder` is `SELECT`-only and
      always was*** — no write verb exists anywhere in the `Database` group, so "compose
      exclusively through `QueryBuilder`" (FR-35's words, carried from RFC-0002) covers the read
      half and leaves the write half nowhere to go. Found by grep before any code was written;
      **spec FR-35 is corrected in this PR rather than left describing something absent**.
      The write side became `MutationBuilder`, placed in `Database` beside the read builder for
      two reasons: composing SQL inside `Persistence` would put a second generator in the group
      whose job is to *call* one, and its text could only enter `SqlStatement` through
      `composed()` — spending ADR-0041's review-list property on machine-generated SQL that has a
      checker. Instead `fromMutation()` is a fourth named door and **`composed()` keeps its zero
      in-library uses**. The FR-07 allowlist is extracted to a shared `Identifier` (one rule, not
      two copies: a drifting `LIKE_ESCAPE` yields a wrong query, a drifting allowlist yields a
      vulnerability), asserted by a mechanism test that the pattern appears **exactly once** in
      the production tree, comments excluded. Three gateway decisions are normative: **empty
      criteria refused** on every filtered operation (`all()` is the named whole-table read),
      **the DTO projected rather than `SELECT *`** (strict hydration, ADR-0008), and the table
      allowlisted **at construction** as well as per statement (ADR-0022's fail-fast). Honest
      limit, found by a test failing for the wrong reason: **on SQLite a DTO/table mismatch is
      not a driver error** — the double-quoted-string misfeature returns the column name as a
      string literal, so strict hydration catches it on the first row and **nothing catches it
      against an empty table**; both behaviours asserted, the schema-round-trip fix declined in
      the ADR. Extracting `Identifier` also made `QueryBuilder::toSql()` **pure**, which PHPStan
      noticed in two untouched tests. 1770 tests (+119); 10 planted defects, 10 caught — and the
      **first run of that campaign was itself invalid** (the new files were untracked, so the
      restore step silently did nothing and defects accumulated), redone against a staged index.
- [x] 10.5 T-13 injection suite over the gateway/statement paths: ADR-0017's 29-payload
      corpus re-run through `Repository`/`TableGateway`, placeholder-only text asserted at
      the PDO boundary via the `QueryLog` fixture (RFC-0002 T-13) (security) — size: M ·
      route: frontier-reasoning / extra *(run at standard tier; mismatch accepted by the
      maintainer and recorded)* · **spec r12**, **no new ADR** — the boundary decision is
      ADR-0017's and this applies it, which is worth saying rather than minting an ADR to look
      thorough. *578 tests under `--group T-13`. **The item found a defect it had itself
      introduced one PR earlier:** item 10.4 shipped `MutationBuilderTest` with its **own,
      shorter identifier corpus** — ten payloads where `QueryBuilderTest` had nineteen — so the
      newer builder was held to the weaker list while both suites stayed green. That is the
      "two rules, the weaker decides" argument ADR-0044 makes about the allowlist, reproduced
      one layer up in the tests. Both corpora now live in one `InjectionPayloads` fixture shared
      by T-02, T-13 and the builder suites; unification alone added **21 identifier cases to the
      write builder** and two to the read builder. **The identifier leg asserts more than the
      refusal:** the log must be **empty** — a hostile column name rejected *after* the statement
      reached the driver would satisfy every exception assertion while having already run the
      injection, and no round-trip test can see the difference. **The value leg adds what a
      boundary check cannot say:** the payload round-trips through hydration intact, a tautology
      criterion matches and deletes nothing, and an `UPDATE`'s two parameter groups bind in order
      — swap `SET` and `WHERE` and the statement still runs, affects a plausible row count, and
      writes the criterion into the column. 7 planted defects, 7 caught, including one aimed at
      the suite's own vacuity guard; the restore step worked this time because item 10.4's lesson
      (stage the files first) was applied.*
- [x] 10.6 phpbench: NFR-09 gateway-vs-hand-written-PDO ratio (`bench_ratio_gate` pattern,
      ADR-0011 precedent) (RFC-0002) (step:optimize) — size: S · route: fast / medium ·
      **no new ADR** — the ratio-gate mechanism is ADR-0011's, applied to a new pair of
      subjects, same as item 9.6 applied ADR-0030's harness rather than re-arguing it.
      *`GatewayBench` measures `TableGateway::all()` (fetch + normalize-via-`RowNormalizer` +
      hydrate, 100 rows) against a hand-written loop over the same shared `PDO` connection and
      table — the identical `SELECT`, the identical `trim()`-only normalization applied by
      hand under the same `is_string()` guard `RowNormalizer` itself uses, and a direct
      `new GatewayRow(...)` with no reflection. Both subjects share one connection (SQLite
      `:memory:` cannot be reopened) and one 100-row table, seeded once outside every timed
      iteration — item 3.5's warm-cache convention, applied to a warmed `ReflectionCache` and
      one discarded warm-up call so the gateway's own lazily-cached column projection is paid
      for before the clock starts, exactly as NFR-01's benchmark warms reflection first.
      **The comparison choice worth stating:** the manual loop reads via `$pdo->query()`
      rather than `DatabaseConnection::select()`, because the statement has no bound value at
      all — nobody hand-rolling this read would reach for a prepared statement over a literal
      `SELECT`, and the gateway is not exempt from real prepares (ADR-0014) just because this
      benchmark exists. That asymmetry is the overhead NFR-09 is measuring, not a thumb on the
      scale. **Local timing is informative only** (this machine's `vendor/bin/phpbench` fails
      its own environment-detection capture before any subject runs — the pre-existing,
      documented quirk items 4.5/4.6/9.6 all hit); CI's `ubuntu-24.04` run is what the ≤ 1.5×
      gate is wired against. **CI's own numbers: 160.591 µs / 86.767 µs = 1.85× — the gate
      FAILS, and item 10.10 is filed rather than the number massaged.** Profiling in-process
      (real `Hydrator`/`RowNormalizer`, no benchmark harness, `hrtime`) before accepting the
      miss found one real, safe win — `TableGateway::query()` re-ran `Identifier`'s allowlist
      on the table name and every projected column on **every call**, though neither can
      change after construction; cached per instance (perf commit, no ADR — the same
      resolve-once shape as the existing `$projection` cache and ADR-0020's driver lookup).
      It moved the ratio from **1.82× to 1.85×** — within run-to-run noise, because it was
      never the dominant cost: of the ~416 µs total, `select()` alone costs ~114 µs,
      `RowNormalizer::normalize()` adds ~98 µs, and **hydration adds ~184 µs — 44% of the
      total, and the whole gap.** Both `select()` and `normalize()` cost the manual loop
      roughly the same (it replicates the identical read and the identical `trim()`), so they
      wash out of the ratio; hydration does not, because the manual side pays a direct
      constructor call for the equivalent step. **The arithmetic that makes 1.5× very likely
      unreachable as specified:** item 7.1 measured hydration itself, already through
      ADR-0013's compiled-closure fast path (which `GatewayRow` qualifies for — builtin,
      non-variadic, no-default constructor parameters), at **2.40× a manual constructor
      call** — a floor this project spent a whole `standard/high` item (3.7) reaching and has
      not since beaten. Diluted by the shared fetch+normalize cost, that floor propagates to
      almost exactly the 1.82-1.85× measured here. Closing the remaining gap would mean
      re-opening item 3.7's own optimization, which is out of this item's `fast/medium`
      route — filed as item 10.10 instead of attempted here.*
- [x] 10.7 Make FR-33's guarantee **mechanical** instead of organizational: annotate
      `SqlStatement::__construct()`'s `$sql` as `@param literal-string`, so PHPStan at max
      level — a gate this project already runs — *refuses* a value interpolated or
      concatenated into statement text. **Filed by the item-10.1 review, which found the
      option had never been considered**: ADR-0039's Alternatives weighed a *runtime*
      assertion (rightly rejected — it cannot tell "nothing to bind" from "forgot to bind")
      and wrote *"a type-level guarantee catches what a string never announces"* while
      shipping the version that has no type-level guarantee. Verified empirically against
      this repository's own `phpstan.neon` before filing: an interpolated `"… {$v} …"` and a
      `'…' . $v` concatenation are both **rejected**, while hand-written literal SQL with
      placeholders — precisely what FR-33 exists to allow — passes. Needs two companions,
      because `QueryBuilder::toSql()` returns `string` and is correctly flagged: a
      `SqlStatement::fromQueryBuilder(QueryBuilder $b)` named constructor that takes the
      builder rather than a string (adding **no** new string-accepting door), plus one
      conspicuously-named escape hatch for genuinely composed SQL (`IN (?,?,?)` built with
      `implode()` is not a `literal-string` and is a legitimate pattern). **Should land
      before 10.3/10.4** — `Repository` and `TableGateway` are the callers the guarantee
      exists for. Supersedes or amends ADR-0039 (security; adr) —
      route: frontier-reasoning / extra · **ADR-0041** (amends ADR-0039), **spec r8**. *Shipped
      the filed plan with one design change forced by this project's own rules: an annotated
      **public** constructor could not be reached by the escape hatch without an analyser
      suppression, which is forbidden here — so the **constructor is private** and the class
      exposes exactly three named entry points (`literal()`, `fromQueryBuilder()`,
      `composed()`), needing **no suppression of any kind**. `composed()` has **zero in-library
      uses** by construction — that is why `fromQueryBuilder()` takes the builder *object*
      rather than its `toSql()` string, so `grep composed(` stays a list of places a human had
      to think. **Proved non-vacuous, 4 planted / 4 caught**: interpolation, concatenation,
      `sprintf()` and `implode()` into `literal()` are all rejected by PHPStan max — while four
      legitimate shapes still pass, including the one that mattered most before committing,
      **hand-written dialect SQL with a positional substring predicate**, which is FR-33's
      whole reason to exist. Tested as a **mechanism** (ADR-0027's pattern): the annotation's
      presence, the constructor's privacy, and the exact public static surface are asserted
      from the source, because no runtime test can exercise a static property. **Second pre-1.0
      break to this class in two PRs** (51 call sites moved) — named in ADR-0041 as the process
      finding it is: one PR should have shipped both, and did not because ADR-0039 never weighed
      the static alternative. Also found by running it: PHPStan parses an ignore-comment tag
      **inside prose** and fails on it, so the class docblock cannot name one verbatim.*
- [x] 10.8 Make NFR-07's mutation gate actually run. `infection.json5` never existed, so the
      `mutation` CI job's config guard reported `present=false` and the job passed in ~7
      seconds having executed nothing — **spec NFR-07's "≥ 70% MSI on Security/Database/Dto"
      has been unenforced since M1**, which is item 2.7's coverage-gate shape a second time
      (there, the job set up pcov and ran PHPUnit with no `--coverage` flag). Found by the
      item-10.1 review, which had listed the job among PR #57's passing checks — true of the
      job, false of the requirement (severity:high — a green gate that measures nothing) —
      route: standard / high · **ADR-0040**. *Three more obstacles surfaced by **running** the
      step rather than reading it. **Infection cannot be a dependency of this package**: every
      release from 0.29.10 requires PHP ^8.2/^8.3 against the 8.1 floor, and the
      8.1-compatible ones conflict with versions already locked here (`json-schema` 6.10 vs
      `^5.2`, `cpu-core-counter` 1.3 vs `^0.4`, `xdebug-handler` 3.0 vs `^1.3`) — installed
      into a throwaway project, ADR-0031's answer for the BC checker, so the 8.1 cell and
      `--prefer-lowest` stay untouched. **`--only-covered` is not an Infection option** (the
      generated step passed it; uncovered code is excluded by default now) so the step would
      have failed on argument parsing regardless. And Infection **could not locate PHPUnit**
      across two vendor directories, so `phpUnit.customPath` is stated rather than left to its
      finder. **Measured: MSI 79%** — 443 killed, 117 escaped, mutation code coverage 100% —
      so NFR-07's ≥70% is **met with 9 points of headroom**, and the floor **stays at the
      spec's 70**: raising it to today's number would be this item inventing a requirement.
      **Gate proved able to fail** (L-0008): floor temporarily raised to 95 against the same
      79% measurement → CI red, reverted in the next commit, both kept in this PR's history.
      Honest limits: 11 mutants produce syntax errors and are excluded from the ratio; the
      escaped set is now a CI artifact with a per-mutator breakdown but is **not** chased here;
      and the MSI cannot be measured on the maintainer's machine (no coverage driver — the
      same limit as the suite's 9 skips and item 9.6's benchmark caveat).*
- [ ] 10.9 Decide what the >10% regression gate should do about **I/O-bound and memory-hard
      subjects**, which it currently cannot measure to that precision. Filed from evidence
      produced by item 10.5, a **test-only** PR whose diff touches no file under `src/main`
      (verified, not assumed): the gate failed with
      `FileSequenceBench::benchSequenceNext 75.503 → 105.779 µs (+40.10%)` and
      `HashBench::benchVerifyArgon2id 113465.370 → 129072.012 µs (+13.75%)`, and **the same
      commit passed on re-run** (run 31114681269). Both subjects stayed far inside their
      absolute budgets; only the relative comparison fired. This is not the stored-baseline
      problem ADR-0030 already solved — base and HEAD were measured on the same runner, as that
      ADR requires — so it is a second, narrower finding: **a same-runner A/B is still not
      precise enough for a subject dominated by filesystem locking or by memory-hard hashing**,
      where the shared runner's noise is in the same order as the budget. Options to weigh (none
      chosen here): a per-subject threshold, best-of-N for the two classes, excluding them from
      the relative gate while keeping their absolute ceilings, or accepting re-runs as the
      documented protocol. Whichever is picked, **a gate that cries wolf on a docs-and-tests PR
      teaches people to re-run until green**, which is the failure mode worth spending an item on
      — route: standard / medium (adr)
- [ ] 10.10 Decide what to do about NFR-09's `bench_ratio_gate` step being **red on `master`**:
      `TableGateway::all()` measures **1.85×** a hand-written PDO loop (item 10.6) against the
      spec's ≤ 1.5× budget, and one safe, real optimization (caching the gateway's base
      `QueryBuilder` per instance, landed in the same PR) moved it from 1.82× to 1.85× — noise,
      not progress, because the dominant cost was never there. Profiled before filing rather
      than guessed: of ~416 µs total, `select()` costs ~114 µs and `RowNormalizer::normalize()`
      ~98 µs — both paid roughly equally by the manual loop too, so they wash out of the
      ratio — and **hydration adds ~184 µs, the entire gap**. Item 7.1 already measured
      hydration itself (through ADR-0013's compiled-closure fast path, which `GatewayRow`
      qualifies for) at **2.40× a manual constructor call**, a floor a whole `standard/high`
      item was spent reaching; diluted by the shared fetch+normalize cost, that floor
      arithmetically propagates to almost exactly 1.8×. Two honest paths, not one: **(a)**
      revisit whether 1.5× was ever reachable given NFR-01's own accepted floor — a spec-scope
      question (ADR-0040's rule: the spec owns its own numbers, not an agent unilaterally), or
      **(b)** a further hydration investigation beyond item 3.7's — which would mean re-opening
      a `standard/high` decision from a `fast/medium` item, the same over-reach item 10.9
      already named as the wrong move. Filed rather than either decided here — route: standard
      / medium (adr)

---

## Milestone 11 — Http application layer (`v0.10.0`) · size: M

The console-side trio: client, router, envelope (RFC-0002 FR-37…FR-39).

- [ ] 11.1 `HttpClient`: stream-context transport (no ext-curl), JSON/raw bodies, **TLS
      verification on by default**, explicit connect/read timeouts, typed
      `HttpClientException`; deliberately not PSR-18 (RFC-0002 FR-37) (security) — size: M ·
      route: frontier-reasoning / extra · ADR (transport TLS/timeout policy)
- [ ] 11.2 `Router`: method+path matcher with `{param}` extraction, **404-vs-405 with
      `Allow`**, callable handlers; stated non-goals (no middleware, no cache, no attribute
      discovery) + T-11 matrix + Front Controller catalogue adoption + the endpoint-kernel
      pattern doc (RFC-0002 FR-38; T-11) (adr — pattern adoption) — size: M ·
      route: frontier-reasoning / extra · ADR
- [ ] 11.3 `ApiEnvelope`: readonly envelope value, fixed JSON shape (`status`, `code`,
      `messages`, `data`), outcome constructors (ok/created/updated/deleted/empty/invalid/
      notFound/failed/caught); message strings caller-supplied, `Result`-mapping stays a
      documented app-side pattern (RFC-0002 FR-39) (severity:medium — consumer-visible API
      shape) — size: S · route: standard / medium
- [ ] 11.4 T-07 `HttpClient` behavioural suite against a live `php -S` origin (timeout,
      refusal and error-taxonomy paths; T-03's process discipline) (RFC-0002 T-07)
      (security) — size: M · route: frontier-reasoning / extra
- [ ] 11.5 phpbench: NFR-11 (router dispatch at 50 routes; envelope build) (RFC-0002)
      (step:optimize) — size: XS · route: fast / medium

---

## Milestone 12 — Security & channels (`v0.11.0`) · size: M

AEAD crypto, PSR-3 channel composition, and the Mail group (RFC-0002 FR-40…FR-44).

- [ ] 12.1 `Crypto` + `SecretKey`: AES-256-GCM, versioned `v1.` base64url token, `decrypt()`
      **throws** `CryptoException` on any failure (wrong key, tamper, malformed token);
      ext-openssl suggested with constructor refusal when absent (ADR-0021/ADR-0022
      pattern); `#[SensitiveParameter]` on secret-bearing signatures (RFC-0002 FR-40)
      (security) — size: M · route: frontier-reasoning / extra · ADR (AEAD replaces
      unauthenticated CBC)
- [ ] 12.2 T-09 crypto suite: tamper/wrong-key/truncation vectors, nonce uniqueness across
      10⁵ tokens, version-prefix handling (RFC-0002 T-09) (security) — size: S ·
      route: frontier-reasoning / extra
- [ ] 12.3 Logging channels: `Level` enum (PSR-3 mapping + ordering), `LevelFilteredLogger`,
      `MultiLogger`, `LoggerFactory` (one config array → channel map), PSR-3-pure (no
      Monolog dependency, NFR-08) + T-12 routing matrix + NFR-14 bench
      (RFC-0002 FR-41/FR-42; T-12) (severity:medium) — size: M · route: standard / medium
- [ ] 12.4 `Mail` group: `EmailAddress` (validated), `MailMessage` (**CR/LF/NUL in
      header-bound values refused at construction**), `Mailer` + `NativeMailer` (explicit
      constructor config, no global `ini_set`), `MailException`, the Mail deptrac layer
      (Support-only edge) + T-10 header-injection corpus (RFC-0002 FR-43/FR-44; T-10)
      (security) — size: M · route: frontier-reasoning / extra · ADR (header-injection
      refusal + transport non-goals)
- [ ] 12.5 phpbench: NFR-13 (crypto 1 KiB round-trip) (RFC-0002) (step:optimize) — size: XS ·
      route: fast / medium

---

## Spec Coverage Map

Tracks which spec section is fulfilled by which roadmap item(s). Sections follow the frozen
spec shape (scaffold renders `docs/specs/`; source: `.specs/d4np-php.md` v2.0 via RFC-0001).
Legend: ⏳ not started · 🚧 in progress · ✅ done · ❎ N/A.

| Spec § | Requirement | Roadmap items | Status |
|--------|-------------|---------------|--------|
| §1 | Objective & design philosophy | 1.1, 1.6 | ✅ |
| §2 | Functional items 1–25 (+9b); r3 adds FR-27–44 (RFC-0002) | 2.1–2.5, 3.1–3.3, 4.1–4.3, 5.1–5.3, 6.1–6.2, 6.4–6.5; 9.1–9.5, 10.1–10.4, 11.1–11.3, 12.1, 12.3–12.4 | 🚧 |
| §3 | Architecture & layering (deptrac); r3 adds Persistence/Mail + named edges | 1.1, 1.6, 2.1, 2.5, 10.2–10.3, 12.4 | 🚧 |
| §4 | NFR budgets & benchmark methodology; r3 adds NFR-09–14 | 3.5, 4.5, 5.5, 6.4, 7.1, 9.6, 10.6, 11.5, 12.5 | 🚧 |
| §5 | Security test criteria; r3 adds T-08/09/10/13 | 4.4, 5.4, 5.5, 6.3, 9.4, 10.5, 11.4, 12.2, 12.4 | 🚧 |
| §6 | API example / public interface | 1.6, 3.1, 10.3–10.4, 11.3 | 🚧 |
| §7 | Verification & test strategy (r3) | 1.2, 2.6, 3.1, 3.4, 4.4, 6.3, 8.2, 9.5, 10.2, 10.5, 11.2, 11.4, 12.2–12.3 | 🚧 |
| §8 | CI/CD & release engineering | 1.4, 1.7, 7.1–7.3, 8.3 | 🚧 |
| §9 | Decision log (imported + seeded ADRs); r3 items carrying ADRs | 2.1, 5.3, 7.4, 9.3–9.4, 10.1, 10.3–10.4, 11.1–11.2, 12.1, 12.4 | 🚧 |
