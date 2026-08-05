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

- Package scaffold (roadmap item **8.1**): `composer.json`, PSR-4 roots, PHPStan configuration at
  max level, this changelog and the package README — the boundary specified by
  [`docs/specs/02_spec_psr7_bridge.md`](../../docs/specs/02_spec_psr7_bridge.md) §2.
  No converters yet: they land with their contract suite in item **8.2**, and publication in **8.3**.
