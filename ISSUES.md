# Issues — egl-util-php

The GitHub issue tracker mirrored as a checkbox-driven list, **newest first** (descending
issue number). Companion to [`ROADMAP.md`](ROADMAP.md), same conventions:

- **New issue ⇒ prepend one bullet at the top** of the list; never reorder, never renumber.
- **Issue closed ⇒ flip its checkbox** (`- [ ]` → `- [x]`) in the PR that closes it, keeping
  the row in place.
- Every bullet carries the advisory **route** in `ROADMAP.md`'s vocabulary —
  `route: <tier> / <effort>` — resolved through the same dated catalog
  ([ROADMAP.md § Model & effort routing](ROADMAP.md#model--effort-routing-advisory); as of
  2026-07-27: `fast` = Sonnet 5 · `standard` = Opus 5 · `frontier-reasoning` = Fable 5;
  efforts `low` · `medium` · `high` · `extra` · `max`). The route *recommends*; the human
  keeps final model authority.

Seeded 2026-08-09 from the seven-seat release review board held on `v1.0.0` (verdict:
**approved with conditions**, average 7.7/10). Each issue names its originating seat(s)
and evidence; issues mirroring a `ROADMAP.md` M13 item cross-reference it rather than
replacing it.

## Issues (newest → oldest)

- [x] [#122](https://github.com/danielPoloWork/egl-util-php/issues/122) **release: settle the v1.0.0 tag provenance and correct the gate-approved claim** — The flagship release notes assert the opposite of the repository's own audit trail — route: standard / medium
- [ ] [#121](https://github.com/danielPoloWork/egl-util-php/issues/121) **build: register egl/utils on Packagist and verify the tag resolves** — `egl/utils` registered + resolution verified 2026-08-10; open only on the bridge vendor squat-protection, blocked on #120's split repo — route: fast / low
- [ ] [#120](https://github.com/danielPoloWork/egl-util-php/issues/120) **release: complete the utils-psr7-bridge publication (split repo, Packagist, first bridge tag)** — The library's entire interop story is implemented and contract-tested but not installable — route: standard / medium
- [ ] [#119](https://github.com/danielPoloWork/egl-util-php/issues/119) **build: add export-ignore rules and cut a v1.0.1 dist-hygiene patch** — The Packagist dist ships 524 files / 3.6 MB of which 97 are production code — route: fast / medium
- [ ] [#118](https://github.com/danielPoloWork/egl-util-php/issues/118) **docs: ship the consumer on-ramp (composer require, runnable examples, naming map, full surface)** — ROADMAP 13.2 — unanimous across the review: no path from landing on the repo to a first working call — route: standard / medium
- [ ] [#117](https://github.com/danielPoloWork/egl-util-php/issues/117) **docs: repair the five dead relative links in docs/changelog/v1/v1.0.0.md** — Entries rolled from the root changelog without path rebasing; same defect class PR 83 fixed elsewhere — route: fast / low
- [ ] [#116](https://github.com/danielPoloWork/egl-util-php/issues/116) **ci: wire a Markdown link checker into CI and a link-rebase step into the changelog roll** — ROADMAP 13.4 — proven non-speculative twice now: two dead links found on master, five more in the v1 changelog — route: standard / medium
- [ ] [#115](https://github.com/danielPoloWork/egl-util-php/issues/115) **security: complete the release signing chain (key setup, pre-push tag guard, attestations)** — Two consecutive releases failed the same signing step; a third must be impossible — route: standard / medium
- [ ] [#114](https://github.com/danielPoloWork/egl-util-php/issues/114) **security: add key identifiers to Crypto tokens (SecretKeyRing) for rotation** — Rotating after a suspected compromise currently orphans every outstanding token — route: frontier-reasoning / extra
- [ ] [#113](https://github.com/danielPoloWork/egl-util-php/issues/113) **feat: extract a ConnectionInterface from DatabaseConnection (additive MINOR)** — The only I/O boundary without a test seam; land it before consumer suites calcify around workarounds — route: frontier-reasoning / extra
- [ ] [#112](https://github.com/danielPoloWork/egl-util-php/issues/112) **ci: run the BC checker report-only on every PR against v1.0.0** — The release-PR-only rationale expired at 1.0; a mid-cycle break is now found far from the diff that caused it — route: standard / medium
- [ ] [#111](https://github.com/danielPoloWork/egl-util-php/issues/111) **build: pin the @internal inventory in consistency_lint.py** — Nothing prevents a future PR from stamping @internal onto a frozen symbol and silently shrinking the contract — route: fast / medium
- [ ] [#110](https://github.com/danielPoloWork/egl-util-php/issues/110) **ci: add a real-engine database leg (MySQL and PostgreSQL service containers)** — Every DB/persistence behavioral proof runs exclusively on SQLite today — route: standard / high
- [ ] [#109](https://github.com/danielPoloWork/egl-util-php/issues/109) **ci: add a per-diff coverage gate** — The 90 percent total floor lets an untested addition hide inside the headroom; ADR-0007 admits it openly — route: standard / medium
- [ ] [#108](https://github.com/danielPoloWork/egl-util-php/issues/108) **ci: widen mutation testing to Persistence (or a nightly advisory full-tree MSI)** — RowNormalizer/TableGateway are data-mapping, injection-adjacent code with no mutation-strength floor — route: standard / medium
- [ ] [#107](https://github.com/danielPoloWork/egl-util-php/issues/107) **docs: implement the phpDocumentor API-docs build or retract the claim** — ROADMAP 13.7 — a mandatory per-PR gate in AGENTS.md that has never run once — route: standard / medium
- [ ] [#106](https://github.com/danielPoloWork/egl-util-php/issues/106) **release: single-source the release notes and generate the Release body from the changelog** — ROADMAP 13.5 plus mechanical generation, so a hand-published Release can never diverge from the record — route: standard / medium
- [ ] [#105](https://github.com/danielPoloWork/egl-util-php/issues/105) **release: add a post-publish verification step to the release tooling** — Assert GitHub-side state after a release: tag verified, Release live and non-draft, package visible on Packagist — route: fast / medium
- [ ] [#104](https://github.com/danielPoloWork/egl-util-php/issues/104) **docs: add response-time targets to SECURITY.md** — Reporters need to know when silence means escalate — route: fast / low
- [ ] [#103](https://github.com/danielPoloWork/egl-util-php/issues/103) **ci: add a scheduled taint-analysis job (Psalm)** — PHPStan max proves type soundness, not source-to-sink flow; the injection boundaries deserve a second automated eye — route: standard / medium
- [ ] [#102](https://github.com/danielPoloWork/egl-util-php/issues/102) **security: harden three minor edges (per-hop scheme re-check, Hash downgrade signal, CSV guard visibility)** — Three documented trade-offs worth tightening; none exploitable as shipped — route: frontier-reasoning / high
- [ ] [#101](https://github.com/danielPoloWork/egl-util-php/issues/101) **test: wire-level mail capture leg (Mailpit) completing T-10** — Header-injection defense is proven at the mail() argument seam, never on a real wire — route: standard / medium
- [ ] [#100](https://github.com/danielPoloWork/egl-util-php/issues/100) **test: run one CI cell with randomized test order (logged seed)** — Flush hidden inter-test coupling before the suite grows past 3,000 tests — route: fast / low
- [ ] [#99](https://github.com/danielPoloWork/egl-util-php/issues/99) **ci: consume the benchmark control-breach exit code with one automatic re-run** — bench_regression_gate.py already distinguishes invalid run (exit 2) from regression; CI treats both as failure — route: fast / medium
- [ ] [#98](https://github.com/danielPoloWork/egl-util-php/issues/98) **build: supply-chain hygiene batch (scheduled audit, require-checker, lock cadence, SBOM)** — Four small Build-seat recommendations, one PR-sized batch — route: fast / medium
- [ ] [#97](https://github.com/danielPoloWork/egl-util-php/issues/97) **feat: PSR-20 clock in Support (SystemClock / FrozenClock) — M14 candidate** — src/main contains zero time abstraction; the single most re-implemented test seam in enterprise PHP — route: standard / medium
- [ ] [#96](https://github.com/danielPoloWork/egl-util-php/issues/96) **feat: sortable identifiers — Str::ulid() and Str::uuidV7() — M14 candidate** — FR-19..21 ship only UUIDv4, which is index-hostile as a database key at enterprise table sizes — route: standard / high
- [ ] [#95](https://github.com/danielPoloWork/egl-util-php/issues/95) **feat: pagination value objects in Persistence — M14 candidate** — Offset math and total-count plumbing is exactly the ad-hoc per-project code this library replaces — route: standard / medium
- [ ] [#94](https://github.com/danielPoloWork/egl-util-php/issues/94) **feat: retry/backoff policy object in Support — M15 candidate** — Resilience as an explicit mechanism, consumed by HttpClient and transaction callers — route: standard / high
- [ ] [#93](https://github.com/danielPoloWork/egl-util-php/issues/93) **feat: utils-psr18-bridge sub-package (PSR-18 adapter over HttpClient) — M15 candidate** — RFC-0001 Alternative 3 already framed PSR-18 as native wrappers plus optional bridge — route: standard / high
- [ ] [#92](https://github.com/danielPoloWork/egl-util-php/issues/92) **feat: HMAC signing utility (signed URLs, webhook signatures) — M14/M15 candidate** — Consumers hand-roll === comparisons next to a library that already knows better — route: frontier-reasoning / extra
- [ ] [#91](https://github.com/danielPoloWork/egl-util-php/issues/91) **feat: rate-limiting primitive (token bucket / fixed window) — M15 candidate** — Natural next security utility for login and Hash::verify call sites; security-floor route — route: frontier-reasoning / extra
- [ ] [#90](https://github.com/danielPoloWork/egl-util-php/issues/90) **docs: publish a third-party picks pattern page (brick/math, symfony/cache, symfony/mailer)** — Codify what deliberately stays OUT of the library so scope-creep requests have a documented answer — route: fast / medium
- [ ] [#89](https://github.com/danielPoloWork/egl-util-php/issues/89) **docs: publish docs/upgrading.md, the consumer-facing deprecation and support guidance** — Turn maintenance.md internal policy into consumer guidance before the first deprecation lands — route: fast / medium
- [ ] [#88](https://github.com/danielPoloWork/egl-util-php/issues/88) **docs: prepend a consumer highlights section to each per-version changelog** — The 1,178-line v1.0.0 changelog is a development log; adopters need what-is-in-the-box in under a minute — route: fast / low
- [ ] [#87](https://github.com/danielPoloWork/egl-util-php/issues/87) **docs: add the community files (CONTRIBUTING, CODE_OF_CONDUCT, bridge LICENSE)** — ROADMAP 13.6 — no front door for human contributors; the split bridge package would ship MIT with no licence text — route: fast / low
- [ ] [#86](https://github.com/danielPoloWork/egl-util-php/issues/86) **chore: apply the GitHub-side configuration (type labels, milestones)** — ROADMAP 13.8 — labels.yml never applied, eleven stale v0.x milestones, none for v1.0.0 — route: fast / low
- [ ] [#85](https://github.com/danielPoloWork/egl-util-php/issues/85) **docs: sweep the review board minor findings (template artifacts, stale comments, small gaps)** — One pass over the cosmetic defects all seven seats logged as minor — route: fast / medium
- [ ] [#84](https://github.com/danielPoloWork/egl-util-php/issues/84) **chore: define Milestone 14, the post-1.0 functional roadmap** — M13 is hygiene only and no M14 exists; run a plan pass over the board's M14/M15 candidates — route: standard / high
