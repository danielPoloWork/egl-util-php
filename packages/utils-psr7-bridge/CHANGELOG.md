# Changelog — `egl/utils-psr7-bridge`

All notable changes to this package, following
[Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning 2.0.0](https://semver.org/).

**This package versions independently of `egl/utils`** (ADR-0033 §3). Its releases are cut from
package-scoped tags in the monorepo — `utils-psr7-bridge-vMAJOR.MINOR.PATCH` — which the publication
pipeline translates into plain `vMAJOR.MINOR.PATCH` tags on the generated split repository. A core
release does not imply a bridge release, or the reverse.

## [Unreleased]

## [0.1.0] — 2026-08-27

The first published version of this package (issue #120). **`0.1.0`, not `1.0.0`, and the
minor is the claim being made**: the surface below is specified and contract-tested against two
PSR-17 vendors, but the publication pipeline that ships it had never executed end to end when this
version was cut, so a `1.0.0` would have promised stability for machinery with no run behind it. A
`0.x` lets the first publication be corrected without spending a major. `docs/workflow/release.md`
had used `utils-psr7-bridge-v0.1.0` as its worked example since the pipeline was written.

**Release mode was exercised before the tag, not after** — the gate ADR-0035 §2 calls the one that
cannot be faked, and which had never run: the package copied out of the monorepo, installed
resolving `egl/utils` from Packagist exactly as a consumer would, and its contract suite run
against that install. **65 tests, 202 assertions, green against `egl/utils v1.0.0`.**

Note for a reader comparing against the core: Packagist serves **only `v1.0.0`** of `egl/utils`
today. The core's `v1.1.0` tag exists but its publication never completed (issues #115, #105), so
`^1.0` resolves to `v1.0.0` — which satisfies this package's constraint and is what the run above
tested against.

### Added

- Publication pipeline (roadmap item **8.3**, **ADR-0035**): this package is released from a signed
  `utils-psr7-bridge-vX.Y.Z` tag in the monorepo, verified and contract-tested against the
  **released** core before anything is pushed, then split to the generated repository as `vX.Y.Z`.
  **The first release waited on the core, and that precondition is now met.** Release mode resolves
  `egl/utils` from Packagist exactly as a consumer would; until the core had a release, no version of
  this package could be published, and that was enforced rather than assumed. Since #170 one pipeline
  serves every bridge — the tag names the package it publishes.
  When cutting a version, add its `## [X.Y.Z]` heading here: it is what the release gate anchors the
  tag to, since a Composer library carries no version constant of its own.

- **`Psr7Bridge`** (roadmap item **8.2**) — bidirectional conversion between the core's HTTP values
  and PSR-7 messages: `requestToPsr7()`, `requestFromPsr7()`, `responseToPsr7()`,
  `responseFromPsr7()`. PSR-17 factories are **injected at construction**, never discovered or
  defaulted.
  The full **T-B** contract suite implements spec 02 §4–§5 (**BFR-01…BFR-22**) and runs against
  **both** `nyholm/psr7` and `guzzlehttp/psr7` — 65 tests, 202 assertions. Each refusal and fidelity
  clause was probe-verified by planting the defect it claims to catch; all five plants failed on
  both implementations.
  Two clauses carry the weight, and both refuse rather than corrupt:
  a response bearing **multiple `Set-Cookie` headers** is refused, because PSR-7's own comma-joining
  reduction — right for every other header — produces a string no client can split back, cookie
  values containing commas of their own; and a **failed upload's stream is never touched**, its
  error code preserved verbatim, since PSR-7 permits `getStream()` to throw there.
  Requires the core's whole-collection readers (`queryAll()` and friends), added in the same change
  by ADR-0034.
- Package scaffold (roadmap item **8.1**): `composer.json`, PSR-4 roots, PHPStan configuration at
  max level, this changelog and the package README — the boundary specified by
  [`docs/specs/02_spec_psr7_bridge.md`](../../docs/specs/02_spec_psr7_bridge.md) §2.
  Publication to Packagist lands in item **8.3**.

### Notes

- **The core constraint is `egl/utils: ^1.0`**, and it was `^0.11` for part of this package's
  development. The core's API-freeze review cut its first release as `1.0.0` rather than `0.11.0`
  (**ADR-0059**), and a `0.x` caret does not reach across a major — `^0.11` means
  `>=0.11.0 <0.12.0` and would have missed the only release that exists. Caught in the core's
  release PR, before the first real install could fail on it. Recorded as a note rather than under
  *Changed*: nothing had been published yet for it to be a change **to**.
