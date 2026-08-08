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
minor). In the event none of those minors were published: with every milestone closed, the
API-freeze review below settled the whole line into a single first release, **`v1.0.0`**.

- **Versioning start:** pre-1.0 milestone-driven — one minor per milestone
  (M1 → `v0.1.0` … M7 → `v0.7.0`); the **1.0.0 decision is a dedicated post-M7
  API-freeze review**, not an automatic bump. **Held 2026-08-09 and settled by
  [ADR-0059](docs/adr/0059-freeze-the-api-at-1-0-0-with-internal-symbols-outside-the-frozen-surface.md):**
  the API is frozen at `v1.0.0`, with `@internal` symbols outside the frozen surface, and the
  unpublished `v0.11.0` superseded. Post-1.0 versioning follows
  [`maintenance.md`](docs/workflow/maintenance.md)'s decision tree, not the milestone mapping.
- **Session journal:** see [`docs/journal/`](docs/journal/). Latest checkpoint:
  [2026-08-08 — Two open decisions, one table, and the number I nearly picked](docs/journal/2026/08/2026-08-08-nfr-ceiling-decisions.md).
  Previous:
  [2026-08-08 — Closing the milestone by fixing the tool that kept flagging it as open](docs/journal/2026/08/2026-08-08-benchmark-run-invalidation.md),
  [2026-08-08 — The last planned item, and the milestone that still doesn't close](docs/journal/2026/08/2026-08-08-crypto-benchmark.md),
  [2026-08-08 — Three answers to the same bytes, and a corpus that could not fail](docs/journal/2026/08/2026-08-08-mail-group.md),
  [2026-08-08 — The idiomatic enum was too slow, and one of my own arguments was dead](docs/journal/2026/08/2026-08-08-logging-channels.md),
  [2026-08-07 — A tag that shrinks, a key nobody checks, and a guard that never fired](docs/journal/2026/08/2026-08-07-crypto.md).

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

Ship `egl/utils` and settle the bridge (RFC-0001). The **1.0.0 decision** followed as a
dedicated post-M7 API-freeze review — held 2026-08-09, settled by **ADR-0059**.

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
- [x] 10.9 Decide what the >10% regression gate should do about **I/O-bound and memory-hard
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
      where the shared runner's noise is in the same order as the budget — route: standard /
      medium (adr) · **ADR-0045**. *Decided: **exclude, keep the absolute budget as the real
      gate**, over a wider per-subject threshold (arbitrary, still not principled), best-of-N
      (multiplies every PR's benchmark cost to fix two subjects), or accepting re-runs (the
      item's own framing already rejected this: "a gate that cries wolf ... teaches people to
      re-run until green"). `bench_regression_gate.py` gains a repeatable `--exclude
      Benchmark::subject` — the subject still prints in the report, marked `skipped` rather than
      silently dropped (the same absence-is-failure discipline this tool already holds to for
      missing reports). Excludes exactly three: `FileSequenceBench::benchSequenceNext`,
      `HashBench::benchMakeArgon2id`, `HashBench::benchVerifyArgon2id` — `benchMakeBcrypt` stays
      in the relative gate, since bcrypt's cost is pure CPU time with no memory contention and
      it has not shown this failure mode; being slow and being noisy-relative-to-itself are
      different properties, and the ADR states the criterion (real I/O with locking, or a
      deliberately memory-hard primitive) so this is a rule, not three names bolted on.
      Verified directly (this tool has no pytest suite, matching every other `tools/*.py` gate):
      a synthetic fixture reproducing item 10.5's exact numbers now reports `skipped` and exits
      0 with `--exclude`, still exits 1 without it, and an `--exclude` naming a subject absent
      from the report fails loudly rather than silently meaning nothing.*
- [x] 10.10 Decide what to do about NFR-09's `bench_ratio_gate` step being **red on `master`**:
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
      / medium (adr) · **ADR-0046**, **spec r13**. ***The maintainer chose path (a), revise the
      budget: 1.5× → 2.5×.*** *The 1.5× figure **contradicted NFR-01 on the same axis** — NFR-09's
      scope strictly contains hydration, yet 1.5× demanded hydration at **≤ 1.91×** manual
      construction while NFR-01 permits **3×** and item 7.1 measured **2.40×** after item 3.7
      spent a `standard/high` effort reaching it. Five CI runs measured **1.71/1.81/1.81/1.82/
      1.85×** — ratio spread 8.2% while the absolute times spread ~36%, confirming ADR-0011's
      reason for using a ratio at all. **2.5× is derived, not rounded:** above the 1.78×
      structural ceiling implied by NFR-01's own permitted 3×, 35% above the observed maximum
      (≈4× the ratio's own spread), and below NFR-01's 3× because shared fetch cost dilutes a
      containing scope toward 1.0. **Two corrections to item 10.6 along the way.** Its profile
      attributed the whole gap to hydration; re-measured with each stage in an **isolated
      process**, hydration is **72%** and `RowNormalizer`'s per-value dispatch is a real **27%**
      (+55.8 µs/100 rows) — the earlier single-process profile read `gateway->all()` at 884 µs
      when measured last after ~10 000 prior iterations, against 416 µs isolated, a GC/ordering
      artifact and **the fourth benchmark-scope error on record** here (ADR-0020, ADR-0028,
      ADR-0030, now this). And ADR-0014's pinned real prepares — item 10.6's named asymmetry —
      cost **0.4 µs**, essentially nothing; the fairness reasoning stood, the cost attribution
      did not. Checked before accepting the miss as structural: `GatewayRow` **does** compile to
      ADR-0013's fast path. **Row width is now in the NFR** because it changes the answer: 4
      columns hydrate at 3.17× where 10 properties manage 2.98× — less work to amortize the
      hydrator's fixed dispatch over. Also fixes an item 10.6 omission: NFR-09's ratio now runs
      in `nightly.yml` too, where a dependency re-resolve can move it with no commit. Residual
      `RowNormalizer` cost filed as item 10.11.*
- [x] 10.11 Reduce `RowNormalizer`'s per-row dispatch cost, newly attributed at item 10.10:
      **+55.8 µs per 100 rows** against an inline trim loop — 27% of NFR-09's total gateway
      overhead, and the only part of it that is not hydration. The cost is a policy object's
      price (ADR-0042: one `normalize()` call per row, then a per-value branch through
      `normalizeValue()`), paid even on the default policy where the only active step is `trim`.
      Options to weigh: a fast path when the configured policy is trim-only, hoisting the
      per-value branch decisions out of the loop into the constructor, or accepting it as the
      documented price of explicitness. **Not a correctness question** — the policy semantics
      (ADR-0042's ordering and defaults) must not change — route: fast / medium (step:optimize) ·
      **ADR-0047**, **benchmark record**. ***M10's last planned item — but the milestone stays open
      by one decision, because this item filed 10.12 below.*** *The cost was **dispatch, not
      work**: 281 ns per string value (186 of the 400 values in a 100-row batch) to re-derive, per
      value, four decisions that are properties of the immutable policy. Hoisted into the
      constructor as one `trimOnly` flag with a single guarded fast path: **95.2 → 65.2 µs, the
      overhead +52.3 → +22.3 µs, 58% removed** (development machine).* ***CI corrected the premise:***
      *on the reference-class runner the remaining overhead is **+2.760 µs** (22.423 vs the inline
      floor's 19.663) and **NFR-09's ratio did not move — 1.73×, `master`'s own figure**, since
      ~2.8 µs of 141.75 sits inside the 1.71–1.85× noise band ADR-0046 recorded. **Item 10.10's "27%
      of NFR-09's overhead" was a Windows figure**: on CI this component is **4.6%** of the gateway
      overhead. The saving on CI hardware is **unmeasured** — the subject is new, so the same-runner
      harness had nothing to compare against. Kept rather than reverted, on a smaller and honestly
      stated benefit: strictly less work per value for one boolean and one guarded loop.* **All four designs were measured before choosing,
      and both rewrite-shaped candidates were slower than the simple one** *— a precomputed closure
      70.4 µs (a closure call is still a call), the general pipeline inlined behind locals 74.5 µs.
      The more readable one-loop ternary lost by 7.4 µs, recorded so the trade can be revisited
      knowingly.* **Two tests, and their division of labour is proved rather than argued:** *a
      differential matrix (corpus × all 16 policy combinations vs an oracle outside the class)
      catches a condition that fires too widely, while planting `trimOnly = false` — the
      optimization silently ceasing to exist — leaves that matrix* **green** *and fails only the
      reflection truth-table assertion (ADR-0027's rule). 5 planted defects, 5 caught; 2424 tests
      (+24).* **New finding, filed as item 10.12 rather than fixed here:** *the same code measures
      51.5 µs in the global namespace and 65.2 µs inside one —* **13.6 µs is PHP's namespace
      fallback** *on 372 unqualified internal calls (~36 ns each), isolated with a two-class probe,
      in exactly the OPcache-off configuration NFR-06 pins. Not applied to one file, because a lone
      `\trim()` reads as a style slip and the next tidy-up gives the 13.6 µs back with every test
      green.*
- [x] 10.12 Decide the repo-wide policy on **unqualified internal function calls**, measured at
      item 10.11: inside a namespace, PHP resolves `trim()` by trying the namespaced name first and
      the global second, worth **13.6 µs per 100 rows / ~36 ns per call** in `RowNormalizer`'s hot
      loop under NFR-06's OPcache-off benchmark environment (isolated with a two-class probe, not
      inferred). Every file in this repository calls internal functions unqualified, and
      `native_function_invocation` is absent from `.php-cs-fixer.dist.php`. The decision is whether
      to enable that rule repo-wide (risky-rule class, touches every file, and `@PSR12:risky` is
      already on) or to record the cost as accepted; a per-file prefix is explicitly **not** an
      option — it cannot be held. **Measure on CI before deciding, not locally**: item 10.11's own
      CI run showed the dev box overstating this class of per-call cost by ~8× (a component the local
      decomposition put at 27% of NFR-09's overhead is 4.6% there), so the 13.6 µs figure is an upper
      bound from the wrong machine; consumers running OPcache see less of it again —
      route: frontier-reasoning / extra (adr, step:optimize) *(route corrected when the item was
      taken: it was filed `standard / medium`, but `os/routing` resolves `label:adr` to
      frontier-reasoning/extra — an item whose deliverable IS a decision is decision-heavy by
      definition, item 1.10's exact correction. Run at standard tier (Opus 5); mismatch recorded.)*
      · **ADR-0048**, **benchmark record**. ***Closes Milestone 10.*** *Enabled
      `native_function_invocation` (`@all`, `scope: namespaced`, `strict`) — 95 files, 795
      insertions — after CI's same-runner A/B priced it.* **The measurement carried its own
      control:** *`src/bench` is outside the CS-Fixer finder, so three subjects are unprefixed on
      both sides and set the noise band at −1.55%…+2.98% — **wider than most of the wins**, so the
      1.8–3.3% deltas on Container/QueryBuilder/Hydration cannot be claimed individually. Two
      result clears it in **both** runs:* **`RowNormalizer::normalize()` −24.02% and −20.81%**
      *(22.798 → 17.322 µs). The gateway path's −3.98% did* **not** *survive re-measurement
      (−2.17% on run 2, against a control that moved −2.57%), and* **NFR-09's ratio improvement was
      withdrawn outright** *— 1.66× on run 1, 1.73× on run 2, `master`'s own figure. So the benefit
      is **concentrated, not spread** — the rule pays in tight per-item loops and is inert in the ~93 `sprintf()` sites
      formatting exception messages that dominate the diff. Enabled anyway, because those loops are
      exactly the ones nobody remembers to hand-tune (ADR-0047's finding).* **Local overstated it
      again:** *13.6 µs predicted, 5.48 µs measured — direction right, magnitude 2.5× high.* **One
      test was coupled to spelling and broke:** *ADR-0026's seam assertion searched the source for
      the literal `return session_start();`; prefixing makes it `\session_start()` — same call, five
      red data sets, no behaviour changed. Fixed by normalising the separator, with the general rule
      recorded: a mechanism assertion must pin the mechanism and nothing else.* **Two open questions
      handed to the maintainer, not settled here** *(ADR-0040): NFR-09's ratio improved partly by
      asymmetry (library prefixed, its `src/bench` comparator not), and `RowNormalizerBench`'s class
      is now faster than its own floor for the same reason — either add `src/bench` to the finder or
      state in the spec that these compare against typical consumer code.*

---

## Milestone 11 — Http application layer (`v0.10.0`) · size: M

The console-side trio: client, router, envelope (RFC-0002 FR-37…FR-39).

- [x] 11.1 `HttpClient`: stream-context transport (no ext-curl), JSON/raw bodies, **TLS
      verification on by default**, explicit connect/read timeouts, typed
      `HttpClientException`; deliberately not PSR-18 (RFC-0002 FR-37) (security) — size: M ·
      route: frontier-reasoning / extra *(run at standard tier, Opus 5; mismatch recorded)* ·
      ADR (transport TLS/timeout policy) · **ADR-0049**, **spec r14**. ***Opens Milestone 11.***
      *Four probes ran before any code, and two of them changed the item.* **"TLS verification on
      by default" was the wrong guarantee to ship:** *a freshly created stream context carries*
      **no `ssl` options at all**, *so verification is whatever the process default holds — and
      `stream_context_set_default(['ssl' => ['verify_peer' => false]])` in any host bootstrap
      silently becomes this client's policy. Measured that an explicit option* **wins** *over that
      default, so every context states its TLS policy rather than inheriting it.* **"Explicit
      connect/read timeouts" was not implementable** *— PHP's wrapper has one `timeout` covering
      connect and each read (probed: a 2 s value cut a hanging connect at 2.01 s with
      `default_socket_timeout` at 5 s), and it re-arms per phase, so an origin dripping one byte
      per window outlasts it forever. Shipped instead: the per-phase timeout* **and** *a wall-clock
      ceiling enforced by a read loop (verified against a server that answers then stalls — the
      deadline fired, the received bytes survived); neither can be omitted at construction. Spec
      §2 amended to what the wrapper can deliver.* **Also decided:** *a response is a* **result**
      *(any status returns an `HttpResponse`; the exception is for no response at all), redirects
      are* **off** *by default, `http`/`https` only, and outbound header injection is refused
      (ADR-0025's stance, outbound). `HttpException` became an* **extension point** *(amending
      ADR-0004) so `HttpClientException` is catchable as either. Policy exposed as a pure value +
      a `Transport` seam (ADR-0026's shape), which is what makes any of this assertable without a
      network — the live-origin half is T-07, item 11.4. 7 planted defects, 7 caught; 2468 tests
      (+44).* **Process finding:** *the first CRLF plant silently failed to match the file and the
      suite went green — a fake plant is indistinguishable from a passing campaign, so the plant is
      now confirmed present before the result is believed.*
- [x] 11.2 `Router`: method+path matcher with `{param}` extraction, **404-vs-405 with
      `Allow`**, callable handlers; stated non-goals (no middleware, no cache, no attribute
      discovery) + T-11 matrix + Front Controller catalogue adoption + the endpoint-kernel
      pattern doc (RFC-0002 FR-38; T-11) (adr — pattern adoption) — size: M ·
      route: frontier-reasoning / extra *(run at standard tier, Opus 5; mismatch recorded)* ·
      **ADR-0050**, **patterns catalogue entry #2**. *The 37 folders become one table.*
      **Classifying the miss is the requirement, not a nicety:** *RFC 9110 §15.5.6 makes `Allow`
      MUST on a 405, so a router that cannot tell "nobody registered this path" from "somebody
      did, for another method" leaves the application unable to send a header it is required to
      send. `RouteNotFoundException` and `MethodNotAllowedException` are therefore distinct types
      — possible only because ADR-0049 unsealed `HttpException` one item earlier — and the 405
      carries the sorted method list plus `allowHeader()`.* **Security details that are not
      incidental:** *a placeholder is `[^/]+` and never `.+` (or `{id}` swallows separators and
      routes `/orders/42/lines/7` to the wrong handler), and percent-decoding happens* **after**
      *the match — decoding first would let `%2F` forge a segment boundary. Literal route text is
      `preg_quote`d, so `/files/report.txt` is a path and not a pattern. Duplicate registration,
      a relative path and a repeated placeholder name are all* **refused** *rather than resolved
      by include order.* **Front Controller was missing from the taxonomy** *— item 10.4's finding
      repeated, so `design-patterns.md` gains the row alongside the catalogue entry — and the
      ~20-line kernel it names is written out in
      [`docs/patterns/endpoint-kernel.md`](docs/patterns/endpoint-kernel.md), because the value of
      the pattern is the file that stops being copied 37 times. Non-goals recorded with reasons
      (no middleware/PSR-15, no route cache, no attribute scan, no implicit HEAD→GET); the
      middleware rejection joins the catalogue's Rejected table. T-11: 29 cases, misses given as
      much weight as hits; 5 planted defects, 5 caught.* **Process finding, second time this
      session:** *a `sed` plant silently failed to match and my guard did not catch it because it
      checked that the* **replacement** *was present — and `$value` already occurred elsewhere.
      The check has to be that the* **original is gone**.
- [x] 11.3 `ApiEnvelope`: readonly envelope value, fixed JSON shape (`status`, `code`,
      `messages`, `data`), outcome constructors (ok/created/updated/deleted/empty/invalid/
      notFound/failed/caught); message strings caller-supplied, `Result`-mapping stays a
      documented app-side pattern (RFC-0002 FR-39) (severity:medium — consumer-visible API
      shape) — size: S · route: standard / medium *(session model matched the route — first time
      this milestone)* · **ADR-0051** *(not scheduled by the item: a security-relevant decision
      surfaced, and §7/§10 require one under the enterprise posture)*. *Three estate envelopes and
      232+ construction sites become one shape, with the nine outcomes as an* `Outcome` *enum that
      owns its HTTP status (ADR-0015's reasoning; the estate re-derived that mapping three times).*
      **The security decision:** `caught()` *takes a* **correlation reference, not a** `Throwable`
      *— an envelope built from an exception puts* `getMessage()` *on the wire, and a message names
      schemas and paths as readily as a trace does (ADR-0029's stance at the payload boundary).
      Asserted as a* **mechanism on the reflected signature**, *because no behavioural test can see
      an overload that does not exist.* **Two status choices argued rather than assumed:**
      `Invalid` *is* **422** *not 400 (well-formed but rejected — RFC 4918 §11.2), and* `Empty` *is*
      **200** *not 404 (a search with no results is a successful search; the estate's 404 is what
      teaches clients to retry "no rows").* **The shape is the product:** *all four keys on every
      outcome, `data: null` present in the JSON and `messages` pinned to encode as* `[]` *not* `{}`.
      *The* `Result`*→envelope mapping stayed* **out** *of the library (an* `Http`*→*`Errors` *edge
      the layering rule forbids) and is now written out in the endpoint-kernel pattern doc. 2525
      tests (+28), 5 planted defects, 5 caught.*
- [x] 11.4 T-07 `HttpClient` behavioural suite against a live `php -S` origin (timeout,
      refusal and error-taxonomy paths; T-03's process discipline) (RFC-0002 T-07)
      (security) — size: M · route: frontier-reasoning / extra *(run at standard tier, Opus 5;
      mismatch recorded)* · **ADR-0052**, **spec r15**. *14 tests against a real origin, and the
      first one written found a defect.* **A followed redirect reported the wrong response:**
      *`wrapper_data` holds the* **whole chain** *when the wrapper follows a `Location`, and the
      transport read the first status line out of it — so a successful fetch came back as `302`
      with the target's body (`isSuccessful()` false for a request that had succeeded), a chain
      ending in `404` reported `302` with the failure invisible, and the two hops' headers were
      merged under one name, first value winning: `header('Set-Cookie')` answered from the hop
      that had been left behind. Fixed here rather than documented as expected (§10), with the
      regression test asserting both directions.* **Two guarantees existed only as values until
      now:** *the client refuses a self-signed certificate — proved against a generated one, with
      a control read in the same test so a refusal cannot pass by the origin being broken — and it
      still refuses after `stream_context_set_default()` has switched verification off
      process-wide, which is the exact hijack ADR-0049 was written around. Also live for the first
      time: the wall-clock ceiling ending a dripping origin (the per-phase timeout provably cannot
      — every window re-arms it), a refused redirect proved by a target that records its own
      visits, 40 KiB of binary body across five read chunks, and repeated `Set-Cookie` headers
      surviving as separate values.* **The suite name had to be reclaimed:** *`T-07` was already a
      group tag on `RequestTest`/`ResponseTest` from item 6.1 — chosen when the spec defined only
      T-01…T-05 — so `--group T-07` returned 86 tests across three classes and the spec's named
      suite was no longer countable. Tag removed there, ADR-0025 annotated rather than rewritten.*
      **Process finding:** *`git checkout -- <file>` restores from the* **index**, *so planting a
      defect in a file whose own fix is unstaged deletes the fix, not the plant — stage first. 8
      planted defects, 8 caught. A ~40% flake in the TLS fixture was root-caused rather than
      retried away: closing a socket that still holds unread inbound bytes resets the connection
      and destroys the response, so the origin now drains the request before answering (6/6 green
      after, 3/5 before).*
- [x] 11.5 phpbench: NFR-11 (router dispatch at 50 routes; envelope build) (RFC-0002)
      (step:optimize) — size: XS · route: fast / medium · **ADR-0053**. *`HttpBench.php`
      (`ContainerBench`'s/`QueryBuilderBench`'s shape) measures both halves. **Which route to
      dispatch was the scoping question** (`Router` has no cache and no index, ADR-0050's stated
      non-goal, so dispatch is a linear scan with a real best/worst case): the **last** of 50
      registered routes is the worst case that scan produces, and is the budgeted subject; the
      **first** is kept alongside it, unbudgeted, so the best case is not silently dropped. The
      same question on the envelope side: `ok()` construction alone is budgeted, never
      `jsonSerialize()` — NFR-11 budgets building the object, not the serialization
      `Response` performs later — with the multi-message `invalid()` path kept visible,
      unbudgeted, beside it.
      **NFR-11 CI measurement (reference: `ubuntu-24.04`, this PR's own run — not spec NFR-06's
      named machine): envelope half MET comfortably** — `benchEnvelopeBuild` **0.366–0.395 µs**
      (mean 0.381) against ≤ 2 µs, more than 5× headroom; `benchEnvelopeBuildWithMessages`
      **0.354–0.368 µs**, no measurable cost for the extra messages.
      **Router half NOT met: `benchDispatchLastOfFiftyRoutes` measured 6.874–7.145 µs
      (mean 6.984) against the ≤ 5 µs budget — about 40% over.** `benchDispatchFirstOfFiftyRoutes`
      measured **0.901–0.929 µs**, confirming the gap is the scan itself and not fixed per-call
      overhead: dispatching the 50th route costs roughly 7.6× dispatching the 1st, in line with
      49 extra failed `preg_match()` attempts. Shipped **as measured, not tuned to pass** — the
      spec's own gate, `bench_budget_gate.py --budget benchDispatchLastOfFiftyRoutes=5`, is wired
      into CI red-and-honest, following items 3.5/3.7's and 10.6/10.10's precedent rather than
      softening the gate or narrowing the workload until it clears. The gap is filed as item
      **11.7**, not decided here — the budget is a spec number and ADR-0040 reserves those for
      the maintainer. **Milestone 11 stays open on two decision items (11.6, 11.7); README's row
      stays "planned" until both resolve** — `consistency_lint`'s `milestones` check enforces
      exactly this (a milestone marked done in README needs every ROADMAP item checked).*
- [x] 11.6 Decide what NFR-10's **absolute** budget should be, now that the subject it guards
      has crossed it on unmodified code. Filed from item 11.4's CI, whose `src/main` diff is a
      single file in the `Http` group — `benchSequenceNext` exercises `Support\FileSequence` and
      `Support\File`, neither of which the diff touches (verified with
      `git diff origin/master...HEAD --name-only`, not assumed). The `Absolute NFR budgets` step
      measured **208.768 µs against the ≤ 200 µs budget** and failed the job; **the same commit
      re-run on the same runner measured inside budget and passed**, which is the evidence that
      settles what it was. The recorded history of this one subject across CI runs on code paths
      nobody had changed: **75.503 · 105.779 · 164.355 · 169.252 · 182.981 · 208.768 µs** — a
      **2.8× spread against a budget the highest reading exceeds by 4%**. [ADR-0045](docs/adr/0045-exclude-io-bound-and-memory-hard-subjects-from-the-relative-gate.md)
      already found this subject too noisy for the *relative* gate and excluded it there while
      **deliberately keeping its absolute ceiling**; that decision is the one now under question,
      and item 9.6 flagged the margin (~9% headroom, the thinnest of any gated NFR here) as worth
      watching for exactly this. Options, none taken unilaterally because the number belongs to
      the spec ([ADR-0040](docs/adr/0040-run-infection-outside-the-dependency-graph-and-hold-the-floor-at-the-specs-70.md)):
      raise NFR-10 to a value CI can actually hold, measure the subject as a *median of N runs*
      rather than one, exclude it from the absolute gate too (and say plainly that NFR-10 is then
      unenforced), or keep it and accept a job that fails a few runs in twenty. **A gate that
      fails on unchanged code teaches people to re-run it**, which is the failure mode worth
      pricing here — size: S · route: frontier-reasoning / extra (adr, decision-heavy)
      *(session model Opus 5 — `standard` tier against a `frontier-reasoning` route; the maintainer
      switched to Opus and delegated the decision in the same breath, mismatch recorded)* ·
      **ADR-0058**, **spec r16**. ***Decided together with 11.7 on the maintainer's explicit
      delegation*** — "decidi tu su 11.6 e 11.7", which is the only thing that made writing these
      numbers legal at all, since ADR-0040 had reserved them. **The decision: the 200 µs target
      stays, and a separate CI ceiling of 450 µs is what shared runners are asked to prove.** The
      two items turned out to have *different diagnoses of the same complaint*, which is why one ADR
      settles both: the router's ceiling was **wrong about the code**, this one is **right about the
      code and unenforceable on this hardware**. Evidence: typical readings are 75–190 µs,
      comfortably inside 200, and exactly **one of seventeen** crossed it (208.768, +4%) — a reading
      whose own commit passed on re-run. Re-derived, not recalled: the ten gated budgets were
      tabulated against their worst observed readings, and **there is a gap with nothing in it** —
      two subjects at 0.70× and 0.96× (the two that have fired), then nothing until 2.66×, so a
      ceiling within ~2× of a subject's worst reading sits inside this repository's demonstrated
      noise envelope. 450 µs is 2.16×. **Rejected: excluding it from the absolute gate too** — the
      item required that option be paired with saying plainly that NFR-10 would then be unenforced,
      and that is exactly the argument against it: after ADR-0045 already removed this subject's
      *relative* gate, dropping the absolute one leaves the component whose cost **is** its lock
      contention with no performance check at all, and an added `fsync` or lock-retry loop is a
      several-hundred-µs change a 450 µs ceiling still catches. **Rejected: median-of-N** — the
      absolute gate reads one report, so a median across runs means N benchmark jobs per PR, the
      same cost ground ADR-0045 rejected best-of-N on. **Rejected: keep 200 and accept occasional
      failures** — the failure mode this item filed itself to price.*
- [x] 11.7 Decide what to do about NFR-11's **router dispatch** budget, measured **not met** on
      unmodified `Router` code. Filed from item 11.5's own CI run
      ([ADR-0053](docs/adr/0053-benchmark-the-last-route-and-construction-not-serialization.md)):
      `benchDispatchLastOfFiftyRoutes` measured **6.874–7.145 µs (mean 6.984)** on
      `ubuntu-24.04` against the ≤ 5 µs ceiling — roughly 40% over, and (unlike item 11.6's
      subject) with no history of noisy runs to suggest measurement error; the first-of-50 figure
      (**0.901–0.929 µs**) confirms the cost scales with the 49 failed `preg_match()` attempts a
      worst-case dispatch pays, which is exactly the shape a linear, uncached scan produces
      (ADR-0050's stated non-goal — no index, no cache). Unlike 11.6, this is not a noisy-runner
      question; it is a real cost with a known cause. **Evidence added at item 12.4** (2026-08-08),
      which qualifies the "40% over" figure without changing the diagnosis: across five CI runs on
      **unmodified** `Router` code the subject has measured **6.874–7.145 · 5.188 · 5.673 · 4.735 ·
      7.021 µs** — once *inside* the ceiling. The two runs of the *same commit* that bracket that
      range came from runners differing by 27–103% on **every** subject (`benchWriteTenThousandByTen`
      9 691 → 19 660 µs), so the spread is the runner's, not the router's. What this changes for the
      decision: the mean sits clearly over budget and the cause is understood, so option (a) or (b)
      is still the question — but a ceiling this close to the runner's own variance makes the gate
      **flap** rather than fail, and whichever option is chosen should leave headroom against that
      variance rather than against the measured mean. **A seventh measurement and a new
      consequence, added at item 12.6** (2026-08-08): `benchDispatchLastOfFiftyRoutes` measured
      **7.069 µs** on PR #79's CI, breaching the ceiling again — the running set is now
      6.874–7.145 · 5.188 · 5.673 · 4.735 · 7.021 · **7.069** µs, still centered clearly over
      budget. More consequential than the number itself: **this breach is a `bench_budget_gate.py`
      failure at step 8 of the `benchmark` job, and GitHub Actions stops a job at its first failed
      step by default** — steps 9–13, including the **regression gate** where item 12.6's new
      `--control` invalidation logic lives, were all reported `skipped`, not run. This is the same
      structural shape item 10.10's journal named when NFR-09 blocked the regression gate's
      `--exclude` step from ever executing in CI: as long as 11.7 stays open, **any** downstream
      benchmark diagnostic added to this job — 12.6's included — is untested on real CI until a PR
      happens to avoid tripping this ceiling. Resolving 11.7 removes that side effect as well as
      the router's own gap; left open, the cost of leaving it open keeps compounding onto whatever
      is added next. Options, none taken unilaterally
      ([ADR-0040](docs/adr/0040-run-infection-outside-the-dependency-graph-and-hold-the-floor-at-the-specs-70.md)
      reserves spec numbers for the maintainer): **(a)** raise NFR-11's router budget to a value
      the current linear scan clears (the measured number, with headroom, e.g. ≤ 10 µs); **(b)**
      add a cache or an index to `Router` — reversing ADR-0050's stated non-goal, which named "a
      50-route table matches in microseconds" as the reason no cache was needed, a claim this
      measurement corrects; **(c)** accept the gap and ship the benchmark job red until (a) or
      (b) is chosen, per items 3.5/3.7's precedent (measure honestly, file the gap, do not tune
      the benchmark to pass) — size: S · route: standard / medium (a benchmark-methodology
      decision with a known, bounded cause, unlike 11.6's open-ended noise question) *(session
      model matched the route — Opus 5)* · **ADR-0058**, **spec r16**. ***Decided with 11.6 on the
      maintainer's explicit delegation.*** **Chose (a): the target is corrected 5 → 10 µs and the CI
      ceiling set at 15 µs.** The 5 µs figure was never measured; the subject's median across
      **eleven** readings is ~5.6 µs (4.735–7.145), and its cost scales exactly as ADR-0050's
      deliberate linear scan predicts — **0.674 µs** for the first of fifty routes against **5.581**
      for the last, ≈0.10 µs per failed `preg_match()` across the 49 misses. So the ceiling was
      wrong about the code, not the other way round. ***(b) rejected, and this is the load-bearing
      call:*** the item's own framing said a cache would reverse ADR-0050's non-goal "which named
      'a 50-route table matches in microseconds' … a claim this measurement corrects." **It does not
      correct it — it confirms it.** 5.6 µs *is* microseconds, and it is ~0.1% of a millisecond-scale
      HTTP request; an index would add a build step, a cache-invalidation question and a second code
      path to test, to reclaim a tenth of a percent. Stated plainly because it is the uncomfortable
      half: raising a breached ceiling is what tuning-to-pass looks like from outside, and the
      distinction is that the code's cost was measured **first**, found to be a documented design
      property, and judged acceptable on its own merits — had the router measured 500 µs this would
      have gone the other way. **(c) rejected on evidence this session produced**: with the job red
      at its absolute-budget step, GitHub Actions skipped every later step, so item 12.6's
      regression-gate logic **never executed on CI at all**. A permanently red gate does not merely
      annoy — it silently disables everything downstream of it in the same job. **15 µs, not 10**:
      10 is 1.40× the worst reading, still inside the noise envelope; 15 is 2.10×, and under
      ADR-0058 D2 catching a single doubling is the *relative* gate's job — it does that with
      **±0.60%** within-run precision, sixteen times finer than the 10% threshold it enforces.*

---

## Milestone 12 — Security & channels (`v0.11.0`) · size: M

AEAD crypto, PSR-3 channel composition, and the Mail group (RFC-0002 FR-40…FR-44).

- [x] 12.1 `Crypto` + `SecretKey`: AES-256-GCM, versioned `v1.` base64url token, `decrypt()`
      **throws** `CryptoException` on any failure (wrong key, tamper, malformed token);
      ext-openssl suggested with constructor refusal when absent (ADR-0021/ADR-0022
      pattern); `#[SensitiveParameter]` on secret-bearing signatures (RFC-0002 FR-40)
      (security) — size: M · route: frontier-reasoning / extra *(run at fast tier, Sonnet 5, on
      the maintainer's explicit `/model` switch — a two-tier mismatch against
      frontier-reasoning, recorded rather than silently accepted)* · **ADR-0054**. *Opens Milestone 12. **Two probes changed the design before any
      class existed.** `openssl_decrypt()`'s tag check is only as strong as the tag length it
      is given — a **correct prefix** of a real tag, at any length down to one byte, is
      accepted; a forged one is rejected at every length. A token format that let the tag's
      length vary would hand an attacker exactly the lever GCM's authentication exists to
      remove, so nonce and tag are **fixed-length constants sliced from fixed offsets** — 12 and
      16 bytes — never a length the token states. Separately, **`openssl_encrypt()` does not
      validate key length for `aes-256-gcm` at all**: 8, 16, 24 and 40-byte keys were all
      accepted silently, with no warning, and a 16-byte key does not silently become
      `aes-128-gcm` (checked directly) — it is simply unchecked. `SecretKey` is therefore the
      **only** way a key can exist (`generate()`, `fromBytes()`, `fromBase64()`), and it is the
      one place the 32-byte length is ever verified, rather than a discipline every call site
      would have to remember.* **`decrypt()` cannot distinguish wrong-key from tampered**: both
      reach the same `openssl_decrypt() === false` and the same `CryptoException`, which is
      GCM's own guarantee rather than a missed opportunity — telling them apart would require
      authenticating first to find out, and by then the answer is already no. **`ext-openssl`'s
      refusal is probed, not tested** (ADR-0021's precedent for `Sanitizer::richText()`'s own
      missing-package branch): the extension is core, present on every runner this project
      targets, so the branch cannot be executed by the suite — verified `true` directly rather
      than left unstated. `#[\SensitiveParameter]` verified on 8.3.1 (an uncaught trace redacts
      the argument); inert on the 8.1 floor per PHP's own lazy attribute resolution, not
      independently re-verifiable on this session's toolchain. First use of the attribute
      anywhere in this codebase. No deptrac change: `ext-openssl` is a core extension, not a
      namespaced dependency, so `Security`'s existing `Support`-only edge already covers it.*
      **A seventh plant found genuine dead code, not a gap**: `base64UrlDecode()` shipped with a
      `preg_match()` alphabet guard ahead of `base64_decode(..., true)`; removing it changed
      nothing — probed against ten malformed shapes, strict-mode `base64_decode()` already
      rejects every one. Removed rather than kept as belt-and-suspenders, the same call ADR-0022
      made for a `password_hash()` guard PHPStan proved unreachable. **A test-design bug found
      along the way**: the version-prefix tests originally encrypted and decrypted with two
      *different* `SecretKey`s, so a dropped/altered prefix and a wrong key threw for the same
      reason and the prefix check's own removal went uncaught — fixed to share one instance,
      which is what makes `"v2."` (exactly as long as `"v1."`) a meaningful case.*
- [x] 12.2 T-09 crypto suite: tamper/wrong-key/truncation vectors, nonce uniqueness across
      10⁵ tokens, version-prefix handling (RFC-0002 T-09) (security) — size: S ·
      route: frontier-reasoning / extra *(run at fast tier, Sonnet 5; mismatch recorded)*.
      *Delivered inline with item 12.1's `CryptoTest`, not as a separate suite. Unlike item
      4.4's T-02 (a materially different verification technique — the query-log assertion at
      the PDO boundary — layered on top of the round-trip tests items 4.1-4.3 already shipped),
      spec T-09's own wording names exactly the vectors GCM's authentication tag already is the
      mechanism for: tamper, wrong key, truncation, nonce uniqueness, version-prefix handling.
      Building a second suite restating them would have been filing something to have something
      to file, which §8 already forbids for patterns and applies here just as well to tests.
      **Six planted defects, six caught** by this suite — decrypt() swallowing
      `openssl_decrypt()`'s failure, a fixed nonce, the version-prefix check removed, the
      minimum-length check removed, `SecretKey`'s length check removed, and a redundant
      alphabet pre-check removed — plus one campaign that found a genuine simplification rather
      than a gap: see item 12.1's own entry.*
- [x] 12.3 Logging channels: `Level` enum (PSR-3 mapping + ordering), `LevelFilteredLogger`,
      `MultiLogger`, `LoggerFactory` (one config array → channel map), PSR-3-pure (no
      Monolog dependency, NFR-08) + T-12 routing matrix + NFR-14 bench
      (RFC-0002 FR-41/FR-42; T-12) (severity:medium) — size: M · route: standard / medium
      *(session model matched the route)* · **ADR-0055**, patterns catalogue **#3 Decorator**
      and **#4 Composite**. ***No spec amendment — the first item in six without one*** (FR-35's
      SELECT-only builder at 10.4, FR-37's unbounded timeout at 11.1, NFR-09's unsatisfiable ratio
      at 10.10 and T-07's tag collision at 11.4 all needed the spec corrected in the same PR);
      FR-41, FR-42, T-12 and NFR-14 were implementable exactly as written, which is worth stating
      rather than leaving as an absence. **Two measurements decided the design before it was
      written.** `match ($this)` over the eight cases costs **0.564 µs** against **0.246 µs** for a
      const-map lookup through the backing value — with OPcache off, as NFR-06 pins it — and NFR-14
      budgets an entire suppressed record at 0.5 µs, so the idiomatic enum shape would have spent
      most of the budget on its own severity lookup; end to end through a real decorator the
      enum-hydrating shape measured **1.089 µs (218% of budget)** against **0.435 µs** for the
      rank-comparing one. Hence `Level::rankOf()`, which answers *"is this a level"* and *"how
      severe"* in one array lookup and never hydrates a case on the hot path. **NFR-14 measured on
      CI: 0.081 µs against the 0.5 µs ceiling** (6.2× headroom; this dev box overstated it ~5×), and
      the fan-out shape measures identically, confirming the filter returns before the composite.
      ***The 8.1 cell caught a claim I had no right to make:*** the cases were first backed by
      PSR-3's own `LogLevel` constants — impossible to drift — and **PHP 8.1 refuses a class constant
      as an enum case value** while 8.2/8.3 accept it. The probe that pronounced it legal ran on 8.3,
      the only runtime on this machine, and the ADR's first draft said "verified on the 8.1 floor",
      which the probe had never established. Cases are literals now, with `LevelTest` asserting them
      equal to PSR-3's in both directions; same failure class as items 10.10/10.11 (a figure taken on
      the wrong machine), and the matrix cell that exists for exactly this did its job in 16 seconds.
      **`Logger`'s private severity map is gone** — it would
      have become the second copy of one rule the moment the decorator arrived, which is item 10.5's
      finding; its constructor widened to `Level|string`, additive. **10 of 11 planted defects
      caught. The 11th is the interesting one:** substituting PSR-3's `NullLogger` for the empty
      `MultiLogger` behind a disabled channel left the suite **green**, because the
      `LevelFilteredLogger` above it validates first — so that half of the ADR's reasoning was
      *dead*, and the ADR now says so and keeps the empty composite on the smaller claim
      (readability), the way item 12.1 removed a guard a probe proved inert. Recorded honestly, the
      **control subject — a bare `AbstractLogger::debug()` on a no-op sink — measures 0.046 µs, 57%
      of the subject on CI** (60% locally, so the proportion reproduces): most of what NFR-14 bounds
      is PHP's own method dispatch rather than this library's filtering, and a future breach should be
      read as "the dispatch or the runner moved" first. The benchmark job is red on
      `benchDispatchLastOfFiftyRoutes` (5.188 vs 5) — **item 11.7, pre-existing on master**, not
      this item's code.*
- [x] 12.4 `Mail` group: `EmailAddress` (validated), `MailMessage` (**CR/LF/NUL in
      header-bound values refused at construction**), `Mailer` + `NativeMailer` (explicit
      constructor config, no global `ini_set`), `MailException`, the Mail deptrac layer
      (Support-only edge) + T-10 header-injection corpus (RFC-0002 FR-43/FR-44; T-10)
      (security) — size: M · route: frontier-reasoning / extra *(run at standard tier / Opus 5;
      mismatch accepted by the maintainer and recorded)* · **ADR-0056**. *2831 tests (+119 under
      `--group T-10`). **No spec amendment — the second item in a row**, after four of the
      previous six needed one. **The probe that shaped everything:** `mail()` returning `false` on
      a host with no MTA says nothing about whether a payload was accepted, so the item was probed
      against a **real SMTP sink** on `127.0.0.1`. PHP does three different things with the same
      bytes: `CRLF` in `$subject` is **flattened to spaces** (`Subject: a subject  Bcc: victim@…`
      reached the wire), `CRLF` in an **array** header value throws `ValueError`, and `CRLF` in a
      **string** header block is **honoured** — a second `RCPT TO:<victim@…>` was issued, a working
      Bcc injection. So the refusal is at construction (ADR-0025's stance on the other protocol),
      the transport hands `mail()` an **array** as defence in depth, and the array form is asserted
      as a **mechanism** through a `MailApi` seam because both shapes send a working email and no
      behavioural test can tell them apart (ADR-0027, ADR-0026's seam). Also probed: PHP issues a
      `RCPT TO` for an array `Bcc` **and omits the header** from what it sends, which is why that is
      not done by hand. Subjects become hand-rolled RFC 2047 encoded-words (no `mbstring`, ADR-0019)
      folded at 75 characters; bodies are base64 with `chunk_split(…, 76)`; two bodies become
      `multipart/alternative` with a 128-bit boundary. **A boundary-collision check was written and
      then removed as unreachable** — the boundary is drawn after the bodies exist, so placing it in
      one means guessing 128 unborn bits (ADR-0022 / item 12.1's precedent for inert defences). New
      `Mail` deptrac layer, **Support-only and deliberately not reaching `Errors`** (a transport that
      logged its own failures would invert ADR-0029); proved by planting a `Mail → Errors` type
      dependency (2 violations → 0). ***15 defects planted, 14 caught — and the one real miss is
      worth the item's weight:*** splitting the subject on **bytes** instead of characters passed the
      suite, because the multi-byte test used only three-byte characters and an encoded-word's
      payload here is **45 bytes, a multiple of three**, so every split landed on a character
      boundary by arithmetic. Test widened to two-byte, four-byte and mixed subjects; plant now
      caught. **A corpus whose members all share one width cannot test a boundary computed in that
      width** — the same failure class as a benchmark measuring the wrong shape (ADR-0018/0020). The
      15th "miss" was not a defect: lower-casing before slicing the domain is byte-equivalent to
      slicing then lower-casing.*
- [x] 12.5 phpbench: NFR-13 (crypto 1 KiB round-trip) (RFC-0002) (step:optimize) — size: XS ·
      route: fast / medium *(session model matched the route — Sonnet 5)*. *`CryptoBench::
      benchCryptoRoundTrip()` measures `Crypto::encrypt()` immediately followed by `decrypt()` on a
      1 KiB payload — the round trip the spec names, not either half alone: `decrypt()` cannot be
      measured honestly without a real token (a hand-built or cached ciphertext would skip the nonce
      generation and tag verification a real call pays for). Local sanity (informative only, this
      box's own CLI capture is broken per the standing note — direct `hrtime()` timing instead):
      **~14 µs**, comfortable headroom under the 60 µs ceiling and nowhere near NFR-11's
      knife-edge. Budget wired into `ci.yml`/`nightly.yml`'s existing `bench_budget_gate.py`
      invocation (`benchCryptoRoundTrip=60`), not a `Bench\Assert` — one home per number, the
      pattern every prior bench item in this milestone kept. **No new control subject**: item
      12.4's finding (filed as 12.6) is that a run-wide runner slowdown moves every subject in one
      CI job together, and `RowNormalizerBench::benchInlineTrimHundredRows` already serves that
      role for this benchmark job — one control per job, not one per file. No ADR: this item wires
      an existing spec number into the existing harness, the same shape as items 9.6 and 11.5,
      neither of which needed one either.*
      ***M12's planned scope is now complete — but the milestone stays open.*** *README's M12 row
      stays "⏳ planned" and `consistency_lint`'s milestone check enforces that structurally: item
      12.6, filed at item 12.4, is unchecked, so the milestone cannot be marked done by mistake
      even if someone tried. This is the same shape M11 closed in — 11.4/11.5 finished the planned
      work while 11.6/11.7 stayed open — except M11's two open items are both maintainer decisions
      on numbers, while 12.6 is a decision on the **gate's own design** (does a control-subject
      breach invalidate a run, and if so, how).*
- [x] 12.6 Decide how the **relative** benchmark gate should treat a run-wide slowdown. Filed from
      item 12.4's CI, where the *same commit* failed **two different gates on two runs** and neither
      failure was attributable to the diff (a new `Mail` group nothing else imports). Run 1: the
      relative gate failed with five subjects at **+11.19% … +19.44%**, among them
      `RowNormalizerBench::benchInlineTrimHundredRows` — **the control**, a hand-written inline
      `trim()` loop that calls no library code and therefore cannot regress. Run 2: the relative gate
      passed (both halves measured on the same slow runner) and the **absolute** ceiling tripped
      instead, on item 11.7's router. Every subject in run 2 was **27–103% slower** than in run 1.
      So ADR-0030's same-runner A/B is sound and has an unhandled failure mode: base and head are
      measured **sequentially**, so a runner that changes speed *between the halves* moves every
      subject in one direction, and the gate reports it as a regression in whichever half came second.
      ADR-0045's exclusions do not cover it — those name three subjects for *cross-run* noise, while
      this hits everything, control included. The instrument that detects it already exists: **a
      control subject moving beyond the threshold is proof the run is invalid**, not proof the code
      regressed (the method item 10.12 introduced). Options, none taken unilaterally: **(a)** name
      the control subjects in `bench_regression_gate.py` and have the gate **invalidate the run**
      (exit distinctly, ask for a re-run) when one of them moves past the threshold; **(b)** compare
      each subject against the control's own delta rather than against zero, so a run-wide shift
      cancels; **(c)** interleave the base and head measurements instead of running them in
      sequence; **(d)** accept the flapping and re-run by hand, as this item's own PR did. (b) is
      the most precise and the easiest to get subtly wrong; (a) is the smallest change that stops a
      false failure being indistinguishable from a real one — size: S · route: standard / medium
      *(session model was Sonnet 5 — `fast` tier, kept from the user's explicit `/model` switch
      before item 12.5 — against a filed `standard` route; mismatch recorded, not glossed)* ·
      **ADR-0057**. *The choice among (a)–(d) is an engineering call about the gate's own
      mechanism, not a spec or budget number — ADR-0040 reserves the latter for the maintainer;
      ADR-0045 made the same kind of call unilaterally when it added `--exclude`. Proceeded on the
      maintainer's explicit "procedi con M12.6." Chose **(a)**:
      `bench_regression_gate.py` gains a repeatable `--control Benchmark::subject` flag; when a
      control's delta exceeds `--max-regression` **in either direction** (a runner speeding up
      is the same broken comparison as one slowing down, and could hide a real regression under
      an apparent improvement), the gate prints `INVALID`, names the breaching control(s), states
      that no other subject's number from that run can be trusted, and exits **`2`** — distinct
      from `0` (pass) and `1` (a trustworthy failure) — rather than reporting individual
      regressions a compromised A/B cannot vouch for. **(b)** (net each subject against the
      control's own delta) was rejected for now, not dismissed: run 2's subjects moved
      27–103%, not by one consistent factor, so a naive subtraction risks manufacturing a false
      pass or fail from real per-subject drift; filed as a follow-up if plain invalidation proves
      too coarse. **Two controls wired into `ci.yml`** (not `nightly.yml`, which never ran the
      relative comparison per ADR-0030 §3): `RowNormalizerBench::benchInlineTrimHundredRows`
      (item 10.11's origin) and `LoggingBench::benchSinkDirectly` (item 12.3's) — two rather than
      one, so a slowdown localized to one part of the job's timeline cannot land entirely outside
      a single sentinel's measurement window. **A name may not appear in both `--control` and
      `--exclude`**, refused loudly at parse time: a control's value is being a clean signal,
      and excluding it would silently defeat the property the flag depends on. **Verified against
      8 synthetic `phpbench --dump-file` fixtures** (no permanent pytest suite exists for
      `tools/*.py`, the standing method from items 10.5/10.9), including an exact reproduction of
      item 12.4's run-1 numbers (`benchInlineTrimHundredRows` +19.44%, `benchNormalizeHundredRows`
      +13.82%) now producing `INVALID`/exit 2 with **no** old-style `FAIL — 2 subject(s)` block,
      and the identical numbers with no `--control` given reproducing the pre-existing `FAIL`
      output byte-for-byte — proving the change is additive, not a behavior change for anyone not
      opting into a control.*

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
| §4 | NFR budgets & benchmark methodology; r3 adds NFR-09–14; r16 splits target from CI ceiling | 3.5, 4.5, 5.5, 6.4, 7.1, 9.6, 10.6, 10.11–10.12, 11.5–11.7, 12.3, 12.5–12.6 | ✅ |
| §5 | Security test criteria; r3 adds T-08/09/10/13 | 4.4, 5.4, 5.5, 6.3, 9.4, 10.5, 11.4, 12.2, 12.4 | 🚧 |
| §6 | API example / public interface | 1.6, 3.1, 10.3–10.4, 11.3, 12.4 | 🚧 |
| §7 | Verification & test strategy (r3) | 1.2, 2.6, 3.1, 3.4, 4.4, 6.3, 8.2, 9.5, 10.2, 10.5, 10.11, 11.2, 11.4, 12.2–12.4 | 🚧 |
| §8 | CI/CD & release engineering | 1.4, 1.7, 7.1–7.3, 8.3 | 🚧 |
| §9 | Decision log (imported + seeded ADRs); r3 items carrying ADRs | 2.1, 5.3, 7.4, 9.3–9.4, 10.1, 10.3–10.4, 10.11–10.12, 11.1–11.2, 11.6–11.7, 12.1, 12.3–12.4, 12.6 | ✅ |
