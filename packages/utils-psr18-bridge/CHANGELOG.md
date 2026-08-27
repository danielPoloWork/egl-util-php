# Changelog — `egl/utils-psr18-bridge`

All notable changes to this package, following
[Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning 2.0.0](https://semver.org/).

**This package versions independently of `egl/utils`** (ADR-0033 §3). Its releases are cut from
package-scoped tags in the monorepo — `utils-psr18-bridge-vMAJOR.MINOR.PATCH` — which the
publication pipeline translates into plain `vMAJOR.MINOR.PATCH` tags on the generated split
repository. A core release does not imply a bridge release, or the reverse.

## [Unreleased]

## [0.1.0] — 2026-08-27

The first published version of this package (issue #120), cut alongside
`egl/utils-psr7-bridge` `0.1.0` so the maintainer's one-time split-repository steps are done once
for two packages rather than twice. **`0.1.0` for the same reason the sibling used it**: the
surface is specified and contract-tested, but the pipeline shipping it had no run behind it when
this version was cut, and a `0.x` lets the first publication be corrected without spending a major.

**Release mode was exercised before the tag** — the package copied out of the monorepo, installed
resolving `egl/utils` from Packagist as a consumer would, and its contract suite run against that
install. **28 tests, 72 assertions, green against `egl/utils v1.0.0`** (the only core version
Packagist serves; `v1.1.0`'s publication never completed — issues #115, #105).

Independence is not just a claim here: this package **does not require the PSR-7 bridge**, so
publishing the two in one round is a convenience of sequencing, not a dependency. Each versions
independently from here on.

### Added

- **The package** (issue #93, **ADR-0075**). `Psr18Client` adapts `egl/utils`' `HttpClient` to
  `Psr\Http\Client\ClientInterface`, returning PSR-7 responses built by an injected PSR-17 factory.
  Contract suite (**T-C**) runs against `nyholm/psr7` and `guzzlehttp/psr7`, and touches no network:
  the core's `Transport` seam stands in for it.
- **`RequestRefused` and `TransportFailed`** — PSR-18's `RequestExceptionInterface` and
  `NetworkExceptionInterface`. The split exists because only a network failure is worth retrying,
  and the core raises one exception type for both, so the bridge classifies structurally: every
  request-shaped check runs *before* the call, and anything thrown after it is the network's.
  Both also extend the core's `HttpException`, satisfying PSR-18's catch-hierarchy and ADR-0004's
  `UtilsThrowable` at once.

### Notes

- **This package does not depend on `egl/utils-psr7-bridge`.** That one converts the *server*
  vocabulary (`Request`/`Response`); this one wraps the *client* (`HttpClient`/`HttpResponse`).
  Different types, no shared code, independently installable.
- **Multi-valued response headers survive**, including two `Set-Cookie` lines — the case the PSR-7
  bridge has to refuse. That refusal is a property of the core's server-side `Response`, which
  holds one value per name; `HttpResponse` keeps a list, so the same contract has the opposite
  outcome here.
