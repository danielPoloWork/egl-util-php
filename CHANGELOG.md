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

### Added

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

### Fixed

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
