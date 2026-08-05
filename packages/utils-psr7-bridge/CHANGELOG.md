# Changelog — `egl/utils-psr7-bridge`

All notable changes to this package, following
[Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning 2.0.0](https://semver.org/).

**This package versions independently of `egl/utils`** (ADR-0033 §3). Its releases are cut from
package-scoped tags in the monorepo — `utils-psr7-bridge-vMAJOR.MINOR.PATCH` — which the publication
pipeline translates into plain `vMAJOR.MINOR.PATCH` tags on the generated split repository. A core
release does not imply a bridge release, or the reverse.

## [Unreleased]

### Added

- Publication pipeline (roadmap item **8.3**, **ADR-0035**): this package is released from a signed
  `utils-psr7-bridge-vX.Y.Z` tag in the monorepo, verified and contract-tested against the
  **released** core before anything is pushed, then split to the generated repository as `vX.Y.Z`.
  **The first release waits on the core.** Release mode resolves `egl/utils` from Packagist exactly
  as a consumer would, and the core has no release yet — so no version of this package can be
  published until it does. That is enforced rather than assumed.
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
