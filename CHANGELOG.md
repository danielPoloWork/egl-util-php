# Changelog

All notable changes to `egl-util-php` are documented here, following
[Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning 2.0.0](https://semver.org/).

Every PR that introduces a user-visible change adds a line to `[Unreleased]` in the same
PR. A release PR moves the `[Unreleased]` entries into a new per-version file under
`docs/changelog/v<MAJOR>/v<X.Y.Z>.md` and adds an index row below.

## [Unreleased]

### Added

- **A per-diff coverage gate** — `tools/diff_coverage_gate.py`, wired into CI on every pull
  request (**ADR-0068**; closes issue #109). The existing gate enforces **total** line coverage
  against spec NFR-07's 90% floor and prints, on every run, what it cannot see: with the suite well
  above the floor, an untested addition rides inside the headroom without the total moving. This
  intersects the same Clover report with the lines a change actually touched.
  - **The floor is NFR-07's own 90%, not a new number.** A change that is itself 90% covered cannot
    drag a 90%-covered library below its floor. `--min` exists so a different figure stays the
    maintainer's decision rather than this tool's.
  - **Only coverable statements count**, so blank lines, comments, docblocks and closing braces are
    neither credit nor penalty — and `@codeCoverageIgnore` is the one escape for a line that
    provably cannot execute, with **zero uses in the tree** so `grep -rn codeCoverageIgnore src/`
    stays the whole review list.
  - **Absence is failure.** A missing report, an unparseable one, or a base ref git cannot resolve
    all exit 2. A change touching no coverable statement passes, saying so rather than dividing by
    zero in either direction.
  - **The proof that it can fail ships with it.** `tools/tests/verify_diff_coverage_gate.py` is the
    first executable check for any `tools/*.py` here — fifteen cases over throwaway git
    repositories, four of them the ones that matter, and CI runs it in the `consistency` job. Every
    previous tool on this project was verified by hand and the outcome written into an ADR.
- **`Security\RateLimiter` — a token bucket per key, behind a compare-and-swap store** (spec
  **r22 FR-50**, RFC-0003; roadmap items **14.6**/**14.7**; **ADR-0061** design + **ADR-0067**
  implementation; closes issue #91). Additive under ADR-0059. With it:
  `Security\{RateLimitPolicy, RateLimitDecision, RateLimitRecord, RateLimitStore,
  ArrayRateLimitStore, FileRateLimitStore}` and `Support\RateLimitStoreException`. Seven things
  worth knowing before wiring it:
  - **A rate limit exists at the scope its store is shared, and nowhere else.** Behind a load
    balancer a per-machine store means each node enforces its own limit — the effective limit is N×
    the configured one, and an attacker spreading requests across nodes is throttled by none of them.
    Multi-node enforcement needs a store every node shares; this library ships the algorithm and the
    seam, and deliberately no network client. Both shipped stores state their scope in the first
    sentence of their own docblock: `ArrayRateLimitStore` is **one process** (under PHP-FPM, one
    request), `FileRateLimitStore` is **one machine**.
  - **Key on the target identity, not the source address.** A limiter bounds attempt frequency
    through the keys you chose; keyed on IP alone it is defeated by address rotation.
  - **A store failure is never a decision.** `RateLimitStoreException` propagates — you choose
    whether this endpoint prefers lockout or exposure while the backend is down. **If you choose
    fail-open, do it loudly** (log at error, alert): a `catch` that returns "allowed" silently
    recreates protection that evaporates exactly when attacks are cheapest.
  - **A denial is a value, not an exception.** `RateLimitDecision` carries `allowed()`,
    `remaining()`, and `retryAfterSeconds()` — which rounds **up**, so a client is never told to come
    back before its token exists.
  - **Your keys never reach the store.** The limiter hashes namespace and key at its own boundary,
    so store-syntax injection, path traversal into the file store's directory, kilobyte-key storage
    inflation, and content-shaped timing are all gone by construction rather than per store.
  - **A skewed clock cannot mint tokens.** Elapsed time is clamped at zero, so a node running behind
    refills nothing: skew can under-grant, never over-grant.
  - **Sizing the file store:** each key costs **two inodes** (the state file plus `File::update()`'s
    sidecar lock), and nothing prunes expired files — a limiter keyed on user input wants a periodic
    sweep of its directory.
  **Deliberately absent:** no circuit breaker, no PSR-15 middleware, no automatic wiring into
  `HttpClient` or `Session`, no in-library Redis store.
- **`Support\RetryPolicy` and `Support\Retrier` — explicit retry with backoff**, plus the sleep
  seam they need: `Support\{Sleeper, SystemSleeper, FrozenSleeper}` (spec **r21 FR-49**, RFC-0003;
  roadmap item **14.5**; **ADR-0066**; closes issue #94). Additive under ADR-0059. Six things worth
  knowing before wiring it:
  - **Nothing retries on its own.** `Retrier` is opt-in for `HttpClient` and transaction callers; a
    library that silently retried would change your failure semantics without being asked, and a
    non-idempotent operation retried once is a duplicate write.
  - **Retry is transparent to your error handling.** When the attempts or the deadline run out, the
    *last* exception is rethrown unwrapped — so an existing `catch (HttpClientException)` keeps
    working. A non-retryable failure propagates immediately, with no delay spent. How much retrying
    happened reaches you through the optional `onRetry` observer, not through a wrapper type.
  - **The deadline bounds the loop, not an attempt.** It cannot end an operation that is already
    running, so what it guarantees is that no *new* attempt begins past it. Bounding a single hung
    call is still `HttpClient`'s wall-clock deadline (ADR-0049). A deadline here over an unbounded
    attempt gives you less than the parameter name suggests, which is why it is written down.
  - **Jitter cannot be switched off.** Full jitter over the exponential ceiling, with no argument
    that disables it — without it, N clients that failed together retry together and the retry
    storm is the outage. The trade is stated: a draw can come back near zero.
  - **A delay that will not fit inside the deadline ends the loop rather than being shortened.**
    Clamping the backoff means retrying soonest exactly when the dependency is struggling.
  - **`FrozenSleeper` advances the `FrozenClock` you give it**, so your retry tests neither sleep
    nor skip the deadline arithmetic. PSR-20 has no `sleep()`, which is why waiting is a second seam
    rather than something the clock could cover.
  Refused at construction rather than clamped: fewer than one attempt, a multiplier below `1.0`, a
  ceiling below the base delay, a zero deadline (pass `null` for none), an empty retryable
  allowlist, or a non-`Throwable` in it. **Non-goal, stated:** no circuit breaker — that is shared
  state across calls, not a parameter.
- **`Security\Hmac` — keyed authentication for signed URLs and webhook signatures** (spec
  **r20 FR-48**, RFC-0003; roadmap item **14.4**; **ADR-0065**; closes issue #92). Additive under
  ADR-0059. `sign(message, ttl = null)` returns `v1.` + base64url(8-byte big-endian expiry ||
  raw MAC); `verify(message, token)` returns **void** and throws `CryptoException` on every
  failure. Five things worth knowing before wiring it:
  - **The signature is detached.** The message stays where it lives — a URL's query, a webhook
    body — because `verify()` is handed it back anyway; a container format would duplicate it on
    the wire.
  - **The MAC covers the expiry, not just the message.** An unauthenticated expiry would make
    extending a signed URL's life an eight-byte edit. Its width is fixed so the concatenation needs
    no delimiter: with a variable-width prefix, `1 || "23"` and `12 || "3"` sign identically.
  - **The algorithm is never read from the token.** It is chosen at construction from an allowlist
    (`sha256`/`sha384`/`sha512`) — a format naming its own algorithm lets an attacker choose how
    their forgery is checked, the JWT `alg`-confusion class. The consequence is deliberate and
    tested: changing algorithm invalidates outstanding tokens rather than trusting them.
  - **The MAC key is derived, not your `SecretKey`.** `hash_hkdf(algorithm, secret, 0,
    'egl/utils:hmac:v1')`, so a single `APP_SECRET` behind both `Crypto` and `Hmac` never feeds the
    same bytes to two primitives. The label is part of the `v1.` format — a verifier in another
    language needs it, along with the payload layout.
  - **Expiry is refused rather than fudged.** A TTL that does not move time forward, or one landing
    at or before the Unix epoch (timestamp `0` is the never-expires sentinel), throws at `sign()`.
    The boundary is inclusive-expired, RFC 7519 `exp` semantics, and measured against FR-45's
    clock so no test sleeps.
- **`Persistence\PageRequest` and `Persistence\Page<T>` — pagination value objects** (spec
  **r19 FR-47**, RFC-0003; roadmap item **14.3**; **ADR-0064**; closes issue #95), with
  `Repository::fetchPage()` and `TableGateway::paginate()`/`paginateBy()` as the reads, plus two
  additive `QueryBuilder` methods (`asRowCount()`, `isOrdered()`). All additive under ADR-0059.
  Four behaviours worth knowing before wiring it:
  - **A paginated read refuses an unordered query.** SQL promises no row order without `ORDER BY`,
    so consecutive windows over the same query may repeat one row and skip another *while every
    page looks perfectly valid*. `TableGateway`'s own paginated reads order by the key column, so
    callers do not have to think about it; a caller with their own query supplies the ordering or
    is told why they must.
  - **The total costs a second statement**, and `withTotal` defaults to on. `COUNT(*) OVER ()`
    would collapse the two, and is rejected on portability rather than merit: every database proof
    here is SQLite-only (issue #110).
  - **Asking for a total that was never counted throws** rather than answering `0` — a zero
    meaning "not counted" renders as "no results" over a table that is not empty. `hasTotal()` and
    `totalOr()` are the tolerant forms, reusing `Lookup`'s missing-value policy.
  - **Nonsense is refused, not clamped**: a page below 1, a size below 1, or an offset that would
    overflow PHP's integer range. `pageCount()` is `1` for an empty result set, never `0`.

### Fixed

- **The constant-time comparison registry had been blind to every call in the library**
  ([BUG-0001](docs/bugs/2026/08/BUG-0001-constant-time-registry-blind-to-prefixed-calls.md), the
  bug ledger's first record; found by item 14.4 and fixed in the same PR).
  `ConstantTimeComparisonTest`'s completeness guard exists so a secret comparison added later
  cannot go unasserted — and the defect it guards against, `===` in place of `hash_equals()`, is
  invisible to every behavioural test. Its scanner matched `T_STRING`, and ADR-0048 prefixed every
  internal call at item 10.12, so `\hash_equals` became `T_NAME_FULLY_QUALIFIED` and the scanner
  saw **0 of 3** comparisons from then on. Demonstrated rather than inferred: in that state, with a
  timing-unsafe comparison present and unregistered in `src/main`, the file reported
  `OK (5 tests, 15 assertions)`. **No shipped code ever used a weakened comparator** — all three
  call sites were verified directly — so no consumer was affected; what was missing was the
  guarantee about the next one. The scanner now matches both token shapes, and a new assertion
  requires it to *see* at least as many comparisons as are registered, because the failure mode
  here was a test going **green**, not red.
- **FR-46's specification entry, which item 14.2 never wrote.** `NFR-15` and r18's own revision
  row both referenced `FR-46` while §2 contained no such requirement — a cross-reference pointing
  at something absent, the defect class ADR-0060 named when `SECURITY.md` deferred to a section
  that did not exist. Added in full at r19.

- **`Str::ulid()` and `Str::uuidV7()` — time-sortable identifiers** (spec **r18 FR-46/NFR-15**,
  RFC-0003; roadmap item **14.2**; **ADR-0063**; closes issue #96). A random v4 UUID as a primary
  key fragments a B-tree index at enterprise table sizes; these carry a 48-bit millisecond
  timestamp in their leading bits, so **sorting the strings sorts them by generation time**. Both
  ship because they are not substitutes: `uuidV7()` is valid anywhere `uuid()` is (same shape, for
  a UUID column, cast or validator), while `ulid()` is shorter, separator-free and drawn from a
  transcription-safe alphabet. Both take an optional `Psr\Clock\ClockInterface`; both are additive.
  Three behaviours worth knowing:
  - **Ordering within a single millisecond is explicitly not guaranteed.** Identifiers from the
    same millisecond share a timestamp prefix and are ordered only by their random tails.
    Guaranteeing otherwise needs cross-call state in a static method, which this library refuses;
    the index locality that motivates the format is a millisecond-granularity property and is
    unaffected. A consumer needing the guarantee needs a stateful generator, which this does not
    preclude.
  - **An unrepresentable instant is refused, never truncated** — before the Unix epoch or beyond
    `10889-08-02T05:31:50.655Z` raises `InvalidArgumentException` naming the method called. A
    wrapped timestamp would be a well-formed identifier that *sorts wrongly*, silently defeating
    the one property the format exists for, in a value that outlives the bug.
  - **NFR-15 budgets generation at ≤ 10 µs** (CI ceiling), derived from measurement rather than
    chosen from two reference-runner runs: ULID 3.453 → 3.722 µs, UUIDv7 2.592 → 2.812 µs, putting
    the ceiling at 2.69× the worst reading — the bottom edge of ADR-0058's ≥ 2.66× band.

- **`D4np\Utils\Support\SystemClock` and `Support\FrozenClock`** — the PSR-20 time seam (spec
  **r17 FR-45**, RFC-0003; roadmap item **14.1**, M14's keystone; **ADR-0062**; closes issue #97).
  Both implement `Psr\Clock\ClockInterface`, and `psr/clock` joins `require` as the third
  interface-only dependency (NFR-08's posture, RFC-0001 R-3's carve-out). Every time-touching API
  added from here on accepts the interface — the retry policy, HMAC expiry and rate-limiter
  refill items all consume it, which is why this shipped first and alone. Behaviours worth
  knowing before wiring it:
  - **`SystemClock::now()` returns a fresh `DateTimeImmutable` per call**, in PHP's default
    timezone unless a `DateTimeZone` object was injected — byte-for-byte what
    `new DateTimeImmutable('now')` does, so the seam changes *where* time is read, never *what*.
    Construction cannot fail: the parameter is an object, not a string, so an invalid zone never
    reaches this class.
  - **`FrozenClock` is the shipped test double and deliberately mutable**: `advance(DateInterval)`
    moves the held instant — cumulatively, and **honouring inverted intervals**, because time
    moving backward is a first-class clock-skew scenario (ADR-0061 §5), not an error.
  - **`Support` gains its first outward deptrac edge in sixty ADRs** (`Support → Psr`), a
    consequence RFC-0003's accepted placements had already made inevitable; the config comment
    claiming "Support depends on nothing" is retired in place, and the new grant is proven to
    discriminate (a planted `Support → Dto` reference refused by name; 344 allowed edges, zero
    violations, zero uncovered).

- **ADR-0061, the rate-limiter design** (issue #91, reopened by the maintainer on 2026-08-13
  against RFC-0003's deferral — whose revisit condition, *"when a storage seam exists"*, proved
  unreachable: nothing in the backlog creates a storage seam, because the seam is this issue's own
  deliverable). Decision-only, the item 7.4/ADR-0033 shape — no code lands. Decided: a **token
  bucket** (fixed window rejected as the estate's boundary-burst defect institutionalized; sliding
  log as attacker-controlled memory; sliding counter as an estimate sold as a limit; GCRA as
  equivalent but less legible) behind a **compare-and-swap store seam**, because a get/set store
  cannot be composed race-free by any caller; **keys hashed at the boundary** (no store-syntax
  injection, no path traversal in the library's own file store, fixed per-key cost,
  content-oblivious comparisons by construction); refill on the injected PSR-20 clock with
  negative elapsed clamped so a skewed node can never mint tokens; **a store failure is never an
  allow** — it propagates typed and the caller owns the availability-versus-security call. Two
  stores will ship with their enforcement scope in their own docblocks (array: one process; file
  over `File::update()`'s locked RMW: one machine), and the multi-node honesty statement the
  deferral demanded is decided verbatim in the ADR. Implementation is roadmap item **14.7**
  (FR-50 reserved), after 14.1's clock. Along the way: the patterns catalogue's *Planned* status
  and `consistency_lint.py`'s patterns check turn out to disagree (the lint requires a code
  location the *Planned* vocabulary says may not exist yet) — recorded in the catalogue rather
  than resolved unilaterally.

- **`docs/patterns/third-party-picks.md`** (issue #90) — endorsed third-party libraries for needs
  this library deliberately doesn't cover: `brick/math` (money/decimal arithmetic), `symfony/cache`
  (PSR-6/16 caching), `symfony/mailer` behind the existing `Mail\Mailer` seam. Carries the explicit
  do-not-add list (money arithmetic, ORM features, an SMTP client, console/i18n helpers) so a
  scope-creep request has a citable answer instead of a fresh argument every time. States plainly
  that the two currently-deferred M14 candidates (rate limiting, the PSR-18 bridge) are **not** on
  this page — deferred in-scope future work is a different claim from "bring your own," and
  recommending a stand-in would blur it. Linked from `README.md`'s docs table and the patterns
  catalogue index.

- **`docs/upgrading.md`** (issue #89), the consumer-facing deprecation lifecycle and
  supported-versions guidance — translating `docs/workflow/maintenance.md`'s internal decision
  tree and ADR-0060 into terms a consumer can act on, written before the first deprecation
  exists rather than after one is discovered. Linked from `README.md` and `SECURITY.md`. Corrects
  a removal-timing detail worth stating plainly: post-1.0, no MINOR release ever removes a
  deprecated symbol regardless of how long its deprecation window has been closed — only a MAJOR
  does, since the maintenance decision tree routes every removal to a MAJOR once past 1.0. An
  earlier draft of this page said a symbol deprecated in `1.4.0` could be removed in `1.6.0`,
  which the decision tree it was translating does not actually permit; caught and fixed before
  publishing rather than after.

- **A consumer-facing `## Highlights` section** at the top of `docs/changelog/v1/v1.0.0.md`
  (issue #88) — the 1,186-line file interleaves 21 repeated `### Added`/`### Changed`/`### Fixed`
  headings in a newest-first engineering roll, self-acknowledged in its own provenance note, and
  said nothing scannable about what shipped. The new section restates nothing the log below
  doesn't already say at length; it exists so a reader doesn't have to read the whole record to
  learn the box's contents. `docs/workflow/release.md`'s changelog-roll step gains the matching
  instruction so future rolls open this way by construction, independent of ROADMAP 13.5's still-
  open question of which of the two changelog locations is canonical.

- **The community files a public repository is expected to carry** (issue #87 / ROADMAP 13.6):
  `CONTRIBUTING.md`, built entirely from gates already documented and enforced elsewhere (the
  `local-build.md` PR checklist, `AGENTS.md` §6's commit/branch/PR conventions) rather than new
  policy; `CODE_OF_CONDUCT.md`, the Contributor Covenant 2.1 verbatim with its Enforcement section
  pointed at the same private GitHub vulnerability-reporting channel `SECURITY.md` uses;
  `packages/utils-psr7-bridge/LICENSE`, identical MIT text to the root, so the package the split
  pipeline (ADR-0033) publishes carries the licence text its `composer.json` already claims.
  `README.md` gains a pointer row for both root files.

- **GitHub-side configuration applied for the first time** (issue #86 / ROADMAP 13.8). Milestone
  naming reconciled to `vX.Y.Z` — every one of the 14 `ROADMAP.md` milestone headers already used
  it, so `AGENTS.md` §6.4 was corrected to match rather than retrofitting history. All 11 type
  labels from `.github/labels.yml` imported, plus a newly-added 11th (`release` — 4 real merged
  commits already used that type with no label to match) and a 12th, `adr`, a routing signal
  `os/routing` depends on that had never existed as a real label. 12 fully-closed milestones
  closed on GitHub (the 11 stale `v0.x.0` ones, plus a 12th — `utils-psr7-bridge-v0.1.0` — that had
  never been created despite M8 closing in 2026-08-05); `post-1.0` (M13) and `v1.1.0` (M14)
  created. `.eados-core/tools/seed_milestones.py` corrected locally, before use, to title milestones
  from each header's own tag instead of a hardcoded, never-matched `MN — name` — the fix itself
  ships in no commit, since `.eados-core/**` is gitignored factory tooling; only the milestones it
  created on GitHub persist. The 2026-08-09 batch of 39 issues
  labelled by type; the nine whose own acceptance criteria require an ADR also carry `adr`, so
  `route_advice.py --issue N` now resolves them to the policy's actual floor instead of `fast/low`.

- **Nine `## References` sections added to the ADR corpus** (issue #85), one per real ADR that
  lacked one, built from each file's own `Related:` header line rather than invented; twelve
  `## Alternatives` headings renamed to the template's canonical `## Alternatives Considered`.
  `docs/patterns/README.md`'s two scaffold sections (*Candidate patterns*, *Out-of-scope
  categories*) now carry real, sourced entries instead of template instructions. `ROADMAP.md`'s
  Spec Coverage Map flips six sections from 🚧 to ✅ — every roadmap item they reference is
  closed, and the frozen spec they track has not changed since.

- **Milestone 14, the first functional roadmap after the freeze** (issue #84), specified by the
  newly accepted [RFC-0003](docs/rfc/0003-post-1-0-functional-scope.md). Five numbered items, all
  additive under ADR-0059: **FR-45** PSR-20 clock (`SystemClock`/`FrozenClock`), **FR-46**
  time-sortable identifiers (`Str::ulid()`/`Str::uuidV7()`), **FR-47** pagination value objects in
  `Persistence`, **FR-48** `Security\Hmac`, **FR-49** `Support\RetryPolicy`. Item 14.1 is sequenced
  first because three of the others need a clock and `src/main` contains no time abstraction at all.
  Two of the review board's seven candidates are **deferred with their reasons recorded and their
  issues left open** — the rate limiter (#91) because a single-node limiter behind a load balancer
  looks like protection and is not, and the PSR-18 bridge (#93) because it would be the second
  consumer of a split-publication pipeline that has never executed. The **do-NOT-add list** (money
  arithmetic, ORM features, an SMTP client, console/i18n helpers) is recorded in the milestone
  preamble so scope-creep requests have a citable answer. `orchestrator/project.yaml` records
  `RFC-0003` and `M14` — and `M13`, which had been missing since the milestone was created.

- **`egl/utils` registered on Packagist** (issue #121), with the GitHub integration wired so
  future tags publish by webhook. Verified end to end: `composer require egl/utils:^1.0` in a
  clean throwaway project resolves `v1.0.0` at source commit `be7f34e` — the exact commit the tag
  points at — installs cleanly with no security advisories, and its autoloaded classes load.
  `README.md` gains a minimal `## Install` section stating the fact; `docs/releases/v1.0.0.md`'s
  "never been installed from Packagist" line is corrected without being rewritten to look
  prescient; `docs/workflow/release.md`'s prerequisite is marked done with the evidence.
  **This also closes the issue's squat-protection criterion, at no extra cost.** Packagist
  protects a vendor namespace as soon as one package under it is published — *"you can not
  publish packages with a vendor name that already exists on packagist without permission"*,
  and publishing under an existing vendor requires being maintainer of a package already in it.
  Registering `egl/utils` therefore locked the whole `egl/` namespace, so `egl/utils-psr7-bridge`
  cannot be squatted by anyone else and no split repository is needed to defend the name. What
  the split repository (issue #120) is still needed for is *publishing* the bridge, since
  Packagist resolves a package from a repository with `composer.json` at its **root** and the
  bridge's sits under `packages/`. The original acceptance criterion had fused protection and
  publication into one line; they are independent, and only the second is still open.
- **Supported-versions window for the post-1.0 line**, defined in
  [`docs/workflow/maintenance.md`](docs/workflow/maintenance.md) and pointed to from `SECURITY.md`:
  the latest release of the current MAJOR, with the previous MAJOR's final release on security
  fixes until `X+1.1.0` ships. `SECURITY.md` had deferred to that section since the repository was
  generated; the section had never existed (ADR-0060).
- **[`ISSUES.md`](ISSUES.md), a reverse-chronological index of the issue tracker.** One bullet per
  GitHub issue, newest first, each carrying the advisory `route: <tier> / <effort>` from
  `ROADMAP.md`'s routing vocabulary; new issues are prepended, closed ones get their checkbox
  flipped in place. Seeded with the 39 issues (#84–#122) consolidated from the 2026-08-09
  seven-seat release review of `v1.0.0`; issues mirroring a `ROADMAP.md` M13 item cross-reference
  it rather than replacing it. `README.md` gains the pointer row.

### Fixed

- **Ten cosmetic defects the review board logged as minor** (issue #85), each verified against
  the tree before touching it: `Version.php`'s docblock described the retired pre-1.0 versioning
  scheme at a 1.0.0 HEAD; a verbless README fragment; a duplicated checklist item in
  `local-build.md`; `nightly.yml`'s comment claimed `composer install` re-resolves dependencies,
  which a committed root `composer.lock` makes false (only the runner environment moves between
  nightly runs); the bridge's `composer.json` carried no `homepage`/`support` block;
  `Result::orElseThrow()` documented its rethrow contract without an `@throws` tag; and
  `docs/workflow/release.md` step 10 claimed CI "builds & attaches artifacts" when
  `draft-release`'s only step sets `draft`/`generate_release_notes`/`body_path` and attaches
  nothing — corrected to state why: the release *is* the tagged source, resolved via Packagist,
  not a downloadable binary. **Left open**: the root and bridge `composer.json` author blocks
  still carry no email — adding one publishes it to Packagist, which is the maintainer's call,
  not a default to fill in.
- **`docs/releases/v1.0.0.md` no longer claims the release gate approved it.** The notes stated the
  published tree was *"the one the gate approved"*; it is not. `v1.0.0`'s tag is unsigned, so
  `release.yml`'s `The tag must be signed` step failed (run 31283673519), the tagged-tree 8.1/8.2/8.3
  matrix and the draft-Release job were both **skipped**, and the GitHub Release was published by
  hand 13 minutes later. The sentence is gone and a `How this release was published` section records
  the bypass, what the missing matrix would have proved, that `quality / backward compatibility`
  reported green on the release PR having compared nothing (no `v*.*.*` tag existed), and that the
  copy of these notes *inside the tagged tree* still carries the stale `0.x` text a tag's
  immutability puts out of reach. The GitHub Release body carries the same correction. The signing
  decision itself stands and is not reopened here; completing the signing chain is issue #115
  (ROADMAP item 13.1).
- **The same claim removed from the two other places it had been copied to.**
  `docs/changelog/v1/v1.0.0.md`'s *Superseded pre-release* section said the shipped tree "is the
  one the gate approved" and now records the bypass instead; **ADR-0059**'s Decision point 4 said
  it too, and carries a Status annotation correcting the fact while leaving the decision intact
  (ADR-0041's annotate-don't-edit precedent). Both were written before the tag was pushed, which is
  how a claim about the future ended up recorded as history.
- **`docs/releases/v1.0.0.md` no longer contradicts itself.** Its closing section still declared
  that the 1.0.0 API-freeze review *"has not happened"* and that *"this is a `0.x` release"* — text
  carried over from the unpublished `v0.11.0` notes, inside the document announcing the freeze.
  Removed. The same file recorded the bridge's constraint on the core as `^0.11`; corrected to the
  `^1.0` that `packages/utils-psr7-bridge/composer.json` actually declares.
- **`SECURITY.md`'s supported-versions table applies at a `1.0.0` HEAD.** It previously offered only
  `latest released 0.x` and `older 0.x`, and no `0.x` was ever published — so the policy's table had
  no row a consumer could be standing on.
- **`packages/utils-psr7-bridge/README.md` no longer announces itself as a scaffold** whose
  converters *"land in 8.2"*. The converters and their BFR-01…BFR-22 contract suite shipped in item
  8.2 and the publication pipeline in 8.3; the banner now states the real remaining gap (the
  one-time publication setup), and *Usage* carries a worked example verified against the frozen 1.0
  signatures instead of a forward reference.
- Two dead `ROADMAP.md` links to ADR-0040, which had pointed at the file's pre-rename name since it
  was renamed.

---

## Released versions

| Version | Date | Notes |
|---------|------|-------|
| [v1.0.0](docs/changelog/v1/v1.0.0.md) | 2026-08-09 | The first release — every milestone M1–M12 — and the API freeze (ADR-0059). |
