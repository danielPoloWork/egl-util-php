# egl/utils-psr7-bridge

Bidirectional conversion between [`egl/utils`](https://github.com/danielPoloWork/egl-util-php)'
HTTP values (`D4np\Utils\Http\Request`, `Response`) and PSR-7 messages, using any PSR-17 factory.

> **Status: implemented, contract-tested, and deliberately not published.** The converters and
> their **BFR-01…BFR-22** contract suite landed in item 8.2, and the publication pipeline in item
> 8.3. Publication itself was **decided against** on 2026-08-27 (issue
> [#120](https://github.com/danielPoloWork/egl-util-php/issues/120), closed as *not planned*), so
> this package is not installable standalone and is not going to become so — see
> [Installing](#installing) for what that does and does not mean.

## Why this package exists

`egl/utils` ships `Request`/`Response` as typed, security-defaulted facades over superglobals, with
**no HTTP dependencies** — the target audience includes framework-less and legacy PHP applications
where superglobals are the reality (imported ADR-002).

PSR-7 plus PSR-15 middleware is how interoperable PHP HTTP code is written. Rather than force
stream semantics on someone who wants `$request->postString('email')`, or maintain a second PSR-7
implementation, the two vocabularies meet **here and only here**: this bridge is the sanctioned
crossing point, and the core never depends on it.

## Installing

**This does not work, and will not:**

```bash
composer require egl/utils-psr7-bridge   # not published — see below
```

**Not a pending step — a decision.** Publishing a bridge means pushing to a generated split
repository, which requires a credential in the monorepo's secrets (`GITHUB_TOKEN` structurally
cannot write outside its own repository — the consequence of ADR-0033's split-publication design).
The maintainer has decided not to hold one, and issue #120 is closed *as not planned*.

**Use it from the monorepo.** The bridge is developed, contract-tested against two PSR-17 vendors on
every pull request, and fully usable by anything that consumes this repository directly — CI
resolves the core from the working tree (spec 02 §7). What you cannot do is install it on its own.

**Nothing else about it is unfinished.** The dependency constraint resolves (`egl/utils: ^1.0`
against the core's released `v1.0.0`), and release mode — the publication gate ADR-0035 §2 calls
the one that cannot be faked — was exercised by hand before the decision: this package installed
from Packagist exactly as a consumer would, **65 tests / 202 assertions green**. The pipeline is
proven up to the push; only the push is not happening.

This constraint has been corrected twice, both times in a release PR and both times because a
`0.x` caret is narrower than it looks. It was `^0.7` while the core was pre-release; when the
core's release was prepared as `0.11.0` that became `>=0.7.0 <0.8.0` against a version which would
never exist, so it was widened to `^0.11`. The API-freeze review then cut the core's first release
as **`1.0.0`** instead ([ADR-0059](../../docs/adr/0059-freeze-the-api-at-1-0-0-with-internal-symbols-outside-the-frozen-surface.md)),
and `^0.11` would have missed it for the same reason — corrected to `^1.0`, which is the first
constraint here that a SemVer-stable core actually widens over time.

You also need a PSR-17 factory implementation — any of them:

```bash
composer require nyholm/psr7        # or guzzlehttp/psr7, or your framework's
```

## Usage

The shape is fixed by
[`docs/specs/02_spec_psr7_bridge.md`](../../docs/specs/02_spec_psr7_bridge.md) §3: factories are
**injected once at construction** — the bridge never discovers or defaults one — and the four
conversions are `requestToPsr7`, `requestFromPsr7`, `responseToPsr7`, `responseFromPsr7`.

```php
use D4np\Utils\Bridge\Psr7\Psr7Bridge;
use D4np\Utils\Http\Request;
use D4np\Utils\Http\Response;
use Nyholm\Psr7\Factory\Psr17Factory;

// Psr7Bridge takes five PSR-17 factories: server-request, response, stream,
// uploaded-file, URI. Nyholm's Psr17Factory implements all five, so it is passed
// five times; with a vendor that splits them, pass each implementation in that order.
$psr17  = new Psr17Factory();
$bridge = new Psr7Bridge($psr17, $psr17, $psr17, $psr17, $psr17);

// Core → PSR-7: hand superglobal-backed values to a PSR-15 stack.
$psrRequest  = $bridge->requestToPsr7(Request::fromGlobals());
$psrResponse = $bridge->responseToPsr7(Response::json(['ok' => true]));

// PSR-7 → core: bring a stack's messages back into the core vocabulary.
$request  = $bridge->requestFromPsr7($psrRequest);
$response = $bridge->responseFromPsr7($psrResponse);
```

`requestFromPsr7()` and `responseFromPsr7()` throw `HttpException` rather than coercing when the
PSR-7 message carries something the core's vocabulary refuses — see *Conversion contract* below.

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
