# Changelog

All notable changes to `egl-util-php` are documented here, following
[Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning 2.0.0](https://semver.org/).

Every PR that introduces a user-visible change adds a line to `[Unreleased]` in the same
PR. A release PR moves the `[Unreleased]` entries into a new per-version file under
`docs/changelog/v<MAJOR>/v<X.Y.Z>.md` and adds an index row below.

## [Unreleased]

### Added

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
