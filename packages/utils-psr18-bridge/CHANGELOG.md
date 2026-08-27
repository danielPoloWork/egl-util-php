# Changelog — `egl/utils-psr18-bridge`

All notable changes to this package, following
[Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning 2.0.0](https://semver.org/).

**This package versions independently of `egl/utils`** (ADR-0033 §3). Its releases are cut from
package-scoped tags in the monorepo — `utils-psr18-bridge-vMAJOR.MINOR.PATCH` — which the
publication pipeline translates into plain `vMAJOR.MINOR.PATCH` tags on the generated split
repository. A core release does not imply a bridge release, or the reverse.

## [Unreleased]

**This package has no released version, and that is now a decision rather than a pending step**
— issue **#120**, closed *as not planned* on 2026-08-27, for the reason its sibling records:
publishing a bridge needs a credential able to push to a generated split repository, and the
maintainer has decided not to hold one. The package stays monorepo-only — specified,
contract-tested on every pull request, usable by anything consuming this repository directly, not
installable with `composer require`.

A `0.1.0` version heading was briefly added here to anchor a tag that is now not being cut, and has
been folded back: a versioned heading means *released*, and nothing was.

**What was proved before the decision.** Release mode was exercised without a tag — the package
copied out of the monorepo, installed resolving `egl/utils` from Packagist as a consumer would, and
its contract suite run against that install. **28 tests, 72 assertions, green against
`egl/utils v1.0.0`** (the only core version Packagist serves; `v1.1.0`'s publication never
completed — issues #115, #105).

Independence is not affected by any of this: this package **does not require the PSR-7 bridge**
(ADR-0075), so the two were only ever going to be published in one round as a convenience of
sequencing, never as a dependency.

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
