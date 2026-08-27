# Changelog

All notable changes to `egl-util-php` are documented here, following
[Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning 2.0.0](https://semver.org/).

Every PR that introduces a user-visible change adds a line to `[Unreleased]` in the same
PR. A release PR moves the `[Unreleased]` entries into a new per-version file under
[`docs/changelog/v<MAJOR>/`](docs/changelog/) and adds an index row below.

**There are two release documents, and they are not copies.** This file and its archive are the
exhaustive record of *what changed*. The consumer-facing narrative — *should I upgrade, what should
I know first* — is [`docs/releases/`](docs/releases/), which is also the file published verbatim as
the GitHub Release body. Editing "the release notes" almost always means that one.

## [Unreleased]

### Changed

- **The bridge packages will not be published, and the documentation now says so everywhere** —
  issue #120, closed *as not planned* on 2026-08-27. Publishing a bridge means pushing to a
  generated split repository, which needs a credential in this repository's secrets
  (`GITHUB_TOKEN` structurally cannot write outside its own repository — ADR-0033's consequence,
  not an implementation detail), and the maintainer has decided not to hold one.
  **No behaviour changes and no code was removed.** `egl/utils-psr7-bridge` and
  `egl/utils-psr18-bridge` stay specified, contract-tested against two PSR-17 vendors on every pull
  request, and usable by anything consuming this repository directly. What changes is that nobody
  can `composer require` them, and the docs stop implying that is about to change: the packages'
  READMEs, their changelogs, and `docs/workflow/release.md`'s bridge section.
  **The `## [0.1.0]` headings added in #183 to anchor a tag are folded back to `[Unreleased]`.**
  Under Keep a Changelog a versioned heading means *released*, and nothing was — a heading naming a
  version that exists nowhere is the kind of claim this repository fixes rather than keeps. The
  release-mode evidence recorded under them is kept, because it is evidence and not an outcome: the
  pipeline is proven up to the push, and `bridge-release.yml` keeps its zero runs.
  The procedure in `release.md` is **kept rather than deleted** — it is correct, was verified up to
  the push, and the decision is reversible: a deploy key scoped to a single split repository would
  need one workflow change rather than a rebuild.
  **Recorded in the same pass, because leaving it implicit was the same defect:** no release signing
  key is being registered either (issue #115, still open on that one criterion). `verify-tag` will
  therefore fail on every tag, so releases take step 0's documented deliberate path
  (`EGL_UNSIGNED_TAG_REASON`) and **`tools/post_publish_gate.py` becomes load-bearing** rather than a
  formality — it is the only remaining check that notices a tag which never became a release, which
  is exactly what happened to `v1.1.0`.

### Added

- **`Hmac` accepts a `SecretKeyRing` — key rotation for signed URLs and webhook signatures, and a
  `v2.` format whose key id is authenticated by the MAC itself** — issue #179, **ADR-0085**, spec
  **r29** (FR-48b). The deferred half of #114's finding: `Hmac` had the identical
  no-key-identifier gap `Crypto` did and none of the fix, so rotating a signing key invalidated
  every outstanding signed URL and webhook signature at the moment of the deploy — the two
  artifacts that outlive a deploy by design, since a webhook signature is checked by someone
  else's server on their schedule.
  `new Hmac(SecretKeyRing::of($current, $previous))` signs as
  `v2.` = `base64url(keyId ‖ expiry ‖ mac)` and verifies anything the ring holds. ADR-0083's
  convention is reused rather than re-decided: the same derived key id, the id first at a fixed
  offset, an unknown id refused rather than retried.
  **One part is genuinely different, and it is the security property.** `Crypto` binds its key id
  with GCM's AAD; **HMAC has no AAD**, so the id goes *under* the MAC —
  `mac = hmac(keyId ‖ expiry ‖ message)`. An id that were merely a token prefix would let a
  substituted id naming another key the ring genuinely holds still verify, and would let a `v2.`
  body be replayed as `v1.` — stripping the four-byte id leaves a well-formed `v1.` payload, so
  the length check cannot refuse it and the MAC has to.
  **`v1.` stays byte-identical** for a bare `SecretKey`, with its conformance vector as the anchor,
  and a ring verifies `v1.` tokens too — so adopting one is a migration, not a cutover. Additive
  under ADR-0059.
  Two findings are recorded rather than glossed. A planted defect exposed a **pre-existing gap in
  the `v1.` suite**: the overlong-payload tests asserted only the exception class, so they could
  not tell an explicit length check from `hash_equals()` refusing incidentally on a length mismatch
  — the property `testACorrectMacPrefixIsRefused`'s own docblock already claimed. Closed by
  asserting the message. And because the key id is signed, **fail-closed is policy and diagnosis
  while the MAC is the security boundary**: a second plant removing the fail-closed refusal was
  caught on the message, not on acceptance.
  Measured, not assumed (ADR-0085 has the table): per message `v2.` and `v1.` are
  indistinguishable, but a `v1.` token from the oldest of three ring keys costs ~2× a single check,
  because a `v1.` token names no key and the ring must walk them. **`v2.` is what keeps
  verification O(1) during a rotation.** Derived MAC keys are computed once at construction, one
  per ring key, asserted as a mechanism. No NFR budget invented — ADR-0040 reserves spec numbers.

- **The release SBOM is now attested** — issue #115 criterion 3, **ADR-0084**. `draft-release`
  produces a signed SLSA provenance attestation for `bom.xml` before the draft exists, verifiable
  with `gh attestation verify bom.xml --repo danielPoloWork/egl-util-php`. An unattested SBOM on a
  Release is a supply-chain claim anyone with write access can replace with no way for a consumer to
  tell — which is worse than shipping none, because the file's presence invites trust it has not
  earned.
  **Worth recording why this changed rather than only that it did.** Criterion 3 had been declined,
  on the reasoning that attesting needs an attached asset and attaching one would reverse
  `release.md`'s "no build artifacts are attached" decision. **ADR-0076 (issue #98) reversed that
  decision itself**, for its own reasons, and an SBOM has been attached ever since — so the
  objection was describing a state of affairs that no longer existed.
  **It attests the SBOM, not the source.** The zip Packagist builds from the tag is not produced by
  this workflow and cannot be attested, so the original objection's first half still stands and the
  assurance for the source remains the **signed tag** — issue #115's criterion 1, still open and the
  maintainer's own action. Issue #115 therefore stays open; closing it here would be the
  checkbox-satisfaction it was filed to prevent. Fail-closed placement: an SBOM whose provenance
  cannot be signed produces no draft at all. No production code changes.

- **`Security\SecretKeyRing` — key rotation for `Crypto` tokens, and a `v2.` format that
  authenticates its key id** — issue #114, **ADR-0083**, spec **r28** (FR-40b). The release board's
  one *major* security finding: `v1.` versions the *format* and carries no key identifier, so
  rotating a key after a suspected compromise invalidated every outstanding token, or pushed the
  consumer into hand-rolling a try-each-key loop. `SecretKeyRing::of($current, ...$previous)` is
  the rotation window; `new Crypto($ring)` encrypts under the current key as
  `v2.` = `base64url(keyId ‖ nonce ‖ ciphertext ‖ tag)`.
  **The key id is GCM's AAD, not merely a prefix** — the decision this change turns on. Probed on
  8.3.1 before committing: the same ciphertext and tag return `false` under a different AAD *or an
  empty one*, so the tag covers the id and two attacks are refused — substituting the id to name
  another key the ring genuinely holds (the lookup succeeds, so only the tag can object), and
  stripping the id to replay the body as a `v1.` token. Both asserted with a live control, since a
  passing tamper test proves nothing if the untampered token never worked.
  Key ids are **HKDF-derived, not caller-assigned** (`hash_hkdf('sha256', bytes, 4,
  'egl/utils:keyid:v1')`, ADR-0065's domain-separation pattern), so they cannot be inverted to key
  material and need no registry; a ring **refuses two keys whose ids collide**, which in practice
  catches the same key listed twice. An **unknown key id fails closed**, never retried against the
  other keys — that would make retiring a key inoperative.
  **A bare `SecretKey` still produces byte-identical `v1.` tokens**, which is what keeps this
  additive under ADR-0059, and a ring also reads `v1.` tokens so adopting one is a migration rather
  than a cutover. **Measured** (indicative, not NFR-06's reference machine): no cost on NFR-13's
  budgeted path — 14.79 µs bare-key against a 60 µs budget, 14.18/14.25 µs for rings of one and
  three, one number inside noise because the id is derived once at construction. The first draft
  called the HKDF per message; that was found and fixed before measuring.

- **`consistency_lint.py` pins the `@internal` inventory, so widening ADR-0059's carve-out is
  visible** — issue #111, **ADR-0082**. Removing an `@internal` symbol already trips
  `bc_gate.py`; adding `@internal` to an already-frozen public symbol tripped nothing at all,
  silently moving it outside the 1.x contract. A new tenth check asserts the `@internal` symbols
  in `src/main` equal exactly a pinned set — five today, not the two ADR-0059's original table
  named: `Base64Url` and `Uint64` (whole classes) and `Page::__construct()` shipped `@internal`
  from day one in the additive MINOR that followed, legitimately, and nothing had recorded that
  growth. Widening or narrowing the set is now a one-line, reviewed edit to the linter. Matched
  strictly to the tag's own line (`@internal` as the first word after `*`), not a substring search
  — `Base64Url.php`'s own docblock mentions the word twice, once in backtick-quoted prose and once
  as the real tag, and only the second counts. Proved in both directions by hand against the real
  tree (a symbol planted, a symbol removed) before `tools/tests/verify_internal_inventory.py`
  shipped the repeatable seven-case proof, run on every PR. No production code changes.

- **`tools/post_publish_gate.py` — verify a release actually reached the world, plus a nightly
  check** — issue #105, **ADR-0081**. `release_gate.py` verifies a tag before drafting a Release;
  nothing verified what happens *after* a human clicks Publish. The gap was not hypothetical: while
  this issue was being worked, `v1.1.0` was found tagged and pushed but never actually released —
  the tag was unsigned, `verify-tag` correctly refused it, and the tag sat for three days with no
  GitHub Release and nothing on Packagist while `ROADMAP.md` read as though it had shipped, because
  nothing checked. The new gate asks four questions of a tag: is it signed and verified, does a
  non-draft GitHub Release exist for it, does the Release body match the canonical rendering of the
  notes (a **prefix** match — GitHub appends its own auto-generated notes after the given body), and
  does the version resolve on Packagist. Exit 0/1/2 distinguish "verified fine" from "a real
  problem" from "could not even check" — the last never allowed to look like the first. Documented
  as release.md's closing step, and **also run nightly** against the latest published Release,
  because a manual step is exactly the mechanism that just failed — a release that slips through it
  is now caught within a day instead of by accident. The broken, unpublished `v1.1.0` tag was
  deleted (documented agent authority: an unpublished tag whose release run visibly failed);
  re-tagging it signed is the maintainer's next action. No production code changes.

- **`Hash::strict()` — the fail-closed password-hashing construction, as a named entry point** —
  issue #102, **ADR-0079**, spec **r27**. Equivalent to `new Hash(bcryptFallback: false)`: Argon2id,
  or refuse to construct. The behaviour already existed as a boolean argument a caller had to know
  about; this makes the safe posture discoverable in the class's own API listing. **It does not
  change `new Hash()`**, which stays permissive because the 1.0 surface is frozen (ADR-0059) — a
  logger-less construction still degrades to bcrypt quietly on an Argon2-less build, which is now
  named as a hazard in the docblock and recorded as a 2.0 candidate rather than papered over.

- **A wire-capture leg for T-10 — mail asserted against a real MTA** — issue #101, **ADR-0078**,
  spec **r26**. T-10's three existing legs all stopped at the `MailApi` seam, which is the right
  place for the array-header mechanism (ADR-0027) and the wrong place for anything about SMTP. Three
  of ADR-0056's load-bearing claims had been settled by hand-probing a transport and writing the
  result into prose. A new `mail-wire` CI job runs msmtp into a Mailpit sink and `WireCaptureTest`
  asserts on the captured messages: a `bcc` recipient really is delivered as an envelope recipient,
  RFC 2047 subjects decode back to what was written across 2-, 3- and 4-byte **and folded** widths
  (the folded path had never been sent through a real `mail()` — the existing test uses a
  six-character subject that never folds), the envelope sender arrives, and the string header block
  ADR-0056 rejected really does deliver an injected `Bcc` — a counterfactual no test inside this
  library's API could express, and the evidence that the array form is load-bearing.
  **Two findings shaped the suite.** Mailpit *rewrites the message it stores*, prepending a synthetic
  `Bcc:` header naming any envelope recipient the headers omit — so the obvious assertion ("no `Bcc:`
  header survived") fails against a pipeline that is working perfectly, and the correct test is its
  inverse. And **`--fail-on-skipped` does not see a suite skipped from `setUpBeforeClass()`**
  (measured: exit 0, and so does `--fail-on-empty-test-suite`), so the guard ADR-0071 relies on would
  have been inert here and a job whose `EGL_TEST_MAILPIT_URL` never arrived would have reported green
  having sent no mail at all; the skip is raised per test from `setUp()` instead. No production code
  changes; unconfigured runs skip and the default `vendor/bin/phpunit` is unchanged.

- **A randomized-order CI cell to surface hidden inter-test coupling** — issue #100, **ADR-0077**,
  spec **r25**. `ci.yml`'s `build` job gains a fourth matrix cell (`php-8.3 / random-order`)
  running the full suite with `vendor/bin/phpunit --order-by=random`, alongside the three
  unchanged declaration-order cells. PHPUnit 10 prints `Random Seed: <N>` in its own output header
  with no extra flag, so the job's log already carries what's needed to reproduce a specific run
  exactly (`--random-order-seed=<N>`). `docs/development/local-build.md` states the rule the
  issue's second criterion asked for: a failure confined to this cell is **coupling, not flake**,
  and the fix is to find the shared state, not to re-run. First local run (3,199 tests, seed
  1787749415): all green, 9 skipped, 0 failed — no coupling found yet. No production code changes.

- **A supply-chain hygiene batch: nightly `composer audit`, a require-checker gate, and an SBOM on
  every release** — issue #98, **ADR-0076**, spec **r24**. Three additive changes from the
  2026-08-09 Release Review Board. `composer audit` now also runs **nightly** (`nightly.yml`), not
  only on a push or PR diff, so a CVE published against an already-vendored dependency during a
  quiet week is caught inside a day. **ComposerRequireChecker** joins `ci.yml`'s `hygiene` job,
  installed outside this package's own dependency graph (its `php >=8.2` floor exceeds this
  library's `>=8.1`, the same throwaway-install pattern ADR-0031/ADR-0040 use for Roave/Psalm/
  Infection) — it proves every symbol the source actually reaches is a declared `require`, the
  `ext-fileinfo` example the issue names included. **Its first real run found two undeclared hard
  dependencies**: `ext-filter` and `ext-session` had no runtime guard anywhere in the source, so
  both now join `require` (spec **NFR-08**). `ext-openssl` and `ext-intl` were already
  guarded — `Security\Crypto` already refuses construction without `ext-openssl`, `Str::slug()`
  already falls back without `ext-intl` — so both join `ext-iconv` and `symfony/html-sanitizer` in
  `suggest` instead, with a new `composer-require-checker.json` whitelisting exactly the symbols
  each guard covers. `release.yml`'s `draft-release` job now
  generates a **CycloneDX SBOM** (production dependencies only) from the tagged tree and attaches
  it to the draft GitHub Release, which also makes `docs/workflow/release.md`'s boundary-table row
  "Build & attach artifacts — CI" literally true. The issue's fourth item — a deliberate
  `composer.lock` refresh cadence — was already met by the existing weekly, grouped
  `dependabot.yml` composer config and needed no change. No production code changes.

- **`egl/utils-psr18-bridge` — a PSR-18 HTTP client over `HttpClient`** (issue #93, **ADR-0075**,
  spec **03**). Ecosystem middleware and SDKs consume `Psr\Http\Client\ClientInterface`; until now
  a consumer wanting to hand them this library's client wrote the adapter themselves, and the
  adapter is not trivial because PSR-18 mandates an exception taxonomy the core does not have.
  **The core's dependency surface is unchanged** — `psr/http-client` lives in the new package only,
  and `BridgePackageBoundaryTest` (now data-driven over both packages) asserts it never reaches the
  core's manifest.
  **It does not depend on `egl/utils-psr7-bridge`.** That package converts the *server* vocabulary
  (`Request`/`Response`); this one wraps the *client* (`HttpClient`/`HttpResponse`). Different
  types, no shared code, independently installable.
  PSR-18's split between a malformed request and a network failure — the one that decides whether a
  retry is worth attempting — is made **structurally**: every request-shaped check runs before the
  send, so anything thrown afterwards is the network's, with no message matching. Both exceptions
  extend the core's `HttpException` *and* implement PSR-18's interfaces, so a consumer's
  `catch (UtilsThrowable)` and a PSR-18 retry middleware both work.
  **One publication pipeline now serves every bridge**: the tag names its package
  (`utils-<name>-bridge-vX.Y.Z`), `bridge_release_gate.py` derives the directory from it, and that
  tool gained a **15-case self-test it previously had none of** — including proof that a tag cannot
  be satisfied by another package's version, which is the mistake no published release can undo.

- **A nightly advisory full-tree mutation run, and every namespace's score with it** — issue #108,
  **ADR-0074**, spec **r23**. `infection.json5` gates three namespaces at NFR-07's 70% floor;
  #108 asked whether `Persistence` (data-mapping, injection-adjacent) and `Http` should join. The
  answer was not available — nobody knew what `Persistence` scored — and ADR-0040 had already ruled
  that the spec owns that number, so this measures rather than legislates.
  One full-tree run now feeds `tools/mutation_scope_report.py`, which splits it per namespace by
  grouping Infection's own JSON log; a matrix leg per namespace would have re-run the suite once per
  leg for information the first run already contained. The reporter **re-checks its own arithmetic
  against Infection's `stats.msi`** and prints no table when the two disagree, because a plausible
  wrong number is exactly what a spec floor would then be set from.
  **What it found:** overall **81.29%**; `Persistence` **75.84%** and `Http` **88.89%** would both
  clear a 70% floor today (the five namespaces together score 81.09%), while a **full-tree gate
  would fail immediately** — `Mail` is the one namespace under the floor at **68.18%**. The gated
  scope is deliberately unchanged; widening it is a spec amendment and the maintainer's decision.
  No production code changes.

- **A nightly taint-analysis job (Psalm `--taint-analysis`)** — issue #103, **ADR-0073**. PHPStan
  runs at max level and carries real security weight here (`SqlStatement::literal()` takes a
  `literal-string`, which *proves* no runtime value is in that SQL text), but PHPStan does no taint
  tracking: it can say "this string is a literal", not "this string came from `$_GET` and reached
  `PDO::prepare()` eleven calls later." A scheduled job now asks the second question across
  Database, Http and Mail, with Psalm installed outside the 8.1 dependency graph the way the BC
  checker and Infection already are (ADR-0031/ADR-0040).
  **`SqlStatement::composed()` gained a `@psalm-taint-sink sql $sql` annotation, and it is the part
  that made the job worth running.** Out of the box Psalm did *not* see a `$_GET` value concatenated
  into SQL and passed through `composed()` — the taint is lost at that value object's boundary, and
  a planted flow came back clean. `composed()` is the one documented door where `literal-string` is
  given up on purpose (ADR-0041), and its docblock asks the caller to assert that nothing from
  outside the program is in the text; the annotation turns that assertion into something a machine
  refuses. PHPStan ignores the tag, so it costs nothing at max level. No production behaviour
  changes.
  The two baselined findings are both `ExceptionHandler`'s debug branch — real flows, correctly
  traced, gated by a `$debug` boolean that defaults to false (ADR-0029) and that taint analysis
  cannot model as a sanitiser. Their triage, and what would invalidate it, is in
  [`docs/security/taint-analysis.md`](docs/security/taint-analysis.md).

- **A control-subject breach in the benchmark gate now retries once, automatically** — issue #99,
  **ADR-0057** annotated. `bench_regression_gate.py` already distinguished an invalid run (exit
  `2`, a control subject moved past threshold so the whole A/B is untrustworthy) from a real
  regression (exit `1`); CI treated both as a plain failure and left the documented remedy — a
  re-run — a manual click. The benchmark job now consumes the distinction directly: on exit `2` it
  re-measures HEAD **and** the base commit together, not just one half, because ADR-0030's
  same-runner argument depends on the two halves being adjacent in wall-clock time — refreshing
  only the base would trade one invalid comparison for a different one, not a valid one — then
  re-runs the identical gate call exactly once. A second invalid run in a row fails loudly rather
  than retrying again. **Exit `1` is never retried, on either attempt** — collapsing the two codes
  back together would undo the reason ADR-0057 gave them different numbers.

- **The database boundary is now a seam: `D4np\Utils\Database\Connection`** — issue #113,
  **ADR-0072**. `DatabaseConnection` implements it, and `Repository`, `TableGateway`,
  `QueryBuilder`, `MutationBuilder` and `Transaction` all accept the interface, so a consumer can
  unit-test their own repository without running a database. Every other I/O boundary in the
  library already had a seam (`Transport`, `SessionApi`, `MailApi`, `RateLimitStore`); the one
  consumers write the most code against did not.
  **Additive in every direction.** No signature refuses anything it used to take,
  `DatabaseConnection` stays `final` with the same constructor and the same four pinned defaults
  (ADR-0014), and the per-PR BC report checks it against the frozen `v1.0.0` surface.
  Two decisions worth knowing about: `pdo()` **is** on the interface, because `Repository` builds a
  `Transaction` and a connection a repository cannot transact on would not fit the class the
  interface was extracted from — a read-oriented fake may simply throw from it, since nothing on a
  read or write path calls it. And `Transaction` is now generic over its connection, so an existing
  closure typed `function (DatabaseConnection $db)` keeps type-checking; without that the change
  would have broken consumers' static analysis while Roave, which cannot see docblocks, reported
  green.
  **What a fake does not inherit:** real prepares, `ERRMODE_EXCEPTION`, `utf8mb4`. Those are
  properties of `DatabaseConnection` pinning them on a PDO, not of the interface — which is why
  this library's own injection suites keep running against a real engine.
  **One narrow incompatibility, stated rather than buried:** `Repository::$connection` is
  `protected` and its declared type widened. No subclass can assign to it (`readonly`), and reading
  it to call any of the five methods is unaffected — but a subclass that *re-exposes* it, say
  `function connection(): DatabaseConnection`, must widen that return type. Everything else the BC
  report flags is Roave being literal: it compares against v1.0.0, where `DatabaseConnection`
  implemented nothing, so it cannot see that the supertype each parameter widened to is one the
  same release gives the class. ADR-0072 works through all eleven findings.

- **The BC checker now also runs report-only on every PR, against the frozen `v1.0.0` surface** —
  issue #112, **ADR-0031** annotated. The gate is unchanged: it still runs on release PRs only and
  still asks *"are these breaks allowed in this bump?"*. The new run asks a different question —
  *"is the frozen public surface still intact?"* — and ADR-0059 is why it is worth asking on every
  PR: the API is frozen at 1.0.0, so a 1.x break is no longer legal noise but signal, and finding
  it at the next release PR puts the discovery far from the change that caused it.
  The baseline is the **oldest tag of the current MAJOR line**, computed rather than written down,
  so it follows the project into a future `v2` without an edit. Findings land in the job summary
  and as a warning annotation; they never fail the build. What *does* fail the build is a report
  that could not be read — `bc_gate.py --report-only` exits 1 on an unreadable or unparseable
  report, because a permanently green report-only tick over an unanswered question is exactly the
  vacuous green this repository keeps having to go back and fix.

- **The `Database` and `Persistence` suites now run against MySQL and PostgreSQL, not only SQLite**
  — issue #110, **ADR-0071**. Every behavioural proof these two groups carry had run on one engine,
  which left three of the four arms of `Identifier::forDriver()` — a security control — never
  executed, `Transaction`'s three savepoint statements parsed by SQLite alone, and
  `Sanitizer::LIKE_ESCAPE`'s choice of `!` over `\` resting on documentation rather than on a run.
  One environment variable now points the suites at an engine (`EGL_TEST_DB_DSN`, unset =
  `sqlite::memory:`, so the default developer run is unchanged), and a new CI job re-runs T-02,
  T-13 and the gateway/repository/pagination suites against MySQL 8.4 and PostgreSQL 16 service
  containers on PHP 8.1 — the library's floor, because driver type coercion is the one behaviour
  here that moved across the 8.x line.
  **An unreachable engine fails the leg.** Four things make a silent green impossible: the service
  health check, a preflight that authenticates with the suite's own credentials, a harness that
  raises rather than skipping when a *configured* engine cannot be opened, and
  `--fail-on-skipped`.
  A new `DialectTest` pins what the engines do **differently**, so each difference is answered once
  instead of rediscovered: quoting per driver, the unknown-column divergence ADR-0044 had only
  reasoned about, the implicit backslash `LIKE` escape MySQL and PostgreSQL have and SQLite does
  not, savepoint nesting, driver type coercion, and MySQL's case-insensitive default collation.
  **The leg's first run found one thing nobody had written down, and consumers on PostgreSQL should
  know it: `PDO_PGSQL` silently truncates a bound parameter at its first NUL byte.** The `INSERT`
  succeeds, one row appears, and the tail is gone — libpq sends a bound parameter as a
  NUL-terminated C string, so the server (which would have rejected the byte) never sees past it.
  MySQL and SQLite store the whole value. Nothing in this library can fix that, so it is pinned by
  test and documented instead. Everything else the leg measured came back confirming the code:
  both engines return native `int` for integer columns and for `COUNT(*)` on PHP 8.1, so strict DTO
  hydration works on all three.

### Changed

- **`SECURITY.md` names response-time targets, not a guarantee** — issue #104, **ADR-0080**. A
  reporter previously had no way to distinguish "in triage" from "lost": the vulnerability-reporting
  sequence named no timeframe, a deliberate 2026-08-09 decision recorded in ADR-0060 to avoid
  overcommitting a solo-maintained project. #104, from the same review board, argued the silence has
  a real cost the earlier decision did not weigh. `SECURITY.md` now states **5 business days to
  acknowledge, 15 business days to a triage verdict** — sized to what this repository's own PR
  turnaround can sustain even on a bad week, and explicitly labeled **targets, not a guarantee**, so
  the phrase does the work ADR-0060 asked for rather than quietly reversing it. The escalation path
  for missed targets depends on nothing the maintainer has to maintain: bump the same private
  thread, and at 30 days of total silence the reporter is released to their own disclosure timeline.
  `docs/workflow/maintenance.md`'s now-stale "no SLA" sentence is corrected in the same PR to point
  at ADR-0080 rather than restate ADR-0060's original claim. No code changes.

- **The `HttpClient` redirect trade-off is now stated on the class, and pinned by tests** — issue
  #102, **ADR-0079**, spec **r27**. No behaviour change. #102 asked whether the http/https allowlist
  is re-applied per hop when `followRedirects` is on, since the hops belong to PHP's stream wrapper.
  Probed rather than reasoned about, with an origin emitting arbitrary `Location` headers: **PHP's
  wrapper never leaves http/https.** `ftp://` and `gopher://` are refused outright, while `file://`,
  `php://filter`, `data://` and a protocol-relative authority are each degraded to a *path on the
  same host*. So a per-hop scheme check would be **unreachable code**, and ADR-0022's precedent is
  that this project does not ship defences a probe proves inert. T-07 pins all six shapes instead,
  with a companion test proving those payloads *are* readable so their absence means something.
  **The real residual is cross-origin following** — bounded only by `maxRedirects`, mitigated by the
  off-by-default flag — and it is now written where someone enabling redirects will read it.

- **The CSV formula guard is documented where it can be seen** — issue #102, **ADR-0079**. No code
  change: `guardFormulas: false` remains ADR-0037's deliberate call, since the guard alters exported
  data and only the caller knows the file's destination. What changed is reach — the `Csv` docblock
  now names the attack concretely (`=WEBSERVICE(...)` in a user-supplied field becomes a request from
  the machine of whoever opens the export), and the README gains a worked example passing
  `guardFormulas: true`, in a repository whose README had no CSV example at all. The framing is the
  point: **the safe choice is the one you have to type.**

### Fixed

- **The bridge publication runbook named a repository variable that no longer exists** — issue
  #120. `docs/workflow/release.md`'s one-time prerequisites told the maintainer to set
  `BRIDGE_SPLIT_REPO`; since issue #93 / **ADR-0075** generalised the pipeline to serve every
  bridge, the workflow derives the name **per package**
  (`BRIDGE_SPLIT_REPO_UTILS_PSR7_BRIDGE`). Following the runbook would have set a variable nothing
  reads, and the failure would have arrived at the prerequisite step *after* every other gate
  passed — the point at which "not configured" is hardest to distinguish from "the release is bad".
  The step now carries both bridges' variable names in a table, and the section is written for a
  bridge rather than for the PSR-7 one.

  Recorded here rather than only in the packages' own changelogs because this is a defect in *this*
  repository's release documentation. **Issue #120 stays open**: its remaining criterion is the
  owner's one-time split-repository, token and Packagist steps, which no agent can perform.

- **`tools/tests/verify_bc_gate.py` was reporting three false failures on Windows**, and had been
  since it was written (found while extending it for issue #112). It reads the gate's stdout back
  and asserts on it, but the child process wrote in the console codepage while the parent decoded
  UTF-8, so the first em dash raised `UnicodeDecodeError` inside `subprocess`'s reader thread. The
  exit codes still arrived, so every `code == N` case passed and only the "…and says so" cases
  failed — for a reason that had nothing to do with the gate. Green on CI's UTF-8 Linux, which is
  how it survived. The child's `PYTHONIOENCODING` is now pinned.

- **An unsigned release tag can no longer be pushed by accident** — `tools/tag_guard.py` and
  `.githooks/pre-push` (issue #115, **ADR-0032** annotated). Enable once per clone with
  `git config core.hooksPath .githooks`.
  `v0.11.0`, `v1.0.0` and `v1.1.0` each went up annotated but unsigned; `release.yml`'s signing gate
  refused each one **after** the tag was public, which is the one moment a tag cannot be corrected in
  place. The guard moves that discovery to the second before the push, and refuses a lightweight tag
  too — a lightweight tag cannot carry a signature at all.
  **It does not make an unsigned release impossible, deliberately.** That choice has been made three
  times with the outcome known, and a guard that simply blocked it would be bypassed with
  `--no-verify` and teach nothing. It requires the choice to be *stated*:
  `EGL_UNSIGNED_TAG_REASON="why" git push origin v1.2.0` prints the reason and the consequences.
  A tag it cannot read exits **2** rather than 0, and an ordinary branch push produces **no output at
  all** — a guard that comments on every push gets switched off for being noisy.
  `tools/tests/verify_tag_guard.py` (15 cases) proves the refusals, the override, the blank-override
  rejection, and the silence. The signed path is the one branch not exercised, because no signing key
  exists on any machine that runs it — which is this issue's first criterion and the maintainer's.

- **`README.md`'s install section no longer describes a one-release world.** It said `^1.0` resolves
  v1.0.0, *"the only published release"*, and carried a warning box announcing that Milestone 14 was
  *"merged but unreleased"*. Both were true when written in #147 and were made false by v1.1.0 — and
  `README.md` is the page Packagist renders, so they were the first thing a consumer read.
  Rewritten to be **version-independent** rather than re-pinned to the newest tag: the constraint
  advice no longer names a resolving version, the dependency list says which package arrives when
  instead of counting them, and the box now explains that `master` runs ahead of the newest tag by
  design, pointing at `[Unreleased]` as the part you cannot install yet. A claim that has to be
  edited on every release is a claim that will be wrong between them.

- **`phpdoc.dist.xml` no longer ships in the Packagist dist**, and a gate now asserts the dist's
  contents rather than trusting the rule list (issue #119). `.gitattributes`' `export-ignore` rules
  cut the archive from 524 files to 121 at `v1.1.0` — and `phpdoc.dist.xml` shipped inside it
  anyway, added in a later PR with no rule of its own and unnoticed until the tag was published.
  **The reasoning that failed was written down as if it were sound**: `.gitattributes` argued a
  deny-list avoids the rot an allowlist suffers, when a deny-list **includes** a new top-level file
  by default and so rots the same way, silently. Neither list is self-maintaining. `tools/dist_gate.py`
  asserts what is actually in `git archive` — everything under `src/main/` plus `LICENSE`,
  `README.md`, `composer.json`, and nothing else — and refuses (exit 2) an archive it cannot read
  or one containing no source at all. It found the real leak on its first run, before any synthetic
  case existed; `tools/tests/verify_dist_gate.py` is the repeatable half.
  `v1.1.0`'s published dist is unchanged: one 1.5 KB config file does not justify moving a tag.


## Released versions

| Version | Date | Notes |
|---------|------|-------|
| [v1.1.0](docs/changelog/v1/v1.1.0.md) | 2026-08-21 | Milestone 14's five additive seams, and Milestone 13's documentation and release-hygiene close-out. |
| [v1.0.0](docs/changelog/v1/v1.0.0.md) | 2026-08-09 | The first release — every milestone M1–M12 — and the API freeze (ADR-0059). |
