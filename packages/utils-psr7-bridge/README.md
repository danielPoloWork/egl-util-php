# egl/utils-psr7-bridge

Bidirectional conversion between [`egl/utils`](https://github.com/danielPoloWork/egl-util-php)'
HTTP values (`D4np\Utils\Http\Request`, `Response`) and PSR-7 messages, using any PSR-17 factory.

> **Status: scaffold.** Roadmap item 8.1 laid down this package; the converters and their contract
> suite land in **8.2**, and publication to Packagist in **8.3**. The package is not yet installable
> standalone — see [Installing](#installing).

## Why this package exists

`egl/utils` ships `Request`/`Response` as typed, security-defaulted facades over superglobals, with
**no HTTP dependencies** — the target audience includes framework-less and legacy PHP applications
where superglobals are the reality (imported ADR-002).

PSR-7 plus PSR-15 middleware is how interoperable PHP HTTP code is written. Rather than force
stream semantics on someone who wants `$request->postString('email')`, or maintain a second PSR-7
implementation, the two vocabularies meet **here and only here**: this bridge is the sanctioned
crossing point, and the core never depends on it.

## Installing

```bash
composer require egl/utils-psr7-bridge
```

**Not yet possible.** This package requires `egl/utils: ^0.7`, and the core has not cut its first
release — `VERSION` is still `0.0.0` with no tag. That is a true statement of the dependency, not a
placeholder: the package becomes installable when the core publishes `v0.7.0`, which is also when
item 8.3 first publishes this one. Until then it is developed and tested inside the monorepo, where
CI resolves the core from the working tree (spec 02 §7).

You also need a PSR-17 factory implementation — any of them:

```bash
composer require nyholm/psr7        # or guzzlehttp/psr7, or your framework's
```

## Usage

The converters arrive in item 8.2. The shape is fixed by
[`docs/specs/02_spec_psr7_bridge.md`](../../docs/specs/02_spec_psr7_bridge.md) §3: factories are
**injected once at construction** — the bridge never discovers or defaults one — and the four
conversions are `requestToPsr7`, `requestFromPsr7`, `responseToPsr7`, `responseFromPsr7`.

Note the deviation from imported ADR-002's literal `Request::toPsr7()`: PHP has no partial classes,
so methods on the core `Request` would put PSR interfaces in the *core's* signatures and
requirements, which its dependency policy (NFR-08) forbids. The bridge owns the converters.

## Conversion contract

Fidelity is specified as numbered, testable clauses — **BFR-01…BFR-22** in
[spec 02 §4–§5](../../docs/specs/02_spec_psr7_bridge.md) — and each is tested against **two**
PSR-17 implementations, because one would silently encode that vendor's leniencies.

Two clauses are worth knowing before you use the bridge, because both are places where a
reasonable-looking implementation is wrong:

- **A response bearing multiple `Set-Cookie` headers is refused, not comma-joined.** PSR-7's own
  `getHeaderLine()` reduction is correct for every other header and corrupts this one: RFC 6265
  cookie strings contain commas (`Expires=Wed, 21 Oct …`), so joining them produces something no
  client can parse back. The core's header projection is single-valued, so the bridge refuses
  rather than mangling.
- **A failed upload's stream is never opened.** Where `error !== UPLOAD_ERR_OK`, the error code is
  preserved verbatim and no read is attempted — PSR-7 permits `getStream()` to throw there, and
  there is nothing valid to read anyway.

Refusals throw `D4np\Utils\Support\HttpException` naming what was seen, carrying the core's
refuse-don't-coerce semantics (ADR-0025) across the boundary unchanged.

## Development

This package's canonical source lives in the `egl-util-php` **monorepo** under
`packages/utils-psr7-bridge/` (**ADR-0033**). If you are reading this in the standalone
`egl/utils-psr7-bridge` repository, that repository is **generated and read-only**: it exists as
the Packagist publication target and accepts no commits or pull requests. Contribute in the
monorepo.

## Licence

MIT, as the core.
