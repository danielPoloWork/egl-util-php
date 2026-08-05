# Package Specification: `egl/utils-psr7-bridge`

> Commissioned by [ADR-0033](../adr/0033-bridge-source-in-the-monorepo-published-through-a-generated-split-repository.md)
> (roadmap item 7.4) to carry imported [ADR-002](../../.specs/d4np_php_adr_002_http_psr7.md)'s
> conversion-contract obligation. Frozen contract for Milestone 8, under the same amendment
> discipline as [`01_spec_utils.md`](01_spec_utils.md): a diverging implementation updates this spec
> in the same PR, with a revision entry and a rationale.

**Revision r3** — 2026-08-05. See [Revision history](#revision-history).

## 1. Scope & non-goals

The bridge converts between this library's HTTP values (`D4np\Utils\Http\Request`, `Response`) and
PSR-7 messages, in both directions, using consumer-supplied PSR-17 factories. It is the **only
sanctioned crossing point** between the two vocabularies (imported ADR-002).

Non-goals, restated from the decisions that set them:

- **No middleware, routing, or emission.** The wrappers "must never grow middleware ambitions"
  (imported ADR-002); `Response::send()` is never called by the bridge, and PSR-15 concerns belong
  to the consumer's stack.
- **No `Session`/`CsrfToken` conversion.** PSR-7 has no session concept; those classes do not cross.
- **No streaming passthrough.** The bridge converts *values*: a PSR-7 body stream is read in full to
  a string, and a string becomes a fresh stream. A message too large to hold in memory is outside
  the lightweight tier this package serves (BFR-19 states the boundary).

## 2. Package boundary

Canonical source: **`packages/utils-psr7-bridge/`** in the `egl-util-php` repository. The directory
is a complete Composer package:

```
packages/utils-psr7-bridge/
├── composer.json            # name: egl/utils-psr7-bridge — no `repositories` entry, ever (§6)
├── CHANGELOG.md             # the bridge's own, Keep a Changelog format
├── README.md                # install, factory wiring, the two-vocabulary rationale
├── phpstan.neon.dist        # max level, analysing this package with the core loaded
└── src/
    ├── main/php/d4np/utils/bridge/psr7/   # PSR-4: D4np\Utils\Bridge\Psr7\
    └── test/php/d4np/utils/bridge/psr7/   # PSR-4 (dev): D4np\Utils\Bridge\Psr7\Tests\
```

The layout mirrors the monorepo's Maven-style convention (AGENTS.md §5) rather than inventing a
second one. The namespace nests under `D4np\Utils\` deliberately — Composer resolves the longest
PSR-4 prefix, so `D4np\Utils\Bridge\Psr7\` maps to this package while everything else under
`D4np\Utils\` stays the core's; consumers read one vendor namespace.

`composer.json` contract:

| field | value | why |
|---|---|---|
| `name` | `egl/utils-psr7-bridge` | RFC-0001's naming mapping of imported ADR-002's `d4np/php-psr7-bridge` |
| `require.php` | `>=8.1` | the core's floor; the bridge must not narrow it |
| `require.egl/utils` | the released core line (e.g. `^0.7`) | proven in **release mode**, §6 — never `@dev` in the committed file |
| `require.psr/http-message` | `^2.0` | the return-typed release; every maintained implementation supports it, and dual `^1.1 \|\| ^2.0` support doubles the matrix for no consumer this library targets |
| `require.psr/http-factory` | `^1.0` | the factory interfaces the converter consumes |
| `require-dev` | `nyholm/psr7`, `guzzlehttp/psr7`, phpunit, phpstan | the two reference implementations §7's matrix runs against |

Dependency direction is one-way and mechanical: the bridge depends on the core's **public API**;
the core never references the bridge (when 8.1 lands, the core's deptrac gains a rule making a
core → `D4np\Utils\Bridge\` import a build failure, the same way `Support: ~` works today).

## 3. API surface

```php
final class Psr7Bridge
{
    public function __construct(
        ServerRequestFactoryInterface $serverRequestFactory,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        UploadedFileFactoryInterface $uploadedFileFactory,
        UriFactoryInterface $uriFactory,
    );

    public function requestToPsr7(Request $request): ServerRequestInterface;
    public function requestFromPsr7(ServerRequestInterface $message): Request;
    public function responseToPsr7(Response $response): ResponseInterface;
    public function responseFromPsr7(ResponseInterface $message): Response;
}
```

**This is a recorded deviation from imported ADR-002's letter**, which wrote `Request::toPsr7()` /
`Request::fromPsr7()`. PHP has no partial classes: methods on the core `Request` would live in the
core package, and their signatures would name PSR interfaces the core must not require (NFR-08).
The bridge therefore owns the converters; the factories arrive once, at construction — "using any
PSR-17 factory" (imported ADR-002) means *injected*, never discovered or defaulted by the bridge.

The core `Request` converts to **`ServerRequestInterface`** (not plain `RequestInterface`): it wraps
superglobals, which is precisely PSR-7's server-side request.

**Core API this depends on (added by [ADR-0034](../adr/0034-whole-collection-readers-on-request.md)).**
When item 8.2 implemented these clauses, four of them turned out not to be implementable: the core's
collection readers were all key-scoped, so a whole query, POST, cookie or uploaded-file collection
could not be *enumerated* at all. POST and `$_FILES` were recoverable from nothing else the class
exposed. `Request` therefore gained `queryAll()`, `postAll()`, `cookieAll()` and `uploadedFiles()` —
additively, with no BC break, and without weakening the typed accessors' refusal semantics, which
govern *scalar* reads and are untouched.

## 4. Conversion contract — requests

Each clause is a testable obligation; §7 maps them to the suite. "Refused" always means a
`D4np\Utils\Support\HttpException` naming what was seen — ADR-0025's refuse-don't-coerce semantics,
crossing the bridge intact.

### Core → PSR-7 (`requestToPsr7`)

- **BFR-01** — the method is preserved case-exactly (PSR-7 treats method as case-sensitive).
- **BFR-02** — the URI round-trips scheme, host, port, path and query; `isSecure()` ⇔ the produced
  URI's scheme is `https`.
- **BFR-03** — every header visible through `Request::headers()` appears on the message with its
  value; the core is single-valued per name, so each becomes a one-element PSR-7 header list.
- **BFR-04** — `getQueryParams()` equals the core's query projection, arrays included (what
  `queryList()` sees survives as an array).
- **BFR-05** — `getParsedBody()` equals the core's POST array.
- **BFR-06** — `getCookieParams()` equals the core's cookie projection.
- **BFR-07** — each file the core exposes becomes an `UploadedFileInterface` preserving size, error
  code (`UPLOAD_ERR_*`, verbatim), client filename and client media type. The stream is created from
  `tmp_name` through the injected `StreamFactoryInterface`; **when `error !== UPLOAD_ERR_OK` no
  stream over `tmp_name` is created at all** — PSR-7 permits `getStream()` to throw on a failed
  upload, and there is nothing valid to read. (r1 said the stream "lazily wraps" `tmp_name`;
  whether a factory opens eagerly is the implementation's business and neither vendor defers, so the
  clause states what the bridge controls.)
- **BFR-08** — **detachment**: mutating a superglobal after conversion does not change the produced
  message. The conversion is a snapshot, not a view.

### PSR-7 → core (`requestFromPsr7`)

- **BFR-09** — multi-valued headers reduce by PSR-7's own `getHeaderLine()` semantics (comma-join);
  the core's projection then shows that line. The reduction is the PSR-blessed one, not an
  invention.
- **BFR-10** — an **object** parsed body is refused: PSR-7 allows `array|object|null`, the core's
  POST projection is an array, and no lossless array projection of an arbitrary object exists. A
  `null` parsed body converts as an empty POST.
- **BFR-11** — uploaded files: for `error === UPLOAD_ERR_OK` the stream is materialized once to a
  private temporary path, byte-identical to the source; for any other error code the entry carries
  the code with **no stream access attempted**. An upload tree whose values are not flat
  `UploadedFileInterface` instances (PSR-7's nested normalization) is refused, naming the key —
  the core's `file(string $key)` surface is flat, and silently flattening would invent structure.
- **BFR-12** — query values pass through as-is; the *core's* typed accessors keep their own refusal
  semantics (ADR-0025) on read. The bridge transports; it does not pre-coerce.
- **BFR-08b** — detachment holds in this direction too: later `with*()` derivatives of the source
  message do not affect the produced `Request`, and no stream is shared between them.

## 5. Conversion contract — responses

### Core → PSR-7 (`responseToPsr7`)

- **BFR-13** — the status code is preserved; the reason phrase is the factory's default for that
  code (the core carries none).
- **BFR-14** — every core header appears on the message; single-valued per name, as in BFR-03.
- **BFR-15** — the body string becomes a stream readable in full from offset 0.
- **BFR-16** — conversion emits nothing: no headers are sent, `send()` is never invoked.

### PSR-7 → core (`responseFromPsr7`)

- **BFR-17** — the status code is preserved.
- **BFR-18** — multi-valued headers reduce per `getHeaderLine()` — **except `Set-Cookie`**. A
  message bearing **multiple** `Set-Cookie` headers is refused: RFC 6265 cookie strings contain
  commas (`Expires=Wed, 21 Oct…`), so the comma-join that is correct for every other header
  *corrupts* this one, and the core's single-valued header projection cannot carry the list. One
  `Set-Cookie` header passes through unchanged. This is the contract's sharpest edge and exists
  precisely because a naive implementation passes every other test while silently mangling cookies.
- **BFR-19** — the body stream is read in full: rewound first when seekable, read to end when not.
  The memory implication is accepted and stated (§1 non-goals): the bridge converts values.

### Round-trips

- **BFR-20** — core → PSR-7 → core preserves every core-observable projection of a `Request`.
- **BFR-21** — the same for `Response`, for messages that do not hit a refusal clause.
- **BFR-22** — an uploaded file's bytes survive the full round-trip identically.

## 6. Versioning & publication

- **Package-scoped tags** in the monorepo: `utils-psr7-bridge-vMAJOR.MINOR.PATCH`, starting at
  `utils-psr7-bridge-v0.1.0`, annotated and **signed** like every release tag (ADR-0032).
- The publication pipeline (item 8.3), on such a tag:
  1. **verifies the tag's signature** via GitHub's verification — ADR-0032's mechanism, reused;
  2. runs the contract suite in **release mode**: a clean install of the package with **no path
     repository**, resolving `egl/utils` from Packagist exactly as a consumer would — because the
     committed constraint is a claim about *released* core versions, and PR-mode evidence (working
     tree) does not support it;
  3. splits `packages/utils-psr7-bridge/` (e.g. `git subtree split`) and pushes to the split
     repository with the translated tag `vMAJOR.MINOR.PATCH`.
- The split repository is **read-only**: generated, no PRs, no authored commits; its README says so
  and links here. Its tags are unsigned build artifacts of a verified signed source — the
  authenticity assertion lives at the monorepo tag, per ADR-0033 §5.
- The committed `composer.json` **never contains a `repositories` entry**: a path repository
  pointing outside the package would break every standalone install of the split package. PR mode
  injects it in the CI workspace only (§7).
- Tag-grammar isolation: **each workflow guards its own ref shape** and refuses a tag that is not
  its own — `release.yml` requires `^v[0-9]+\.[0-9]+\.[0-9]+$`, `bridge_release_gate.py` requires
  `^utils-psr7-bridge-v\d+\.\d+\.\d+$`. r1 planned to verify GitHub's `tags:` glob with a throwaway
  tag; the guards are stronger and make the glob's behaviour irrelevant, so no such tag is pushed
  (ADR-0035 §1).
- **Release mode is never skipped.** It is the only evidence for the package's central published
  claim — that its core constraint resolves and works — so an unavailable release mode blocks
  publication rather than being deferred the way an unavailable *check* would be (ADR-0035 §2).
  Consequently no bridge version can be published until `egl/utils` has a release matching the
  committed constraint.
- One-time maintainer actions, mirroring `docs/workflow/release.md`'s prerequisites section: create
  the split repository, register it on Packagist.

## 7. Test strategy

- Suite lives in the package (`src/test/...`), group **`T-B`**; the frozen core spec §6's *"bridge
  conversion-fidelity contract tests in egl/utils-psr7-bridge CI"* resolves, under ADR-0033, to
  **this repository's CI** — the split repository has none.
- **Two-implementation matrix**: every clause runs against `nyholm/psr7` *and* `guzzlehttp/psr7`
  factories. One implementation would let the contract silently encode that vendor's leniencies;
  two is the cheapest diversity that catches it.
- **Every BFR clause maps to at least one named test**, and lands with the planted-defect discipline
  this project already holds: at implementation (8.2), each refusal and each fidelity clause is
  probed by planting the defect it claims to catch — the comma-joined `Set-Cookie`, the stream read
  on a failed upload, the shared stream that breaks BFR-08b — and observing the named test fail.
- **PR mode** (the `bridge-contract` CI job, self-enabling on the package's `composer.json`
  existing): inject the monorepo as a path repository in the CI workspace —
  `composer config repositories.monorepo path ../../ && composer require egl/utils @dev --no-update`
  — then install and run. Proves the working tree against the contract, which is the same-PR
  guarantee ADR-0033 chose the monorepo for.
- **Release mode** (pipeline-only, §6): no path repository; the released core. Both modes must pass
  for a bridge tag to publish.
- Static analysis: PHPStan max over the package in both modes; the core's deptrac gains the
  no-core→bridge rule (8.1).

## Settled-by-default

Small choices settled here so 8.2 does not relitigate them: URI construction goes through the
injected `UriFactoryInterface` from the core's `uri()` string; temporary files created by BFR-11 are
owned by the produced `Request`'s process lifetime (no destructor magic — PHP's request model
already reclaims them, and the bridge documents that CLI consumers clean up explicitly); no numeric
performance budget is declared for conversion — if one is ever wanted it follows NFR-06's
methodology rather than being invented here.

## Revision history

| Rev | Date | Change |
|-----|------|--------|
| r1 | 2026-08-05 | Commissioned by ADR-0033 (item 7.4): package boundary, independent versioning, publication pipeline, and imported ADR-002's conversion contract as BFR-01…BFR-22. |
| r2 | 2026-08-05 | Item 8.2, from implementing it. §3 records the four whole-collection readers `Request` gained (ADR-0034) — without them BFR-04–07 were not implementable, since every core collection reader was key-scoped and POST and `$_FILES` were recoverable from nothing else. BFR-07's wording corrected: r1 said the upload stream "lazily wraps" `tmp_name`, but eagerness is the PSR-17 factory's business and neither reference implementation defers, so the clause now states what the bridge controls — that no stream over `tmp_name` is created at all for a failed upload. |
| r3 | 2026-08-05 | Item 8.3, from building the pipeline. §6: tag-grammar isolation becomes an explicit **ref-shape guard in each workflow** rather than a throwaway tag verifying GitHub's glob — stronger, and it removes a side effect on a public repository (ADR-0035 §1). Release mode is stated as **never skipped**: it is the only evidence for the package's central published claim, so its unavailability blocks publication rather than deferring a check (ADR-0035 §2). |
