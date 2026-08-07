# Changelog

All notable changes to `egl-util-php` are documented here, following
[Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning 2.0.0](https://semver.org/).

Every PR that introduces a user-visible change adds a line to `[Unreleased]` in the same
PR. A release PR moves the `[Unreleased]` entries into a new per-version file under
`docs/changelog/v<MAJOR>/v<X.Y.Z>.md` and adds an index row below.

## [Unreleased]

### Added

- **`tools/bench_regression_gate.py` gains `--exclude Benchmark::subject`** (spec NFR-06; roadmap
  item **10.9**; **ADR-0045**): a repeatable flag that removes a named subject from the gate's
  pass/fail decision while keeping it in the printed report (marked `skipped`, never silently
  dropped). Wired in `ci.yml` for exactly three subjects — `FileSequenceBench::benchSequenceNext`,
  `HashBench::benchMakeArgon2id`, `HashBench::benchVerifyArgon2id` — whose dominant cost (real
  filesystem locking, or Argon2id's deliberate memory-hardness) made a same-runner A/B fire on
  noise: item 10.5's test-only PR measured `+40.10%` and `+13.75%` on these two and passed
  cleanly on re-run of the identical commit. Both stay fully covered by their own absolute budget
  in `bench_budget_gate.py`, which is what NFR-10 and NFR-05 actually specify.

- **phpbench benchmark for NFR-09** (spec §3, RFC-0002; roadmap item **10.6**): `GatewayBench`
  under `src/bench/php/d4np/utils/`, wired into the `bench_ratio_gate.py` cross-subject
  comparison ADR-0011 established (`benchGatewayFetchNormalizeHydrate` /
  `benchHandWrittenPdoLoop` ≤ 1.5×). No new ADR and no production code change: ADR-0011's
  standing decision (a same-invocation ratio, since PHPBench's own `@Assert` cannot compare two
  subjects in one run) already covers a new pair. Both subjects share one `PDO` connection and
  a 100-row table seeded once, so the comparison is `TableGateway::all()` (select, normalize via
  `RowNormalizer`, hydrate through the warmed reflection cache) against a hand-written loop over
  the identical read and the identical `trim()`-only normalization, applied by hand. **CI's
  measured ratio is 1.85×, over the spec's <= 1.5× budget** — profiled honestly rather than
  massaged; the gap traces to hydration's own already-optimized cost (item 7.1's accepted 2.40×
  vs. manual construction, ADR-0013), diluted by shared fetch+normalize work both sides pay
  equally. Filed as roadmap item **10.10** rather than decided unilaterally (a spec-budget or
  hydration-optimization call, either of which is out of this item's `fast/medium` route).

### Fixed

- **`TableGateway::query()` no longer rebuilds its base query on every call** (found while
  investigating NFR-09, above) — the table name and projected columns were re-run through
  `Identifier`'s allowlist on every read even though neither can change after construction. Cached
  per instance, the same shape as the existing projection cache and ADR-0020's driver-lookup fix.
  A real, safe reduction in the gateway's own bookkeeping cost; not the fix for NFR-09's gap (see
  above), and not a behavior change for any caller.

- **Spec §7's T-13 suite** (roadmap item **10.5**; spec **r12**) — 578 tests proving, at the PDO
  boundary, that every `TableGateway`/`Repository`/`MutationBuilder` path sends **placeholders
  only**, with the payload appearing solely in the bound-parameter array. Two legs: the value
  corpus across every value-accepting surface (including inside a transaction and with a
  `RowNormalizer` configured), and the hostile-identifier corpus across every surface where a
  column name can arrive from a caller — the latter asserting not just the refusal but that
  **nothing was prepared**, since a refusal issued after the driver already had the statement
  would pass any exception assertion. Adds three consequence assertions a boundary check cannot
  make: the payload round-trips through hydration intact, a tautology criterion matches and
  deletes nothing, and an `UPDATE`'s `SET` values bind **before** its `WHERE` criteria.

### Changed

- **One injection corpus, shared** (item 10.5) — the value and identifier payload lists move into
  a single `InjectionPayloads` fixture used by T-02, T-13 and both builder suites. `MutationBuilder`
  had shipped with a **shorter copy** of the identifier corpus at item 10.4 (ten payloads against
  the read builder's nineteen), so the newer builder was being held to the weaker list while both
  suites stayed green — the same "two rules, the weaker decides" failure ADR-0044 argues about for
  the allowlist itself. No production behaviour changes; the write builder now faces 21 identifier
  payloads instead of 10.

- **`D4np\Utils\Persistence\TableGateway`** (spec r11 **FR-35**, RFC-0002; roadmap item **10.4**;
  **ADR-0044**; patterns catalogue: *Table Data Gateway*, the catalogue's first entry) — one
  object per table: `find()`, `all()`, `findBy()`, `findOneBy()`, `insert()`, `update()`,
  `updateBy()`, `delete()`, `deleteBy()`, plus a `protected query()` seam for subclasses that add
  the reads one table actually needs. It extends `Repository`, so hydration, opt-in normalization,
  transactions and the no-catch property come with it. Three behaviours are deliberate and
  documented: **empty criteria are refused** on every filtered operation (`all()` is the named
  whole-table read — an empty array is what an unvalidated request filter collapses to), reads
  **project the DTO instead of `SELECT *`** so strict hydration stays satisfiable on a table with
  columns the DTO does not want, and the table name is **allowlisted at construction** as well as
  in every statement.
- **`D4np\Utils\Database\MutationBuilder`** and **`SqlStatement::fromMutation()`** (spec r11
  **FR-33b**) — `INSERT`/`UPDATE`/`DELETE` composition with the allowlist the read builder
  applies: values bound, column names refused rather than escaped, `null` criteria rendered as
  `IS NULL`, and **unqualified writes refused** (an `UPDATE` or `DELETE` with empty criteria
  applies to every row in the table). This exists because **`QueryBuilder` is `SELECT`-only** —
  FR-35's "compose exclusively through `QueryBuilder`" was not implementable, and the spec is
  corrected in the same PR. `SqlStatement` gains a fourth named door for it; `composed()` keeps
  its zero in-library uses, so `grep composed(` still means "a human checked this one".
- **`D4np\Utils\Database\Identifier`** — the FR-07 allowlist and per-driver quoting, extracted
  from `QueryBuilder`'s private methods so both builders enforce one rule. A copied allowlist is
  one edit away from two that disagree, and the weaker one decides; a test asserts the pattern
  appears exactly once in the production tree. **No behaviour change** to `QueryBuilder`, whose
  public API is untouched.

- **`D4np\Utils\Persistence\Repository`** (spec r10 **FR-34**, RFC-0002; roadmap item **10.3**;
  **ADR-0043**) — the abstract data-access base: `fetchAll()`/`fetchOne()` hydrate rows into a
  DTO, `execute()` returns the affected-row count, `withTransaction()` delegates to `Transaction`
  (ADR-0016's semantics, savepoints included) and passes the repository to the closure.
  **It contains no `try`/`catch` at all, and that is the feature.** FR-34 asks that every failure
  throw and that no `[]`/`false`/`-1` path exist — the surveyed estate had **74** catches
  returning exactly those — and the way to satisfy it was to *not write* them:
  `DatabaseConnection`, the hydrator and `RowNormalizer` each raise a typed failure naming what
  went wrong, and all of them propagate. Since an absence is what a suite loses unnoticed, the
  class's own source is asserted to contain no catch, and that assertion was proved non-vacuous
  by planting the estate's exact sentinel and watching it fail.
  Hydration stays **strict** (ADR-0008), so a projected column the DTO does not declare raises —
  `SELECT *` into a typed DTO fails by design, with `hydrate()` left protected and non-final for
  a subclass that wants lenient. Normalization is **opt-in**: without a `RowNormalizer`, rows
  hydrate exactly as the driver returned them.
  This is also the library's **first cross-group layering exception**: `Persistence → Database`
  and `Persistence → Dto` are granted by name in `deptrac.yaml`, not as a general relaxation, and
  proved in three directions — both grants live, a non-granted edge (`Persistence → Errors`)
  refused, and the inversion (`Dto → Persistence`) refused.

- **`D4np\Utils\Persistence\RowNormalizer`**, and the `Persistence` group it opens (spec r9
  **FR-36**, RFC-0002; roadmap item **10.2**; **ADR-0042**) — the row-cleanup pipeline the
  surveyed estate carried **seventeen times**, once per data-access class, as one immutable
  policy object: charset transcode, trim, collapse internal whitespace, blank→`null`.
  **Only `trim` defaults on.** The other three change data, and a library that quietly rewrites
  a consumer's values is the shape spec §1 rejects — so transcoding, collapsing and blank→`null`
  are opt-in, while trim earns its default by being the step whose *absence* surprises people
  (trailing spaces from a fixed-width `CHAR` are storage, not content).
  **The step order is fixed, and the estate's was a latent bug:** it trimmed *before*
  transcoding, which is harmless for a single-byte source encoding and destructive for any
  multibyte one. Here the transcode runs first, and blank→`null` runs last so it judges what the
  earlier steps produced. Failure is strict by default — an unconvertible value raises
  `DatabaseException` **naming the column**, the reverse of the estate's silent `//IGNORE`, with
  `$lossy = true` as the explicit opt-out. Keys are never touched (a column name is schema, not
  data) and non-string values pass through by identity, so a BLOB resource is never fed to
  `iconv()`. Pinned by suite **T-15**, a 26-row policy table that includes the case a
  hand-rolled version gets wrong: **`'0'` is not blank**, whatever `empty()` thinks.
  The `Persistence` deptrac layer arrives with a **Support-only** edge; the two cross-group
  edges RFC-0002 anticipates are deferred to item 10.3 and proved closed by a planted violation.

### Changed

- **`SqlStatement`'s constructor is now private, behind three named entry points** (spec r8
  **FR-33**; roadmap item **10.7**; **ADR-0041**, amending **ADR-0039**):
  `SqlStatement::literal()` for text written out in the source, `::fromQueryBuilder()` for a
  built query, `::composed()` for text assembled at runtime. `new SqlStatement(...)` no longer
  exists — a **second pre-1.0 break to this class in two PRs**, and the honest reading is that
  one PR should have shipped both.
  The point is that FR-33's guarantee is now **mechanical**: `literal()` takes a
  `literal-string`, so the PHPStan max level this project already runs on every PR **refuses**
  interpolated (`"… {$value} …"`), concatenated (`'…' . $value`), `sprintf()`-ed and
  `implode()`-ed statement text — verified by planting all four, and by confirming four
  legitimate shapes still pass, including the hand-written dialect SQL with a positional
  predicate that FR-33 exists to allow. ADR-0039 had rejected a *runtime* assertion while
  writing that a type-level guarantee was what this needed, and never considered the static
  one; item 10.7 is that omission closed. `composed()` is the single escape hatch — deliberately
  not named `unsafe*()`, since values still bind through real prepares — and has **zero uses
  inside this library**, which is what makes `grep composed(` the review list.

### Fixed

- **NFR-07's mutation gate now actually runs** (roadmap item **10.8**; **ADR-0040**).
  `infection.json5` never existed, so the `mutation` CI job's self-enabling config guard
  reported `present=false` and the job **passed in ~7 seconds having executed nothing** — the
  spec's *"≥ 70% MSI on Security/Database/Dto"* had been unenforced since M1. This is item
  2.7's coverage-gate shape a second time (there, pcov was installed and PHPUnit ran with no
  `--coverage` flag), and it says something about the self-enabling-guard pattern: nothing
  ever asked whether the file it waits for had arrived. Three further defects surfaced only by
  running the step — Infection cannot be a dependency of this package at all (its PHP floor is
  above this library's, and the older releases conflict with versions already locked), so it is
  installed into a throwaway project per ADR-0031; `--only-covered` is not an Infection option,
  so the step would have failed on argument parsing regardless; and Infection could not locate
  PHPUnit across two vendor directories, so the path is now stated explicitly. **Measured: MSI
  79%** (443 mutants killed, 117 escaped, mutation code coverage 100%) — the requirement is met
  with 9 points of headroom, and the floor stays at the spec's 70 rather than being raised to
  today's number. Proved able to fail before being trusted: 95% floor → red, reverted.

### Added

- **`D4np\Utils\Database\SqlStatement`** (spec r7 **FR-33**, RFC-0002; roadmap item **10.1**,
  opening **Milestone 10**; **ADR-0039**) — an immutable pairing of SQL text and its bound
  parameters. Motivated by the surveyed estate's 199 sites where a value was interpolated
  into SQL text against 0 sites where one was bound: a `(string, array)` method signature
  never stopped that from happening, because nothing in it says the two were assembled
  together. `SqlStatement` makes text-and-parameters one value, and `Persistence\Repository`
  (upcoming, item 10.3) will accept nothing else from a caller with hand-written SQL.

### Changed

- **`DatabaseConnection::select()`, `selectOne()` and `execute()` now take a single
  `SqlStatement` argument** instead of `(string $sql, array $parameters = [])` (ADR-0039).
  A breaking change to the `Database` group's public signature, made in a pre-1.0 MINOR
  (SemVer §4 permits it; `tools/bc_gate.py` treats it as bump-legal). No behavior changes:
  every value was already bound as a real parameter (ADR-0014's real prepares) under the
  old shape — this moves where a reviewer has to look, not what the driver receives.
  `QueryBuilder::get()`/`first()` and every test call site are migrated in the same PR.

- **phpbench benchmarks for NFR-10 (`FileSequence`) and NFR-12 (`Csv`)** (spec r6 §3,
  RFC-0002; roadmap item **9.6**, closing **Milestone 9**): `FileSequenceBench` and
  `CsvBench` under `src/bench/php/d4np/utils/`, wired into the same
  `bench_budget_gate.py` call ADR-0030 already established for the other four subjects
  (`benchSequenceNext<=200µs`, `benchWriteTenThousandByTen<=150000µs`). No new ADR and no
  production code change: ADR-0030's standing decision (CI-gated on Linux, same-runner,
  environment-asserted) already covers two more subjects added to it. NFR-12's other
  clause — streaming memory, never a full-table buffer — is `Csv::write()`'s
  construction, proven by `CsvRoundTripTest`, not benchmarked: a stopwatch cannot see an
  absence. This developer's machine measured `benchSequenceNext` at roughly 250× its
  budget via direct timing; read as the same environment artifact ADR-0030 already named
  on this exact box (Windows/NTFS I/O latency, not NFR-06's methodology), not as a defect
  — the Linux CI run is the authoritative measurement.

- **`D4np\Utils\Support\FileSequence`, `SequenceExhaustedException`** and **`File::update()`**
  (spec r6 **FR-32**, RFC-0002; roadmap item **9.5**; **ADR-0038**) — a rolling counter
  persisted to a file and safe across processes. The whole read-modify-write runs under
  **one** exclusive lock: `read()` + `write()` as two separately locked calls lets two
  processes both read `5` and both write `6`, and for a sequence a lost increment is a
  **duplicate identifier**. The cap **refuses** (`SequenceExhaustedException`) rather than
  wrapping, since wrapping re-issues identifiers already in use, and the refusal leaves the
  state untouched. A corrupt state file is refused and **left on disk as evidence** rather
  than reset — resetting re-issues the whole window; an absent or blank file is a legitimate
  fresh start. The window is a caller-supplied opaque string, so no timezone decision happens
  inside the library. `File::update()` is the general read-modify-write primitive this
  needed, sharing ADR-0005's lock, temporary file and atomic rename with the other two
  writers. Verified by **T-14**: four real processes, each number issued exactly once.

- **`D4np\Utils\Support\Csv`, `Delimiter`, `CsvSerializable`, `CsvException`** and
  **`File::writeStream()`** (spec r5 **FR-28/FR-29**, RFC-0002; roadmap item **9.4**;
  **ADR-0037**) — streaming CSV whose memory cost is one row, written through the atomic
  replacement of ADR-0005. **PHP's default backslash escape is disabled on every call**:
  measured, it is not a formatting preference but data corruption — a field ending in a
  backslash escapes the closing quote, so two fields go in and one comes back having
  swallowed the delimiter and the newline. Two more shapes PHP cannot express are handled:
  a single empty field is written as `""` (`fputcsv()` emits a bare newline, which is read
  back as nothing and loses the row) and a zero-column row is refused. Blank lines are
  skipped on read; a quoted empty field is not. `CsvSerializable`'s header/row pairing is
  **enforced** rather than requested in prose. The **formula guard is opt-in, default off** —
  it alters exported data, so it is a decision about where the file is going, and both
  states are tested against the OWASP corpus.

- **`D4np\Utils\Support\Url`** and **`InvalidUrlException`** (spec r4 **FR-27**, RFC-0002;
  roadmap item **9.3**; **ADR-0036**) — an immutable absolute-URL value: parse, inspect,
  recompose, compose queries. Three refusals, each a defect from the intake:
  **control characters are rejected up front** (probed: `parse_url()` does not reject them,
  it rewrites each to `_`, so a CRLF payload parses "clean" with components that differ
  from the input); **a scheme downgrade is refused** (`https`→`http`, `wss`→`ws`,
  `ftps`/`sftp`→`ftp`; an unknown target scheme is allowed through — a recorded limit); and
  **a `null` query value is refused at any depth** (`http_build_query()` drops it silently).
  An untouched query is preserved **byte-exact** so signatures computed over a URL survive;
  composition encodes per RFC 3986, not as an HTML form. `userInfo()` reports credentials
  truthfully and `withoutUserInfo()` strips them for logging.

- **`D4np\Utils\Support\Lookup`** (spec r3 **FR-30**, RFC-0002; roadmap item **9.2**) — an
  immutable code→label map with an explicit missing-key policy: `label()` throws
  `OutOfBoundsException`, `labelOr()` substitutes a caller-supplied default, `tryLabel()`
  returns `null`. Replaces the estate pattern of a silent `"missing: {key}"` placeholder
  string, indistinguishable from real data once it reaches a UI or a CSV export. A code
  mapped to `''` is distinguished from an absent code (`array_key_exists()`, not `??`).

- **Six `Str` additions** (spec r3 **FR-31**, RFC-0002; roadmap item **9.1**) —
  `collapseWhitespace()` (ASCII-scope, UTF-8-safe by construction), `nullIfBlank()`
  (judges blankness, mutates nothing), `transcode()` (**strict by default** — lossy
  conversion is an explicit opt-in flag, and a missing `ext-iconv` is a clear refusal
  rather than silent degradation; unknown encodings are named distinctly from
  unconvertible data), multibyte-safe `padLeft()`/`padRight()` (PHP 8.3
  `mb_str_pad()`-compatible semantics; code points counted via PCRE, no mbstring
  dependency), `shortClassName()` (deterministic `class@anonymous` for anonymous classes —
  their runtime names embed a platform-shaped file path), and `pascalCase()`
  (ASCII case mapping, multibyte pass-through, deliberately not idempotent and documented
  as such). `ext-iconv` joins composer `suggest`.

- **The PSR-7 bridge's publication pipeline** (**ADR-0035**, spec 02 r3) —
  `.github/workflows/bridge-release.yml` and `tools/bridge_release_gate.py`, closing Milestone 8.
  On a `utils-psr7-bridge-vX.Y.Z` tag it verifies the tag is annotated and signed (ADR-0032's
  GitHub-side mechanism, reused), checks it against the package changelog's `## [X.Y.Z]` heading —
  a Composer library carries no version constant, so the changelog is what anchors a bridge tag —
  runs the contract suite in **release mode**, then splits the package and pushes it to the
  generated split repository as a plain `vX.Y.Z`.
  **Release mode is never skipped.** This project's standing pattern is to skip a gate that cannot
  run yet and self-enable later (lesson L-0010), and that is right for a gate that *checks*
  something and wrong for one that *establishes* something: release mode is the only evidence for
  the package's central published claim, so skipping it would publish a package nobody has tested
  against a released core. Consequently **no bridge version can be published until `egl/utils` has
  a release** — the pipeline fails today, by design, saying exactly that.
  **Tag-grammar isolation is a guard, not a glob.** Spec r1 planned to verify with a throwaway tag
  that `v*.*.*` does not match `utils-psr7-bridge-v*`; each workflow now refuses a ref that is not
  its own shape, which is stronger — it does not depend on GitHub's matcher staying what it is —
  and pushes no test tag to a public repository. `release.yml` gains that guard as its first step.

- **`Request::queryAll()`, `postAll()`, `cookieAll()` and `uploadedFiles()`** (**ADR-0034**) —
  whole-collection readers, added because roadmap item 8.2 found spec 02's BFR-04…BFR-07 were not
  implementable: every core collection reader was key-scoped, only `headers()` returned a whole
  collection, and a POST body and `$_FILES` were recoverable from nothing else the class exposed.
  Purely additive, so **no BC break**.
  **This is not a retreat from ADR-0025.** That rule governs *scalar* reads — `queryString('email')`
  refuses an array because `(string) ['x']` yields the literal `"Array"`, a value nobody sent. A
  whole-collection reader promises no conversion and so cannot convert wrongly; the typed accessors
  keep refusing, unchanged, and a test asserts both behaviours side by side. `headers()` and
  `file()` already returned raw collections from this class.
  Each returns a **copy** — PHP arrays are values — so no caller receives mutable access to a
  request's state. `serverAll()` was deliberately *not* added: nothing in the bridge contract needs
  it, and everything the core reads from `$_SERVER` is already reachable through `method()`,
  `uri()`, `isSecure()` and `headers()`.

- **`packages/utils-psr7-bridge/` scaffold** (roadmap item **8.1**, implementing ADR-0033 and
  spec 02 §2) — a complete Composer package with its own manifest, PSR-4 roots
  (`D4np\Utils\Bridge\Psr7\`), PHPStan configuration at max level, README and changelog. **No
  converters yet**: they land with their contract suite in item 8.2, and a stub throwing
  "not implemented" would be a worse artifact than an empty PSR-4 root.
  A new **`quality / PSR-7 bridge contract`** CI job runs the package against the core **from the
  working tree** via a path repository injected into the CI workspace only — the same-PR guarantee
  ADR-0033 chose the monorepo for. It asserts `egl/utils` resolved with source type `path`, because
  a quiet fallback to a published core would leave that guarantee a fiction with every test still
  green. The job self-enables in two stages (absent package → notice; scaffold without tests →
  notice; a test file appears → it runs), all three branches verified.
  **`BridgePackageBoundaryTest` lives in the core's suite**, not the package's, because the
  invariant with the sharpest consequence — no `repositories` entry in the committed manifest —
  breaks only *standalone* installs of the published package, which nothing in this repository would
  otherwise notice. Running PR mode locally mutates the manifest exactly that way; planting both
  mutations fails two of its tests by name.
  A **deptrac `Bridge` layer** makes a core → `D4np\Utils\Bridge\` dependency a build failure.
  Verified, and instructively: an unused `use` statement produces **0 violations** — deptrac
  resolves type dependencies, not imports — while a real type reference produces
  `Response must not depend on Psr7Bridge`.
  The package declares `egl/utils: ^0.7` against a core release that **does not exist yet**
  (`VERSION` is `0.0.0`, no tag), so it is not installable standalone until the core ships `v0.7.0`.
  That is a true statement of the dependency rather than a placeholder, and the package README says
  so where someone would otherwise meet it as a resolution error.

- **The PSR-7 bridge packaging decision** (**ADR-0033**, closing Milestone 7 and RFC-0001's A-8) —
  plus [`docs/specs/02_spec_psr7_bridge.md`](docs/specs/02_spec_psr7_bridge.md), the frozen contract
  Milestone 8 implements. **No code lands**: item 7.4's deliverable is the decision.
  Two findings reframed "subtree vs second repository" before options could be weighed: Packagist
  requires `composer.json` at a repository root, so a second repository exists under **every**
  option — the real question is whether it is authored or **generated**; and the maintainer struck a
  phantom cost from the analysis (EADOS is an external generation tool, not repository governance,
  so "duplicating" it never belonged on the ledger).
  Decision: canonical source under **`packages/utils-psr7-bridge/`** in this monorepo; the split
  repository is a generated, **read-only** publication target; **independent versioning by design**
  — `utils-psr7-bridge-vX.Y.Z` tags, signed at the source and verified before splitting
  (ADR-0032's mechanism), translate to `vX.Y.Z` on the split repository. The load-bearing property
  is same-PR integration: a core change that breaks the conversion contract fails in the PR that
  introduces it — with its flip side named, a **release-mode** re-test against the *released* core
  before any bridge tag ships.
  Imported ADR-002's conversion contract is now numbered, testable clauses (**BFR-01…BFR-22**),
  including its two sharpest edges: a PSR-7 response bearing multiple `Set-Cookie` headers is
  **refused** rather than comma-joined (RFC 6265 cookie strings contain commas — joining corrupts
  them silently), and uploaded files cross the `$_FILES` ↔ `UploadedFileInterface` boundary with
  error codes preserved verbatim and **no stream access on a failed upload**. Milestone 8
  (items 8.1–8.3) carries the implementation; the Spec Coverage Map's §7 row honestly **reopens**,
  since the spec-named bridge contract tests do not exist yet.

- **A verified release path** (**ADR-0032**) — spec §8, NFR-07. `release.yml` previously drafted a
  GitHub Release from whatever was tagged, having checked only that `composer install` succeeded. It
  is now three jobs, and **nothing is drafted until all of them pass**, because a draft is
  publishable:
  the tag must be **annotated and signed** (`verified == true` per GitHub's own verification of the
  tag object — asked of GitHub rather than verified with imported keys, so no key material reaches
  the runner and no keyring goes stale on a rotation); it must **agree with the tree it points at**
  (`tools/release_gate.py`); the repository's consistency invariants must hold **at the tag**; and
  the tagged tree must pass the suite on **PHP 8.1, 8.2 and 8.3**, since a tag can point at a commit
  CI never ran.
  `release_gate.py` closes a hole no lint can reach: `consistency_lint.py` runs on a working copy and
  has **no tag**, so `git tag -a v0.2.0` on a tree whose constant still says `0.1.0` ships a release
  that installs as one version and reports itself as another, with nothing inside the tree
  disagreeing. It also requires the release notes and per-version changelog split to exist *and be
  indexed*.
  **Packagist pulls rather than being pushed to**: it mirrors the repository through its own GitHub
  integration, so no Packagist token lives here and AGENTS.md §11's boundary — the agent drafts, the
  maintainer publishes — stays intact.

### Fixed

- **`release.yml` referenced `matrix.toolchain` in a job with no matrix**, so the PHP version
  expression silently fell through to `'8.3'` — the same rendering artifact roadmap item 1.9 fixed in
  `ci.yml` and which was left uncorrected here. It survived because `'8.3'` is a legal answer, so
  nothing was ever red. The matrix is **restored** rather than the expression hardcoded: the release
  must be tested on the PHP 8.1 floor this library promises. `coverage: pcov` is also dropped from
  that job, which runs no coverage gate.

- **Backward-compatibility gate on release PRs** (**ADR-0031**) — spec NFR-07, imported spec §8.
  A new `quality / backward compatibility` CI job plus `tools/bc_gate.py`.
  **`roave/backward-compatibility-check` is installed into a throwaway project, not into
  `composer.json`.** Every release from 8.7.0 requires PHP ≥ 8.2 (later ≥ 8.3, ≥ 8.4) while this
  library supports **8.1** — hence the `config.platform.php` pin at `8.1.34`. As a dev dependency it
  would resolve to 8.6.0 at best, break the 8.1 matrix cell, or force abandoning 8.1. It analyses
  this package from outside, so its floor need not be ours; upstream ships no PHAR any more.
  **The job skips while no `v*.*.*` tag exists** and self-enables at the first one: the checker
  hard-fails with *"Could not detect any released versions for the given repository"*, and the first
  release PR is exactly when it would first run. A release PR is detected from a `Version.php` diff
  — step 1 of the documented release process — rather than a label a maintainer could forget.
  **`bc_gate.py` gates breaks by the bump they arrive in**, which the checker cannot: it reports
  breaks but not whether *this* bump permits them, and pre-1.0 that matters — SemVer 2.0.0 §4 permits
  a break in `0.7 → 0.8` and forbids the identical break in `0.7.0 → 0.7.1`. Breaks fail a PATCH and
  a post-1.0 MINOR; they pass a MAJOR and a pre-1.0 MINOR, always printing what was permitted, since
  "the gate passed" and "there were no breaks" are different facts.

### Changed

- **The deprecation window is now stated as one full *published* MINOR** (**ADR-0031**), resolving a
  contradiction that had been in the repository since scaffold: `docs/workflow/maintenance.md` said
  deprecations are kept *"for at least the rest of the current MAJOR line"* while the imported spec
  §8 says they *"live one minor before removal"*. The spec is the contract.
  The window is measured in released versions rather than time or commits — deprecating and removing
  inside one release warns nobody, whatever the version numbers say — and removal must still land in
  a bump that permits a break, since removing a public symbol *is* one. The section also gains the
  pre-1.0 case it never had: deprecate in `0.N`, remove no earlier than `0.N+2`.

- **Benchmark regression and budget gates in CI** (**ADR-0030**) — spec NFR-06, plus
  `.github/workflows/nightly.yml`. No production code changed.
  NFR-06's *"regression > 10% fails"* could not be built against a stored baseline: measured from
  this repository's own CI history, nine `master` runs with `QueryBuilder` and its benchmark
  **provably unchanged** ranged **2.684–3.767 µs — 40.4% peak to peak** on GitHub's shared runners.
  Five consecutive phpbench passes inside **one** job spread **0.4–1.5%**, so the gate measures the
  base commit and HEAD **on the same runner** via `git worktree`, which leaves the 10% threshold
  roughly six times the noise it must clear.
  `tools/bench_regression_gate.py` (relative) and `tools/bench_budget_gate.py` (absolute ceilings,
  and a *range* since NFR-05's lower bound is the serious one — a hash that got faster got weaker)
  both hold `coverage_gate.py`'s absence-is-failure discipline. A subject new at HEAD is a notice; one
  that disappeared is reported, because a deleted benchmark is how a regression stops being visible.
  `tools/assert_bench_env.php` refuses to let the suite run outside NFR-06's environment (PHP 8.3,
  OPcache and JIT off) rather than setting those values in a workflow and trusting them.
  `tools/bench_ratio_gate.py` is finally wired into CI, having sat in the tree since item 3.5 marked
  it "advisory today, not yet wired into CI".

### Fixed

- **All three deferred NFR budgets are met**, and the reason is a measurement error rather than an
  optimisation — **no production code changed**. NFR-01 **0.958 µs / 2.40×** (≤ 5 µs, ≤ 3×), NFR-03
  **3.776 µs** (≤ 10 µs), NFR-05 **148.3 ms** (50–200 ms). The earlier records reporting NFR-03 and
  NFR-05 as *over* budget were produced with `--php-disable-ini`, which does not disable OPcache alone
  but discards the entire `php.ini` and every extension with it, on Windows — not NFR-06's
  environment. They were honest measurements of a different thing.
  Still outstanding and named rather than skipped: the runner is not NFR-06's Ryzen 7 5800X, so every
  budget is "met on this runner"; and NFR-04 is ungated, because it budgets a *memory delta* and
  phpbench's `mem_peak` reports the whole process's peak.

- `D4np\Utils\Errors\Result`, `Logger` and `ExceptionHandler` (**ADR-0029**) — spec FR-16, FR-17,
  FR-18. **Milestone 6 is complete.**
  **`Result`** replaces boolean/null returns for service outcomes (RFC-0001): `success()`,
  `failure()`, `try()`, `map()`, `flatMap()`, `recover()`, `orElseThrow()`, `orElse()`, `error()`. A
  failure carries a **`Throwable`**, so `orElseThrow()` rethrows the *original instance* with the
  trace still pointing at where the operation failed — manufacturing an exception at unwrap time
  would put the trace in the accessor. `map()` deliberately does **not** catch: a mapper that throws
  has a defect, and converting a `TypeError` into a business failure would hide it. `Result::try()`
  is the named opt-in for catching.
  **`Logger`** is a PSR-3 logger writing one line per record. Two behaviours were probed rather than
  assumed, and both defaults lose data in silence: `file_put_contents()` with `LOCK_EX` on a `php://`
  stream returns **`false` and writes nothing**, so real files are locked and streams are not; and a
  `Throwable` in the context **encodes to `{}`** — `json_encode()` sees only public properties — so
  throwables are expanded explicitly, recursively through `getPrevious()`. The destination is
  validated at construction and writes never throw, because a logger that throws inside an exception
  handler turns a handled failure into a fatal one. An unknown level throws
  `Psr\Log\InvalidArgumentException`, as PSR-3 requires.
  **`ExceptionHandler`** produces an RFC 7807 problem document and captures fatal errors through a
  shutdown handler — the only route by which an `E_ERROR` is ever reported. **In production it
  withholds the exception message as well as the trace**, which is stricter than FR-18's letter and
  recorded as such: a message names schemas (`Base table not found: 'users_backup'`) and paths
  (`/srv/app/config/secrets.php`) just as effectively as a trace. A random **reference** goes into
  both the response and the log record, so the two can be correlated. Debug is off unless the
  environment says otherwise, so a missing `APP_DEBUG` cannot be what exposes a trace.

- `D4np\Utils\Container\Container` (PSR-11), `ServiceProvider`, and the `ContainerException` /
  `NotFoundException` / `CircularDependencyException` trio (**ADR-0028**) — spec FR-04, FR-05,
  NFR-02, imported ADR-001. Constructor autowiring over the **shared** `ReflectionCache`, plus
  `instance()`, `singleton()`, `factory()` and `bind()` definitions.
  **The refusals are the design.** Imported ADR-001 bought a hand-written container by promising it
  would fail loudly wherever a mature one adds a feature, so an unbound interface, an abstract
  class, a non-public constructor, a built-in or untyped parameter without a default, and a
  union-typed parameter are each declined with a message naming the parameter and the class.
  Circular dependencies throw with the **full path** (`A -> B -> C -> A`) and are a distinct
  exception type, because the container acts on the distinction: a parameter default may answer an
  absent dependency and must not answer a cycle.
  Autowired instances are **shared** by default, with `factory()` as the explicit opt-out; `has()`
  answers for what `get()` would actually do rather than for the registration table.
  **`get()` declares a conditional return type PSR-11 does not have** — `get(Mailer::class)` is a
  `Mailer`, while a string-keyed entry stays `mixed`. `ContainerInterface::get()` carries no return
  type at all in psr/container 2.0.2, so without this every consumer at PHPStan max would narrow at
  every call site.
  **NFR-02 measured and met**: 0.173 µs warm singleton resolve (≤ 2 µs) and 18.593 µs first
  autowired resolve of a four-class graph (≤ 30 µs), on a developer machine rather than NFR-06's
  reference machine — not asserted as a CI gate, per ADR-0018; nightly tracking is item 7.1.

- **T-03**, spec §7's session/CSRF integration suite, against a real `php -S` process — 17 tests
  covering everything ADR-0026 structurally could not reach in CLI: FR-15's three flags on a live
  `Set-Cookie`, the configured policy reaching the header, state surviving across requests,
  `regenerate()` rotating the identifier while carrying the data forward, cross-session and
  cross-scope CSRF rejection, `rotate()` invalidating the previous token, and a token not
  outliving its session.
  The suite's centre is that **the pre-rotation identifier stops resolving**: `session_regenerate_id(true)`
  deletes the old record, and without the `true` the session is merely renamed while the old
  identifier keeps working — session fixation. Planting that one-character change fails exactly one
  test; rotation still happens, data still survives, flags are still correct, and the other sixteen
  stay green.
  Two probe findings shaped the harness: **`Secure` is emitted over plain HTTP** (PHP writes the
  attribute unconditionally, enforcement is the browser's), so no TLS is needed; and reading a live
  process's pipe blocks forever, so the server logs to a file. A server that cannot start **fails**
  the suite rather than skipping it.
- `docs/specs/01_spec_utils.md` gains a **Revision history** and moves to **r2**.

- Project bootstrap: enterprise scaffolding generated by EADOS (agent contract, docs system,
  CI gate, consistency lint).
- Composer build system: the package is `egl/utils`, PSR-4 autoloading `D4np\Utils\` from
  `src/main/php/d4np/utils/`, requiring PHP >= 8.1 with `ext-pdo` and `ext-fileinfo`, plus the
  interface-only `psr/container` and `psr/log`. Component-group skeleton laid out per
  RFC-0001 (`Dto`, `Container`, `Database`, `Security`, `Http`, `Errors`, `Support`).
- Test harness: PHPUnit 10.5 wired via `phpunit.xml.dist` (`src/test/php/d4np/utils/`,
  coverage source `src/main/php/d4np/utils/`), with a `BootstrapTest` smoke suite proving the
  PHP version floor and the PSR-4 autoloader wiring.
- Formatter + linter: PHP-CS-Fixer 3.95 (`@PSR12`/`@PSR12:risky`, `declare_strict_types`,
  `strict_comparison`/`strict_param`) and PHPStan 2.2 at `level: max`, both scoped to
  `src/main/php/d4np/utils/` and `src/test/php/d4np/utils/`. `ergebnis/composer-normalize`
  keeps `composer.json` canonical (CI `hygiene` job). `.gitattributes` normalizes line endings
  to LF so Windows checkouts match what CI lints.
- `D4np\Utils\Version::VERSION` (`Version.php`) as the single source of truth for the
  released version, kept in lockstep with the README badge by `tools/consistency_lint.py`.
- Enum-typed DTO properties: a **backed** enum (`enum Status: string { … }`) hydrates from its
  scalar backing value via `tryFrom()`; an invalid value is a `TypeMismatchException` naming the
  valid ones. A **pure** (non-backed) enum has no scalar to resolve from and stays
  instance-only. `#[Group('T-01')]` covers the case.
- `D4np\Utils\Dto\Collection` (**ADR-0010**) — an immutable, functional list wrapper with
  `map()`/`filter()`/`reduce()`, `@template-covariant` (sound because nothing mutates), and an
  **optional** runtime `instanceof` guard: PHP has no runtime generics, so `Collection<Foo>` is
  enforced by PHPStan and, when asked, by `Collection::of(Foo::class, …)`. `filter()` carries
  the guard across; `map()` drops it, since the element type changes.
- `D4np\Utils\Dto\CollectionOf` — `#[CollectionOf(Foo::class)]` on a `Collection` constructor
  parameter lets hydration build its elements, closing the `Collection<T>` gap deferred from
  the DTO item. An attribute rather than the docblock because PHP resolves its argument to a
  real class-string, whereas `Collection<Addr>` in a docblock is an alias token only a full
  parser could resolve. Failures name the index: `stops.1.postcode`.
- `D4np\Utils\Dto\WithersTrait` (**ADR-0009**) — `$user->with(name: 'Grace')` returns a modified
  copy of an immutable DTO, leaving the receiver untouched. It **rebuilds through the
  constructor** rather than cloning, which works identically on PHP 8.1/8.2/8.3 (8.3's readonly
  amendment only permits reassignment *inside* `__clone()`) and, independently of versions,
  keeps any validation the constructor performs applying to the result. Changes route through
  the same checks as hydration: an undeclared name raises `UnknownKeyException`, a bad value
  raises `TypeMismatchException`.
- `D4np\Utils\Dto\DataTransferObject` (**ADR-0008**) — typed, immutable DTOs hydrated from
  arrays: `UserDto::fromArray($data)` is **strict by default** (an undeclared key raises
  `UnknownKeyException`, so a typo or a mass-assignment attempt cannot pass silently), and
  `UserDto::lenient()->fromArray($data)` is the per-call opt-out that ignores extra keys while
  still requiring declared ones and still type-checking. Nested DTOs hydrate recursively, and
  every failure names its path (`customer.address.postcode`). Absence follows RFC-0001 R-4: a
  defaulted parameter keeps its default, a nullable one without a default becomes `null`, and
  anything else raises `MissingKeyException`. `Collection<T>` properties land with `Collection`
  itself.
- `tools/coverage_gate.py` enforces the **≥ 90% line-coverage floor** (**ADR-0007**) that
  `AGENTS.md` §10 and spec NFR-07 stated but nothing measured — PHPUnit 10 has no fail-under
  option, so a dedicated CI job produces a Clover report and the gate compares it. A missing or
  empty report fails rather than skipping. The `build` matrix no longer loads `pcov`, which it
  never used. **The first measurement came back at 82.08%** — the stated bar had never been met; raised to **91.51%** in the same PR rather than by lowering the threshold.
- Tests for `File`'s failure paths (unwritable directory, unopenable lock file, failed rename
  leaving no temporary file behind, unreadable file for `read()` and `mime()`) — the branches
  ADR-0005's design exists to handle, previously never executed — and for the static-utility
  contract every helper class follows (final, non-instantiable, private inert constructor, all
  public methods static).
- Spec §7's **T-05 property-test suite** (Json round-trips, `Str::slug()` idempotence, `Env`
  boolean coercion) is now a runnable, countable unit: `vendor/bin/phpunit --group T-05`. The
  three cases already existed, landed with their own items; `#[Group('T-05')]` ties them
  together so the spec's named suite is a mechanical fact rather than a docblock claim.
- `D4np\Utils\Support\ReflectionCache` with `ClassMetadata`/`ParameterMetadata` (**ADR-0006**):
  the single per-class constructor-metadata cache imported ADR-001 commits to, shared by the DTO
  hydrator (M3) and the Container (M6) — reflection is paid once per class, every later lookup is
  an array hit, which is what NFR-01/NFR-02 rest on. Instance-scoped, no interface, no static
  accessor; union and intersection types are recorded as un-autowirable with their declaration
  preserved for diagnostics, and a failed reflection is not cached.
- `D4np\Utils\Support\Env::get()`: boolean coercion built on PHP's own
  `filter_var(…, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)` — fixes the classic bug where
  `getenv('FLAG')` returns the truthy **string** `"false"`. An explicitly empty variable
  (`FOO=""`) returns `''`, not `false` — a deliberate exception, since the coercion recognises
  yes/no-shaped words, not "unset-looking" values.
- `D4np\Utils\Support\Json::encode()`/`decode()`: always pass `JSON_THROW_ON_ERROR`, wrapping
  the native `\JsonException` via `JsonException::wrap()` (RFC-0001 R-7) so a caller can never
  forget the flag and get a silent `null`/`false` back.
- `D4np\Utils\Support\File` (**ADR-0005**): `write()` replaces the target **atomically** — a
  temporary file in the same directory, `rename()`d over — so a reader never observes a
  half-written file; writers are serialised through an exclusive `flock()` on a `<path>.lock`
  sidecar, which is where the lock has to live because a handle held on the target makes the
  replacement fail on Windows. `read()` reads under a shared lock. `mime()` detects from
  **contents** via `ext-fileinfo`, never from the filename. Every failure throws `FileException`
  instead of returning `false`.
- `D4np\Utils\Support\Str`: `slug()` (three-tier ASCII transliteration — `ext-intl`, then
  `iconv`, then a printable-ASCII filter, degrading gracefully rather than throwing; idempotent
  per spec T-05), `uuid()` (RFC 4122 v4 from `random_bytes()`), and `random()` (CSPRNG
  alphanumeric tokens via `random_int()`, custom length and alphabet).
- Exception hierarchy under `D4np\Utils\Support\` (**ADR-0004**): the `UtilsThrowable`
  interface every exception implements — `catch (UtilsThrowable $e)` catches anything this
  library raises — over `UtilsException extends \RuntimeException`, with
  `DatabaseException`, `HttpException`, `FileException`, `JsonException` (wrapping PHP's native
  one via `wrap()`, original preserved as `getPrevious()`), and `HydrationException` carrying
  the failing property path, with `UnknownKeyException`, `MissingKeyException` and
  `TypeMismatchException` under it. Concrete leaves are `final`; `UtilsException` and
  `HydrationException` are the documented extension points.
- phpbench benchmarks for NFR-01 (hydration) and NFR-04 (memory) (**ADR-0011**):
  `HydrationBench` and `MemoryBench` under `src/bench/php/d4np/utils/`, sharing one
  `TenScalarPropsDto` fixture. `MemoryBench` carries a real, CI-enforced
  `@Assert('mode(variant.mem.peak) < 16 mebibytes +/- 10%')` — comfortable headroom measured.
  NFR-01's ≤3× hydration-vs-manual-construction ratio cannot be a phpbench `@Assert` (its
  `baseline` means a previous tagged run, not a sibling subject in the same run), so
  `tools/bench_ratio_gate.py` reports it standalone from a `--dump-file` XML report instead of
  gating CI. Measured: hydration is currently **~15.4×** manual construction, well over budget —
  recorded honestly rather than adjusted to pass, shipped non-blocking by explicit maintainer
  decision, with closing the gap filed as roadmap item 3.7. The `benchmark` CI job (self-skipped
  since item 1.9) is now active, since `phpbench.json.dist` exists.
### Fixed

- `masterminds/html5` floored at `^2.7.5` in `require-dev`. It is a transitive dependency of
  `symfony/html-sanitizer`, and its 2.7.2 release passes `null` to a `string` parameter of
  `DOMImplementation::createDocument()` — deprecated on PHP 8.1+, and so a failure under this
  project's warnings-as-errors bar. Only the `prefer-lowest` CI job saw it; the normal resolution
  picks 2.10.1. 2.7.5 is the exact fixing version, found by bisecting upstream rather than by
  choosing a comfortable floor.
- **NFR-03's benchmark measured the wrong workload** (**ADR-0020**, correcting **ADR-0018**). The
  subject added a five-column `select()`, an `orderBy`, a `limit` and an `offset` on top of its
  five conditions — twelve quoted identifiers where NFR-03 budgets a *"5-condition SELECT"* — while
  its own docblock claimed to count exactly five conditions. Roughly two thirds of the ~23 µs gap
  reported at item 4.5 was benchmark scope, not builder cost. The subject now matches the spec's
  wording; the heavier shape is **kept and still published** as `benchBuildRealisticPagedQuery`,
  asserting nothing, so the correction cannot be mistaken for a benchmark narrowed until it passed.
  Item 4.5's report and ADR-0018 are annotated rather than silently edited.

### Changed

- **Spec §7/T-03 amended to revision r2** (**ADR-0027**, authorised by the maintainer): the
  `hash_equals` **timing test** is replaced by a **mechanism assertion**, required in both
  directions — that `hash_equals()` is the comparator on every secret-comparison path, and that
  `==`, `===`, `strcmp()`, `strncmp()` and equivalents are absent from those paths.
  Measured before it was changed, on 64-char tokens over 2,000,000 iterations × 5 rounds: the
  gradient a timing test depends on is **+2.8 ns/op** against **38 ns/op** of noise — about **13×
  below the noise floor** on an idle machine, and six orders of magnitude below request latency
  over HTTP, where T-03 runs. `hash_equals()`'s own gradient measures *negative*.
  The scoping point is the substance: whether `hash_equals()` is constant-time is **PHP's** contract,
  verified upstream; the property that exists at this layer is *which comparator the code invokes*,
  which is decidable exactly from the source. Rejected: asserting `hash_equals()` is measurably
  slower than `===` (an implementation artifact with an inverted failure profile — red on a
  legitimate PHP optimisation, green on a slow non-constant-time comparator), and a dudect-style
  statistical test (right technique, wrong layer; zero discriminative power at this SNR).
  This is an amendment, **not a standing deviation** — there is nothing left to track.
- The constant-time assertion moved from `CsrfTokenTest` to a dedicated
  `Security\ConstantTimeComparisonTest` covering every registered secret-comparison path —
  `CsrfToken::validate()` via `hash_equals()` and `Hash::verify()` via `password_verify()`, the
  correct comparator there. The registry **guards its own completeness**: any constant-time call
  outside a registered path fails the suite, naming the file and line.
- `QueryBuilder` resolves its driver's quoting characters **once per builder** instead of per
  identifier (**ADR-0020**), and builds conditions by concatenation rather than `sprintf()`.
  Together: **14.430 → 12.979 µs** (−10.1%) on the corrected five-condition subject. The driver
  saving is sub-noise per call, so it is pinned by an exact **count** (7 → 1 lookups for a
  five-condition query, 13 → 1 for a paged one) rather than a flaky timing assertion.
  **NFR-03 remains unmet** — ~30% over ≤ 10 µs, versus the 2.3× previously reported. The residual
  is deferred to item 7.1 because phpbench (12.979 µs) and a plain in-process loop (9.246 µs)
  disagree by more than the remaining overage, and an empty phpbench subject costs 0.079 µs — so
  it is not harness overhead, and which instrument is authoritative is NFR-06/7.1's question.

### Added

- `D4np\Utils\Http\Session`, `CsrfToken`, `SameSite`, and the `SessionStore` / `SessionApi`
  interfaces with `NativeSessionApi`
  (**ADR-0026**) — spec FR-12 and FR-15. `Session` applies `httponly`, `secure` and
  `samesite=Lax` before starting, and `regenerate()` wraps `session_regenerate_id(true)` — the
  `true` being the half that closes session fixation, since without it the old identifier keeps
  working. `CsrfToken` issues 32 CSPRNG bytes per scope and compares with `hash_equals()`.
  **The cookie policy is exposed as a value** (`cookieParams()`) and `CsrfToken` depends on a
  three-method `SessionStore` rather than `$_SESSION`, because PHP will not run a session in CLI —
  `session_start()`, `session_set_cookie_params()` and `session_regenerate_id()` all return
  `false` — so without those seams FR-15's flags and all of CSRF validation would have had no unit
  assertion at all.
  `secure` defaults to `true` with an explicit named opt-out for local `http://` development,
  rather than auto-detection from `$_SERVER['HTTPS']` which would silently disable the flag behind
  a misconfigured proxy. `httponly` has **no** opt-out. `SameSite` is an enum, so an illegal value
  is a compile-time impossibility; `None` without `Secure` is refused at construction, because
  browsers drop that combination entirely.
  A token is issued **once per scope and reused** — regenerating per render would invalidate the
  token in another open tab — with `rotate()` as the explicit call for a privilege transition.
  Scope names are validated: a scope becomes a session-storage key, so one taken from user input
  would let a client grow the session record one key per request.
  PHP's session functions themselves sit behind a third seam, `SessionApi` (**ADR-0026 §8**), whose
  `NativeSessionApi` default is five single-statement delegations. That exists for one property
  behaviour cannot see: the cookie parameters must be applied **before** the session starts, since
  `session_set_cookie_params()` has no effect afterwards. Get it wrong and the session still works
  perfectly — with a cookie carrying none of FR-15's flags. Only the call sequence distinguishes the
  two, so a fake asserts the sequence.
  **Known gap:** that a real browser cookie carries the flags, and that a real identifier changes
  across `regenerate()`, remain behavioural — roadmap item 6.3's `php -S` integration suite owns
  them.
- `D4np\Utils\Http\Request` and `D4np\Utils\Http\Response` (**ADR-0025**) — opens Milestone 6.
  Native lightweight wrappers **mirroring PSR-7's naming without its types**, per RFC-0001: the
  optional `egl/utils-psr7-bridge` is the only sanctioned crossing point, and these never grow
  middleware ambitions.
  **The typed accessors refuse rather than coerce**, which is the security decision: `?email=x`
  gives a string but `?email[]=x` gives an **array** — the same key, a different PHP type, chosen by
  the client. A `(string)` cast yields the literal `"Array"` and `implode()` invents a value nobody
  sent, so a scalar accessor handed a non-scalar returns its default instead.
  `queryList()`/`postList()` are there for when a list is genuinely expected, and refuse scalars
  rather than wrapping them. `queryInt()` uses `FILTER_VALIDATE_INT`, not a cast, because
  `(int) "12abc"` is `12`.
  Headers are derived from `$_SERVER` (`getallheaders()` does not exist outside Apache-like SAPIs),
  including `CONTENT_TYPE`/`CONTENT_LENGTH` which CGI reports without the `HTTP_` prefix.
  `isSecure()` ignores `X-Forwarded-Proto` — client-supplied absent a trusted proxy — but does
  handle `$_SERVER['HTTPS']` being the string `'off'`.
  `Response` is immutable, refuses CR/LF/NUL in header values (**response splitting**) at the point
  they are *set* rather than at send time, and stores header names case-insensitively so a
  duplicated `Content-Type` cannot smuggle a second interpretation past a proxy. `json()` encodes
  through `Json::encode()` so an unencodable value raises instead of putting `false` in the body;
  `html()` deliberately escapes nothing, since escaping is a render-time decision (ADR-0019).
- Spec §7's **Hash matrix** and NFR-05's timing (**ADR-0024**) — closes Milestone 5. NFR-05 is a
  **range** (50–200 ms), unlike every other budget here, and the *lower* bound is the serious one:
  a hash completing in 5 ms means the work factor is inadequate. So it is split by what each half
  can prove. The **security** half is asserted as the **work factor** — `memory_cost`/`time_cost`
  against OWASP's published Argon2id minimums — which is machine-independent and catches what a
  stopwatch cannot: a duration cannot distinguish strong parameters on slow hardware from weak ones
  on fast. The **capacity** half is a benchmark asserting nothing (`HashBench`).
  Measured `Hash::make()` at **349 ms against the 50–200 ms range** — over, and deliberately **not**
  fixed: the cost parameters are PHP's defaults and clear OWASP's floor by more than 3× on memory,
  so lowering them to hit the range would trade security for latency. `verify()` is measured too
  (~348 ms): NFR-05 does not budget it, but it runs on **every login** and is what actually caps
  authentication throughput.
  Documented PHP behaviour: `password_needs_rehash()` reports `true` for **stronger** parameters as
  well as weaker ones, because it compares for equality with the current defaults — so a hash
  hardened beyond them is silently downgraded on next login.
- Spec §7's **OWASP XSS corpus snapshot suite** and **DOM-bypass corpus** for `richText()`
  (**ADR-0023**). Every payload is run through all four escaper contexts and recorded in a
  committed snapshot, so any change to escaping output becomes a reviewable diff rather than silent
  drift — paired with context invariants, because **a snapshot proves stability, not safety**: a
  snapshot of broken output is a valid snapshot. Re-recording is deliberate (`UPDATE_SNAPSHOTS=1`),
  never automatic, since an assertion that repairs itself is not an assertion.
  For mutation XSS the load-bearing check is **idempotence** — `richText(richText($x))` must equal
  `richText($x)`. mXSS payloads are inert when parsed once and become executable after re-parse, so
  "no `<script>` in the output" cannot detect them; instability under re-parse is the signature, and
  it holds for payloads nobody has written yet.
  **Correction to ADR-0021:** it claimed the scheme allowlist "is what refuses `javascript:` and
  `data:`". A probe adding `javascript` to the allowed schemes **passed** — `symfony/html-sanitizer`
  refuses that scheme unconditionally. The allowlist is the sole barrier for `data:` (including
  `data:text/html`) and defence-in-depth for `javascript:`. The restriction is unchanged; the
  explanation of it was imprecise, which matters when deciding whether to widen the list.
- `D4np\Utils\Security\Hash` (**ADR-0022**) — password hashing with an Argon2id default, per spec
  FR-11. **`PASSWORD_DEFAULT` is deliberately not used**: it is bcrypt (`'2y'`) on every PHP release
  to date, even where Argon2id is available, so code reaching for it expecting "whatever is
  strongest" silently gets the weaker algorithm — pinned by a test so the day PHP changes it, the
  suite says so. Availability is `defined('PASSWORD_ARGON2ID')`, checked *before* use because an
  unknown algorithm raises a bare `ValueError` outside ADR-0004's exception family.
  Unlike `Escaper` and `Sanitizer` this is an **instance**, because it carries a policy and a
  collaborator, and security configuration that can change mid-request is the wrong shape. The
  fallback is decided **once at construction**: `bcryptFallback: false` refuses to construct at all
  (so a misconfigured deployment fails while being wired, not at the first user registration), and
  the default `true` logs a single WARNING rather than one per hash. `algorithm()` exposes the
  outcome as a **value**, so a deployment without a PSR-3 logger can still detect the degradation.
  `verify()` works across algorithms and `needsRehash()` flags both a weaker algorithm and weaker
  *parameters*, which is what makes upgrade-on-login work.
- `deptrac.yaml` gains a `Psr` layer, granted to `Security` only for now. `psr/log` is a *required*
  interface-only dependency (RFC-0001 R-3) rather than an optional one, and further groups will
  need it as `Errors` (PSR-3) and `Container` (PSR-11) arrive, so it is granted per group as each
  does.
- `D4np\Utils\Security\Sanitizer` (**ADR-0021**) — `richText()` and `sqlLikePattern()`. **This
  completes spec §7's T-02 suite**, whose LIKE-wildcard leg roadmap item 4.4 deferred here.
  `sqlLikePattern()` neutralises the `%` and `_` that survive parameter binding intact — binding
  stops a `LIKE` value injecting SQL but does nothing about wildcards, so a search box forwarding
  `%` turns an indexed lookup into a scan. The escape character is **`!`, not `\`**: a backslash is
  special inside SQL string literals on several drivers, and `ESCAPE '\'` is a **parse error** on
  SQLite. `QueryBuilder::whereLike()` is added to emit the `ESCAPE` clause, because **without one
  an escaped pattern silently matches nothing on SQLite while working by accident on
  MySQL/PostgreSQL** — a wrong answer that only appears on one driver.
  `richText()` delegates to `symfony/html-sanitizer` (RFC-0001: *"no hand-rolled tag stripper"*), a
  new **optional** dependency declared in `suggest`. When it is absent `richText()` **throws**
  rather than returning the input unsanitized, and no Symfony type appears in its signature so
  "optional" holds at the API boundary. The curated profile limits link schemes to
  `https`/`http`/`mailto` (this is what refuses `javascript:` and `data:`), refuses relative links
  and media, and forces `rel="noopener noreferrer"`.
- `deptrac.yaml` gains an `HtmlSanitizer` layer that **only `Security` may reach** — the library's
  first third-party production dependency took the layering gate from `Uncovered: 0` to `6`, and
  declaring it keeps a future import from another group a build failure rather than a silent new
  coupling to an optional package.
- `D4np\Utils\Security\Escaper` (**ADR-0019**) — opens Milestone 5. RFC-0001's third security
  mechanism: context-aware output escaping. Four methods — `html()`, `attr()`, `js()`, `url()` —
  and deliberately **no general-purpose `escape()`**, because a method that did not name its
  context is the one that would get used in the wrong one. Each documents what it is *not* safe
  for, and a test asserts that separation rather than leaving it as advice.
  **`attr()` assumes the attribute is unquoted**, escaping every non-alphanumeric ASCII character
  as `&#xHH;`: an escaper cannot see its own call site, and the two possible assumptions are
  asymmetric — assuming quoted is wrong toward an XSS hole, assuming unquoted is wrong toward
  verbose output. Valid multibyte passes through, since no non-ASCII byte can terminate an
  attribute and escaping bytes individually would only produce mojibake.
  `js()` escapes on an allowlist, including `/` (inside `<script>` the HTML parser ends the element
  at `</script>` regardless of JavaScript string state) and U+2028/U+2029 (JavaScript line
  terminators before ES2019); output is pure ASCII with surrogate pairs above U+FFFF.
  **Invalid UTF-8 becomes U+FFFD in all four.** Without `ENT_SUBSTITUTE`, `htmlspecialchars()`
  returns an **empty string** for input containing one malformed byte — total silent data loss —
  so `attr()` and `js()` reproduce the same substitution via PCRE rather than `mbstring`, which is
  not a declared extension (NFR-08) and substitutes `?` rather than U+FFFD.
  `url()` covers one URL *component*; it is **not** the defence for a whole-URL sink such as
  `href="…"`, which needs scheme allowlisting — named as a gap rather than half-built.
- phpbench benchmark for NFR-03 (**ADR-0018**) — closes Milestone 4. `QueryBuilderBench` measures
  a 5-condition `SELECT`'s build time; `QueryBuilderTest::testBuildingNeverRunsAQuery()` asserts
  the "0 queries executed" half directly via the same `QueryLog` fixture item 4.4 built (timing
  cannot prove an absence, so this is a real assertion, not a benchmark, and it **is** CI-enforced).
  Measured build time: **~23µs against the ≤10µs budget**, attributed to ~1µs per identifier
  quoted (allowlist + driver-quote, ADR-0015) across the query's 12 identifiers, plus per-call
  cloning from the same ADR's immutability guarantee. Same shape as item 3.5's NFR-01 finding,
  handled the same way per ADR-0011's precedent: recorded honestly, shipped non-blocking (no
  reference-machine baseline exists yet — that's item 7.1's job), and filed as a separate follow-up
  (**roadmap item 4.6**) rather than fixed under a benchmark item's own route.
- Spec §7's **T-02 injection suite** (**ADR-0017**) — 29 fuzzed payloads × 6 value-accepting paths,
  asserting via a real **query log** (`PDO::ATTR_STATEMENT_CLASS`) that a payload appears in the
  bound-parameter array and **never** in the statement text. This is stronger than the round-trip
  assertions items 4.1–4.3 carried, and measurably so: against a planted *correctly-escaping*
  interpolation, the round-trip assertions passed 28 of 29 times while the query-log assertions
  failed 28 of 29. The PDO boundary is a sufficient proof point *because* ADR-0014 pins real
  prepares — with no client-side interpolation, placeholder-only text there is placeholder-only
  text on the wire. `--group T-02` now runs 321 tests and `--group T-04` 17, making spec §7's named
  suites runnable units.
  **T-02 is not complete:** its LIKE-wildcard leg needs `Sanitizer::sqlLikePattern()` (FR-10,
  roadmap item 5.2, not yet built). A test asserts the current behaviour and names the gap rather
  than leaving silence — a `LIKE` value binds and cannot inject SQL, but a user-supplied `%` still
  behaves as a wildcard.
- `D4np\Utils\Database\Transaction` (**ADR-0016**) — closure-scoped transactions: commit on return,
  roll back and rethrow on any **`Throwable`** (a `TypeError` leaves the same half-written state as
  an exception). Nested calls use savepoints, which is not an optimisation but the only mechanism
  available: a second `beginTransaction()` on an open connection **throws** rather than nesting or
  no-opping. A handled inner failure therefore undoes only the inner work, leaving the enclosing
  transaction intact. If the rollback *itself* fails its error is swallowed so the closure's
  exception — the actionable one — still reaches the caller; PHP has no suppressed-exception
  mechanism, so the choice is strictly between losing the cause and losing the cleanup failure.
  Savepoint names come from a process-wide monotonic counter and are never caller-influenced.
  Documented caveat no wrapper can fix: on MySQL, DDL causes an implicit commit mid-closure.
- `D4np\Utils\Database\QueryBuilder`, `Sort` and `Operator` (**ADR-0015**) — RFC-0001's second
  security mechanism: prepared statements bind *values*, never table or column names, so an
  identifier has nothing to hide behind and the allowlist is the whole defence. Identifiers are
  **refused** (`DatabaseException`), never sanitised, then driver-quoted (backticks on MySQL,
  brackets on SQL Server, double quotes elsewhere). Values always bind. `LIMIT`/`OFFSET` are `int`
  by signature and refused when negative rather than clamped.
  **Security fix found while building it:** spec FR-07 writes the allowlist as
  `^[A-Za-z_][A-Za-z0-9_]*$`, and transcribed literally into PHP that is a **bypass** — PCRE's `$`
  also matches before a trailing newline, so `"id\n"` passed and rendered as
  `SELECT "id\n" FROM "users"`. The pattern is anchored with `\z` instead, implementing FR-07's
  intent rather than copying its notation; ADR-0015 records it so nobody restores `$` for spec
  fidelity. `Operator` is likewise an extension of FR-07 rather than a silent addition: FR-07 makes
  the `ORDER BY` direction an enum because it is concatenated into the SQL text and cannot be
  bound, and a comparison operator is concatenated for exactly the same reason.
- `D4np\Utils\Database\DatabaseConnection` (**ADR-0014**) — opens Milestone 4. Wraps a
  **consumer-owned** `PDO` (RFC-0001: the library opens no connections) and pins spec FR-06's four
  safe defaults: `ERRMODE_EXCEPTION`, real prepares (`EMULATE_PREPARES=false`), `FETCH_ASSOC`, and
  `SET NAMES utf8mb4` on MySQL. A connection that will not take a pinned default is **refused**
  with a `DatabaseException` rather than handed back weakened — `PDO::setAttribute()` signals
  refusal by *returning `false`*, not by throwing, so the obvious implementation would let a
  security-relevant default fail silently. The ambiguous `false` is disambiguated by reading the
  attribute back: a driver with no emulation concept at all (SQLite) is fine; one that is still
  emulating is refused. The order the defaults are applied in is load-bearing and documented —
  `SET NAMES` is only safe *because* real prepares are already on, leaving no client-side escaping
  to fool. `select()`, `selectOne()` (returns `null`, not PDO's `false`) and `execute()` always
  bind values; identifiers cannot be bound and belong to `QueryBuilder` (item 4.2). Failures wrap
  `PDOException` per ADR-0004 and deliberately **omit the SQL** from the message, since a failing
  statement's text is the likeliest place for data that should not reach a log.
- `D4np\Utils\Dto\HydrationCompiler` (**ADR-0013**) closes NFR-01's performance gap: hydrating a
  10-scalar-property DTO went from **15.40× to 2.74×** manual constructor assignment
  (14.155 µs → 2.511 µs), meeting both halves of the budget for the first time. Four approaches
  were measured before any code was written, and the budget proved **unreachable** by tuning the
  interpreted loop (best case 4.80×) — so the hydrator now generates a per-class closure, but only
  for the all-scalar shape NFR-01 actually specifies (non-variadic builtin `int`/`float`/`string`/
  `bool`, no defaults). **Nested DTOs, `Collection`, enums, unions, `array`, variadics and
  defaulted parameters are not compiled** and run the existing interpreter unchanged, at roughly
  their previous cost — so this makes the specified shape fast, not every DTO. `HydrationParityTest`
  runs every case down both paths and compares them against each other, and `new
  HydrationCompiler(false)` turns generation off entirely for anyone who would rather no `eval`
  ran in their process. Full before/after with the environment, the rejected alternatives, and the
  ≈5.5-hydrations-per-process break-even for `eval`'s uncacheable compile cost is recorded in
  [`docs/benchmarks/2026/08/nfr01-hydration-compiled-closure.md`](docs/benchmarks/2026/08/nfr01-hydration-compiled-closure.md).
  NFR-04's 10 000-hydration benchmark improved as a side effect (149.7 ms → 37.8 ms).
- `deptrac.yaml` (**ADR-0012**) turns on the layering gate RFC-0001 named but nothing enforced:
  *groups depend downward on Support only; no cross-group imports*. One layer per component group
  plus `Support` and `Version`, collected by **directory** (bound to the source tree RFC-0001 §A-9
  fixes, rather than a second class-name-regex vocabulary that could drift from it), analysed over
  `src/main` only — tests and benchmarks legitimately cross groups and are out of scope. The empty
  `Support: ~` ruleset is the load-bearing half: it makes the `Support → Dto` inversion that
  ADR-0006 and ADR-0010 each designed around a build failure rather than a review opinion.
  Verified failing on a planted `Support → Dto` import and on a planted peer `Http → Dto` import,
  and verified *passing* on an allowed `Http → Support` import, so the gate is known to
  discriminate rather than merely reject. The CI `layering` job (self-skipped since item 1.9) is
  now active. `deptrac/deptrac` is held at `^4.4` by the PHP 8.1 platform pin — 4.7 requires 8.2.
- `composer.json`'s `config.audit.abandoned` set to `"report"` (was implicitly `"fail"`):
  phpbench pulls in `doctrine/annotations` as its own transitive dependency, flagged abandoned
  on Packagist. Not a security vulnerability and not a package this library depends on directly,
  so `composer audit` now surfaces it in output rather than failing CI on a maintenance-status
  flag of a dependency-of-a-dependency we cannot swap.

### Changed

- **Milestone 1 (`v0.1.0`) complete** — build system, test harness, formatter/linter,
  version constant, CI matrix, and the benchmark job are all in place and green.
- CI `benchmark` job hardened: it now self-enables on the presence of a phpbench config (the
  same step-level guard as the `deptrac` and `Infection` jobs) instead of failing until the
  harness lands, pins its interpreter to PHP 8.3 per spec NFR-06 rather than through a
  matrix expression the job never had, and runs without coverage instrumentation.

### Deprecated

### Removed

### Fixed

- `tools/consistency_lint.py`'s `version_file` path was doubled
  (`src/main/php/d4np/utils/src/main/php/d4np/utils/Version.php`), which would have silently
  disarmed `version-lockstep` — the check falls back to the README badge when the configured
  path does not exist, so the bug was invisible until this PR created `Version.php`. Fixed at
  the source (the manifest's `toolchain.version_file`) and re-rendered.

### Security

- Every GitHub Actions `uses:` reference in `.github/workflows/` is now pinned to an immutable
  commit SHA with a truthful version comment, per **ADR-0003**. Previously the same action was
  pinned two ways in one file — SHA in the generated steps, floating tag (`@v7`, `@v2`) in the
  quality jobs — so a re-pointed upstream tag could have changed what CI executes with no diff
  here. Dependabot (already configured for the `github-actions` ecosystem) keeps the pins and
  their comments current.
- `tools/action_pin_lint.py` enforces ADR-0003 mechanically in CI: `pin-shape` offline, and
  `pin-label-truth` resolving every version comment against its upstream repository — the half
  no purely local check can perform, since a comment that lies uniformly leaves nothing for a
  cross-file comparison to disagree with.

---

## Released versions

_No releases yet._
