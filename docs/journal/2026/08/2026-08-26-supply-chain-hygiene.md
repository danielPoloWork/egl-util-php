# 2026-08-26 — The require-checker's first run found four undeclared symbols, not zero

Issue **#98**, all four checklist items. Route `fast / medium`; session model Sonnet 5.
**ADR-0076** annotated, spec **r24**.

Four Build-seat recommendations from the 2026-08-09 review board, batched as one PR (#171).
Three needed real changes; one, investigated first, turned out not to.

## Item 3 was already done, and the fix was a paragraph, not a diff

"Renovate (or a Dependabot config extension) for `composer.lock` refresh PRs" reads like a gap.
`.github/dependabot.yml` already runs a grouped, weekly `composer` update against the root
directory, and Dependabot's version-update check considers the whole resolved tree — direct and
transitive — so a newer compatible release of anything already opens a PR that refreshes the lock
on that cadence. Adding Renovate next to it would be two bots proposing the same class of PR
against the same file. ADR-0076 records this rather than shipping a second tool that duplicates
the first — the batch's cheapest item was the one that cost nothing.

## The two tools split on one question: does it need to *be* the project, or just look at it?

`nightly.yml`'s new `composer audit` job and `ci.yml`'s new ComposerRequireChecker step both
needed no more than the pattern this repository already has for Psalm and Infection — install
outside the package's own dependency graph, because the tool's PHP floor (>= 8.2) exceeds the
library's own (>= 8.1) and `lowest-deps` installs dev dependencies too.

The SBOM generator did not fit that pattern, and figuring out *why* was the one real design
question in this PR. `cyclonedx/cyclonedx-php-composer` is not a standalone analyser reading
source files — it is a Composer **plugin** that reads *this* project's own
`vendor/composer/installed.json` through Composer's own runtime API. Installed into a throwaway
directory, it would describe that empty directory, not this library. Its `php ^8.1` requirement
happens to match the library's own floor exactly, so it went into `require-dev` like any other
project tool instead — verified locally before committing: `composer update
cyclonedx/cyclonedx-php-composer` locked exactly seven new packages and bumped nothing already
locked.

## What the require-checker actually found

The tempting assumption going in was that this step would be inert — a proof that nothing was
wrong, landing green on the first try like `composer normalize --dry-run` always does. It was not.
The first real CI run reported **19 unknown symbols across four extensions**:

- `ext-filter` (`filter_var`, `FILTER_VALIDATE_*`) — reached from `Http\Request`,
  `Mail\EmailAddress`, `Support\Env`.
- `ext-session` (every `session_*` function) — reached from the `Http` session classes.
- `ext-openssl` (`openssl_encrypt`/`openssl_decrypt`) — reached from `Security\Crypto`.
- `ext-intl` (`transliterator_transliterate`) — reached from `Str::slug()`.

The first two had **no runtime guard anywhere in the source** — a missing extension there is a
fatal `Error`, not a caught one — so both joined `ext-pdo`/`ext-fileinfo` in `require` (NFR-08
amended in the same revision as NFR-07). The other two were a different finding entirely: both
were **already guarded**. `Security\Crypto::__construct()` already calls
`extension_loaded('openssl')` and throws `CryptoException` before either OpenSSL function is
reachable; `Str::slug()` already falls through `viaIntl()` → `viaIconv()` → `viaAsciiFilter()`,
returning `null` from `viaIntl()` via `function_exists()` when the extension is absent. Declaring
either as a hard `require` would have misstated a dependency the code already treats as optional,
so both joined `ext-iconv` and `symfony/html-sanitizer` in `suggest` instead, and
`composer-require-checker.json` whitelists exactly the symbols each guard covers — copying the
tool's own default `php-core-extensions` list rather than omitting it, per its own documented
warning that a partial config silently drops the defaults and reopens false positives on `true`,
`false`, `self` and the like.

This is what item 2 was for. A gate that had come back green on the first try would have proven
nothing; the two categories of finding — a real gap, and a dependency already handled correctly —
are the two outcomes the tool exists to tell apart, and this run produced one of each.

## One CI failure the tool itself did not cause

Fixing the require-checker findings meant adding `composer-require-checker.json` at the repo
root. `consistency / lint`'s `dist_gate.py` (issue #119) failed on the very next push: the file is
a dev-only CI config, and the dist-hygiene allowlist admits only `src/main/`, `LICENSE`,
`README.md` and `composer.json`. It needed an `export-ignore` line in `.gitattributes`, the same
line every other dev-only config in the repository already carries (`psalm-taint.xml`,
`deptrac.yaml`, `infection.json5`, ...) — missed in the first commit, caught by CI exactly as
issue #119's gate was built to catch it, fixed in the next.

## Where this leaves the project

No production PHP changed. Three CI/release workflow edits, one new config file, and
`composer.json`'s `require`/`suggest` blocks now state this library's actual platform surface more
precisely than they did before this PR — not a new dependency for any consumer, since every
consumer already had these extensions loaded; the code already called into them unconditionally.
`docs/workflow/release.md`'s boundary-table row "Build & attach artifacts — CI" is now literally
true rather than aspirational, and every future draft release carries a production-only CycloneDX
SBOM. All 20 CI checks passed on the second push; the maintainer merged as #171.
