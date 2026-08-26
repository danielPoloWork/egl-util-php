# Package Specification: `egl/utils-psr18-bridge`

> Commissioned by [ADR-0075](../adr/0075-a-psr18-client-over-httpclient-and-one-pipeline-for-every-bridge.md)
> (issue [#93](https://github.com/danielPoloWork/egl-util-php/issues/93)) to carry the PSR-18
> obligation the review board's Product Manager seat raised. Frozen contract under the same
> amendment discipline as [`01_spec_utils.md`](01_spec_utils.md) and
> [`02_spec_psr7_bridge.md`](02_spec_psr7_bridge.md): a diverging implementation updates this spec
> in the same PR, with a revision entry and a rationale.

**Revision r1** — 2026-08-26. See [Revision history](#revision-history).

## 1. Scope & non-goals

The package adapts `D4np\Utils\Http\HttpClient` to `Psr\Http\Client\ClientInterface`, so ecosystem
middleware and SDKs that consume PSR-18 can be given this library's client — with its pinned TLS
verification, per-phase timeout and wall-clock budget (ADR-0049) — without the consumer writing an
adapter.

Non-goals, each with the reason it is one:

- **No PSR-15.** Issue #93 offered "optionally a PSR-15 adapter for `Router` in the same or a
  sibling package". Out of scope here: PSR-15 is a *server* middleware contract and `Router` is a
  server concern, so bundling it would put two unrelated interop stories in one package and force
  every PSR-18 consumer to install both. If it is wanted it is its own package.
- **No PSR-7 conversion.** That is [`egl/utils-psr7-bridge`](02_spec_psr7_bridge.md)'s job, and this
  package does not depend on it — see §2.
- **No factory discovery.** Factories are injected. This package ships no default, falls back to
  nothing, and does not consult `php-http/discovery`.
- **No streaming.** Bodies are read to strings, as in the PSR-7 bridge: the core's `HttpResponse`
  holds a string body, so there is nothing to stream from.
- **No retry, no circuit breaking.** `Support\Retrier` (FR-49) already owns retry, and a PSR-18
  client that silently retried would violate the contract's own "send this request" reading.

## 2. Package boundary

Identical to spec 02 §2 in every structural respect, and asserted by the same test
(`BridgePackageBoundaryTest`, which is data-driven over both packages):

- name `egl/utils-psr18-bridge`; namespace `D4np\Utils\Bridge\Psr18\`;
- the core is required by a **released** constraint (`^1.0`), never `@dev`;
- the committed manifest carries **no `repositories` entry** — CI injects a path repository into the
  workspace only;
- the PHP floor equals the core's (`>=8.1`); the bridge never narrows it;
- `psr/http-client`, `psr/http-factory` and `psr/http-message` are the package's dependencies and
  must never appear in the core's (NFR-08).

**This package does not depend on `egl/utils-psr7-bridge`,** and the reason is a type distinction
rather than a preference. The PSR-7 bridge converts the *server* vocabulary — `Request` and
`Response`, what an application receives and emits. `HttpClient` speaks the *client* one and returns
`HttpResponse`, a different, read-only type. There is no conversion the two packages share, and
depending on one from the other would couple a consumer who wants an HTTP client to a server-side
conversion they never asked for.

## 3. API surface

```php
final class Psr18Client implements \Psr\Http\Client\ClientInterface
{
    public function __construct(
        HttpClient $client,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
    );

    public function sendRequest(RequestInterface $request): ResponseInterface;
}

final class RequestRefused  extends HttpException implements RequestExceptionInterface {}
final class TransportFailed extends HttpException implements NetworkExceptionInterface {}
```

Two PSR-17 factories, not five: this package builds responses and streams and never a server
request, a URI or an uploaded file.

## 4. Client contract

- **CFR-01** Every status the origin produced is returned as a response. A `4xx` or `5xx` is not an
  exception — which is both PSR-18's rule and what `HttpClient` already did (ADR-0049).
- **CFR-02** Redirects are not followed unless the injected `HttpClient` was constructed to follow
  them. The core's default is off, so the default composition is PSR-18-shaped; a consumer who
  turned them on has opted in and the bridge does not second-guess it.
- **CFR-03** The request's method, URI and body reach the transport unmodified. A seekable body is
  rewound before it is read, so a body already consumed by middleware is not sent empty.
- **CFR-04** An empty body is sent as *no* body. PSR-7 gives every request a stream whether or not
  anything was written to it; the core's signature distinguishes `null` from `''`, and a `GET`
  through this bridge must look like a `GET` through `HttpClient::get()`.
- **CFR-05** Request headers are comma-joined per RFC 7230 §3.2.2, which is correct for every header
  a *request* carries. The `Host` header is **not** forwarded: PSR-7 guarantees one matching the
  URI and the core derives its own, so forwarding would put two in play.
- **CFR-06** Response headers keep every value. `HttpResponse` holds `array<string, list<string>>`,
  so two `Set-Cookie` lines both survive — the case spec 02 §5 has to *refuse*, because the core's
  server-side `Response` holds one value per name. Same contract, opposite outcome, different type.

## 5. Failure contract

PSR-18 separates a malformed request from a network failure because **only the second is worth
retrying**. The core raises one exception type for both, so the split is made structurally:

- **CFR-07** Every request-shaped check runs **before** the request is sent — a missing host, a
  scheme other than `http`/`https`, and the core's own header-smuggling guard
  (`HttpClient::contextOptionsFor()`, which is public and pure). Any of these is a
  `RequestRefused` / `RequestExceptionInterface`, and nothing is sent.
- **CFR-08** Anything the core throws *after* those checks is a `TransportFailed` /
  `NetworkExceptionInterface`: the message was well-formed, so the failure is the network's.
- **CFR-09** Both exceptions also extend the core's `HttpException`, satisfying ADR-0004's
  `UtilsThrowable` and PSR-18's `ClientExceptionInterface` at once. Two audiences, two
  catch-hierarchies, both correct.
- **CFR-10** The bridge's scheme list is duplicated from `HttpClient`'s `private const` and **kept
  in step by a test** that asks the real core — the arrangement `QueryBuilder::LIKE_ESCAPE` has
  with `Sanitizer::LIKE_ESCAPE`. Duplicated at all because PSR-18 wants a scheme refusal classified
  as a *request* failure, and reading it out of an exception message would be worse.

## 6. Versioning & publication

Identical to spec 02 §6 and served by the **same** pipeline, generalised for this package
(ADR-0075): package-scoped tags `utils-psr18-bridge-vMAJOR.MINOR.PATCH` in the monorepo, translated
to plain `vMAJOR.MINOR.PATCH` on the generated split repository. `bridge_release_gate.py` derives
the package from the tag, so one workflow publishes every bridge and a third one needs no new
machinery.

The split repository is named by a **per-package** variable,
`BRIDGE_SPLIT_REPO_UTILS_PSR18_BRIDGE`, because one variable cannot name two repositories. The
token may be shared.

## 7. Test strategy

Suite **T-C**, mirroring T-B's method:

- every test runs against **every PSR-17 implementation installed**, and CI pins one per matrix
  cell (`nyholm/psr7`, `guzzlehttp/psr7`) — a contract proven against one vendor silently encodes
  that vendor's leniencies;
- the provider **throws** when none is installed, because an empty provider is a suite that passes
  without testing anything;
- **no network.** The core's `Transport` seam stands in for it, which is also what makes *which*
  exception was raised and *what* reached the wire assertable at all.

## Revision history

| Rev | Date | Change |
|-----|------|--------|
| r1 | 2026-08-26 | Frozen from [ADR-0075](../adr/0075-a-psr18-client-over-httpclient-and-one-pipeline-for-every-bridge.md), issue #93. Records one finding that changed the design during implementation: **both conformant PSR-7 vendors refuse to construct a request carrying a CRLF header value**, so CFR-07's header-smuggling branch is unreachable through a conformant implementation. It stays as defence in depth — PSR-18 hands the client *a* `RequestInterface` and guarantees nothing about who implemented it — and is asserted against a deliberately non-conformant test double rather than dropped as dead code. |
