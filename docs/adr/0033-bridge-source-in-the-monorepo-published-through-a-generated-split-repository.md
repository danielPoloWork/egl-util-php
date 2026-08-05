# ADR-0033: The bridge's source lives in this monorepo; a generated, read-only split repository is only its publication target

- **Status:** Accepted
- **Date:** 2026-08-05
- **Deciders:** maintainer (`@danielPoloWork`, who selected the topology and corrected the
  analysis), agent acting as tech-lead — **run at the routed tier** (`frontier-reasoning / extra`,
  protected floor; the maintainer switched the session to Fable 5 rather than accepting a mismatch)
- **Related:** ROADMAP item 7.4 (closes Milestone 7) · [RFC-0001](../rfc/0001-egl-utils-library.md)
  A-8 (the deferred packaging decision this settles) ·
  imported [ADR-002](../../.specs/d4np_php_adr_002_http_psr7.md) (the bridge exists, is optional,
  and owes conversion-fidelity contract tests) · spec NFR-08 (dependency policy) ·
  [ADR-0025](0025-typed-http-wrappers-that-refuse-rather-than-coerce.md) (the refuse-don't-coerce
  semantics the contract carries across the boundary) ·
  [ADR-0032](0032-verify-the-tag-before-drafting-and-let-packagist-pull.md) (the signed-tag
  verification the publication pipeline reuses) ·
  **Contract:** [`docs/specs/02_spec_psr7_bridge.md`](../specs/02_spec_psr7_bridge.md) (the package
  boundary, versioning scheme, publication pipeline and conversion contract this ADR commissions)

## Context

Imported ADR-002 already decided the *what*: `Request`/`Response` stay dependency-free in the core,
and PSR-7 interop lives in an **optional separate Composer package**, `egl/utils-psr7-bridge`, which
owes conversion-fidelity contract tests. RFC-0001's A-8 deferred the *where*: "subtree vs second
repository (possibly a second EADOS run)".

Two findings reframed that wording before any option could be weighed.

**First, the mechanical one.** Packagist resolves a VCS-backed package from a repository whose
`composer.json` sits at the **root**. A path inside this repository cannot be a Packagist package,
which is why monorepo-split tooling exists at all. So "subtree" was never an alternative to a second
repository — a second repository exists under every option, and the real question is whether it is
**authored** (development happens there) or **generated** (a read-only publication artifact).

**Second, a correction from the maintainer that removed a cost from the ledger.** The first analysis
counted "a second copy of the governance built in items 7.1–7.3" against the authored-repository
option. That conflated an external code-generation tool with repository governance: EADOS is not
part of the library, is not committed, and duplicating a generation *tool* is not an architectural
cost of a second *repository*. With that struck, the comparison stands on four honest axes:

1. one authored repository versus two;
2. **same-PR integration testing versus cross-repository compatibility testing**;
3. one contribution and review flow versus two;
4. generated publication infrastructure versus duplicated repository maintenance.

The authored-repository option is stronger than the uncorrected analysis made it look — and still
loses, on axis 2 above all.

## Decision

### 1. Canonical source: `packages/utils-psr7-bridge/` in this repository

A strict package boundary containing its own `composer.json` (package `egl/utils-psr7-bridge`), its
own PSR-4 namespace (`D4np\Utils\Bridge\Psr7\`), its own tests, static-analysis configuration,
changelog and documentation — the full boundary is specified in
[`02_spec_psr7_bridge.md` §2](../specs/02_spec_psr7_bridge.md). All development happens here, under
this repository's review flow and quality bar.

The load-bearing property is axis 2: **a core change that violates the PSR-7 conversion contract
fails in the pull request that introduces it.** In CI the bridge installs the core from the working
tree via an injected Composer path repository, so the contract tests run against the exact code
under review — not against whatever core release the bridge happened to pin. With an authored second
repository, the same defect is discovered later, in the other repository's CI, after the offending
change has already merged here.

### 2. The split repository is a publication target and nothing else

Generated, **read-only**: no authored changes, no manual commits, no pull requests. Its README
states that it is produced from this repository and links back. Its only writer is the publication
pipeline. It exists because Packagist needs it, and for no other reason.

### 3. The bridge versions independently, by package-scoped tags — designed, not inherited

Tags in this repository of the form `utils-psr7-bridge-vMAJOR.MINOR.PATCH` version the bridge; the
publication pipeline translates each into a plain `vMAJOR.MINOR.PATCH` tag on the split repository,
which is what Packagist reads. The bridge starts its own pre-1.0 line at `utils-psr7-bridge-v0.1.0`.

Nothing about the core's release cycle forces a bridge release or vice versa: a core release that
does not touch the bridge ships no bridge version, and a bridge fix does not wait for a core
milestone. The two tag grammars cannot collide: `release.yml` triggers on `v*.*.*`, and GitHub's
filter patterns match the whole ref name, so `utils-psr7-bridge-v0.1.0` — which starts with `u` —
does not match (`likely`, from GitHub's documented pattern semantics; item 8.3 verifies it against a
real tag before the first publication, because a documented behavior is still an assumption until a
tag has been pushed).

The core's "one MINOR per completed milestone" rule (AGENTS.md §11) applies to milestones that
change the **core package**. Milestone 8's deliverable versions under the bridge's own line; its
core-side changes (a CI job, docs) are chore-level and roll into whichever core release follows.

### 4. Two test modes, because the same-PR guarantee has a flip side

PR mode proves the bridge against the **working tree**. But the bridge's committed `composer.json`
declares a constraint against **released** core versions — and a constraint proven only against
unreleased HEAD is a claim without evidence. So the publication pipeline runs the contract suite a
second time, in **release mode**: a clean install with no path repository, resolving `egl/utils`
from Packagist as a consumer would. A bridge tag ships only if both modes pass. The two modes and
their mechanics are specified in [`02_spec_psr7_bridge.md` §6](../specs/02_spec_psr7_bridge.md).

### 5. The authenticity chain anchors at a signed monorepo tag

The split repository's tags are created by automation holding a `GITHUB_TOKEN`, so they cannot be
maintainer-signed — and per ADR-0032, no signing key ever reaches a runner. The chain is instead:
the maintainer signs `utils-psr7-bridge-vX.Y.Z` **here**; the pipeline verifies that signature via
GitHub's own verification (ADR-0032's mechanism, reused) before splitting; the split tag is a
derived build artifact of a verified source. The split repository asserts nothing on its own — the
assertion lives where the signature is.

### 6. What Milestone 8 contains

This ADR decides; it does not implement. A new ROADMAP milestone carries the implementation:

- **8.1** — scaffold `packages/utils-psr7-bridge/` per the boundary spec, and the self-enabling
  monorepo CI job (guarded on the package's `composer.json` existing, lesson L-0010's shape).
- **8.2** — the converters and the full contract suite (`T-B`), every clause of
  `02_spec_psr7_bridge.md` §4–§5 tested against **two** PSR-17 implementations, with the planted-
  defect verification discipline this project already holds tests to.
- **8.3** — the publication pipeline: split, tag translation, signed-source verification, release-
  mode gate, the read-only split repository and the Packagist registration (the last two are
  one-time maintainer actions, documented as such).

The CI job is deliberately **not** added now: a job that skips for the months until 8.1 lands is
noise, and unlike the scaffold-generated benchmark job (which had to self-enable because the
workflow could not be edited later), `ci.yml` is ordinary authored code that 8.1 can extend.

## Alternatives Considered

- **An authored second repository** — the strongest rival, and stronger after the EADOS correction
  removed a phantom cost. Rejected on the remaining axes: cross-repository compatibility testing
  discovers a contract-breaking core change only after it merges here; two contribution flows and
  two review surfaces for one team; and the operational overhead of a second authored repository
  buys independence the bridge does not want — its whole purpose is to track the core closely.
- **A second EADOS run to create that repository** (RFC A-8's parenthetical) — rejected as a
  category error, per the maintainer's correction: EADOS is an external generation tool, not
  governance to be duplicated. Whatever generates code, its output is reviewed as ordinary source in
  the canonical repository; the tool, its state, credentials and caches stay outside both
  repositories.
- **Building `toPsr7()`/`fromPsr7()` into the core** behind `interface_exists()` guards — rejected:
  it contradicts imported ADR-002 (the bridge is a separate optional package) and NFR-08 (no
  implementation dependencies in the core), and would put PSR types in the core's public signatures
  where PHPStan-level consumers would meet them without the interfaces installed.
- **Sharing the core's tags** (bridge published at every `vX.Y.Z`) — rejected explicitly rather than
  by omission, because it is the *default outcome* of a monorepo if versioning is not designed: the
  bridge's version would then communicate core events, not bridge API changes. The maintainer's
  instruction was precise on this — independent versioning is part of the decision, not an option
  within it.
- **Deferring the decision** — rejected: item 7.4 *is* the decision, and RFC A-8 already deferred it
  once, to plan. A second deferral leaves Milestone 8 unplannable.

## Consequences

- No code lands with this ADR. The deliverable is this decision plus
  [`02_spec_psr7_bridge.md`](../specs/02_spec_psr7_bridge.md) — the package boundary, the
  independent-versioning scheme, the publication pipeline contract, ADR-002's conversion contract as
  numbered, testable clauses (BFR-01…BFR-22), and the CI design for both test modes.
- **Milestone 8** (items 8.1–8.3) is added to the ROADMAP; the README milestone table gains its row.
- The Spec Coverage Map's §7 row **reopens** (✅ → 🚧, gaining 8.2): the frozen spec's §6 names
  *"bridge conversion-fidelity contract tests in egl/utils-psr7-bridge CI"*, and those tests do not
  exist yet. Marking §7 done at 6.3 was correct for the suites that had components to land with;
  with the bridge milestone now real, leaving the row closed would hide named, unfinished spec work.
  §8 gains 8.3 (the publication pipeline is release engineering) and stays 🚧.
- Imported ADR-002's literal naming — `Request::toPsr7()` — is realized as bridge-owned converters
  instead, because PHP has no partial classes and the core cannot carry methods whose signatures
  name PSR types without violating NFR-08. The deviation and its API shape are recorded in the
  contract's §3, not silently.
- The two fidelity traps that make the contract more than ceremony are specified with refusal
  semantics carried over from ADR-0025: a PSR-7 response bearing **multiple `Set-Cookie` headers**
  is refused rather than comma-joined (RFC 6265 cookie strings contain commas; joining corrupts
  them), and uploaded files cross the `$_FILES`-array ↔ `UploadedFileInterface`-stream boundary with
  error codes preserved and **no stream access on a failed upload**.

## References

- Imported ADR-002 — the bridge's existence, optionality, and contract-test obligation
- RFC-0001 A-8 — the deferral this closes; R-1/R-3 — placement and the dependency-policy correction
- The maintainer's decision record (2026-08-05): canonical monorepo source, generated read-only
  split, package-scoped tags translated at publication, decision-not-implementation scope — and the
  EADOS scoping correction incorporated above
- Packagist/Composer VCS repository resolution (root `composer.json`) — the fact that reframed A-8
