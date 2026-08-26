# ADR-0075: A PSR-18 client over HttpClient, and one pipeline for every bridge

- **Status:** Accepted
- **Date:** 2026-08-26
- **Deciders:** maintainer (`@danielPoloWork`, explicit override — see *Sequencing*), project
  architect (agent)
- **Related:** issue [#93](https://github.com/danielPoloWork/egl-util-php/issues/93) ·
  [ADR-0033](0033-bridge-source-in-the-monorepo-published-through-a-generated-split-repository.md)
  (the monorepo + split-publication pattern this repeats) ·
  [ADR-0035](0035-guard-the-ref-shape-rather-than-trust-a-glob-and-never-skip-release-mode.md)
  (the pipeline generalised here) ·
  [ADR-0049](0049-state-the-transport-policy-explicitly-and-bound-the-whole-request.md)
  (`HttpClient`'s policy, and why PSR-18 needed no translation) ·
  [ADR-0004](0004-root-the-exception-hierarchy-on-an-interface.md) (the second catch-hierarchy) ·
  [ADR-0025](0025-typed-http-wrappers-that-refuse-rather-than-coerce.md) (the header guard) ·
  spec **03** (new), spec 02 §2/§6/§7

## Context

`egl/utils-psr7-bridge` was the library's only interop connector. Ecosystem HTTP middleware and
SDKs consume `Psr\Http\Client\ClientInterface` (PSR-18), so a consumer wanting to hand them
`HttpClient` had to write the adapter themselves — and the adapter is not trivial: PSR-18 mandates
an exception taxonomy the core does not have.

### Sequencing — this ADR exists over a recorded deferral

Issue #93 was **deferred** during the Milestone 14 plan pass, on this reasoning:

> Building a second consumer of an unproven pipeline before the first one has run once is the wrong
> order: a failure would be ambiguous between the new package and the pipeline itself.
> **Revisit when** #120 has published the PSR-7 bridge once and the pipeline has a real run behind
> it.

**That condition is not met**, and it was checked rather than assumed before any code was written:
`bridge-release.yml` has **zero runs**, no `utils-psr7-bridge-v*` tag exists, the split repository
does not exist, and `egl/utils-psr7-bridge` returns **404** on Packagist. Issue #120 is open, and
its first acceptance criterion is explicitly the owner's one-time work.

The maintainer was shown that evidence and **chose to override the deferral** (2026-08-26). This
ADR records the override rather than quietly proceeding, because the deferral was itself a recorded
decision and the risk it named is unchanged: **the publication half of this work is still
unexercised.** What that risk is bounded by is stated in *Consequences*.

## Decision

**Ship `egl/utils-psr18-bridge` as a second package under ADR-0033's pattern, and generalise the
publication pipeline so one workflow serves every bridge rather than one workflow per bridge.**

### 1. It does not depend on the PSR-7 bridge, and that is a type distinction

The obvious assumption is that a PSR-18 adapter reuses the PSR-7 bridge. It cannot: that package
converts the **server** vocabulary — `Request` and `Response`, what an application receives and
emits — while `HttpClient` speaks the **client** one and returns `HttpResponse`, a different,
read-only type. There is no conversion the two share.

So the packages are independent, and a consumer who wants an HTTP client does not acquire a
server-side conversion they never asked for.

### 2. PSR-18's exception split is made structurally, not by reading messages

PSR-18 separates `RequestExceptionInterface` from `NetworkExceptionInterface` because **only the
second is worth retrying**. The core raises one `HttpClientException` for both a refused scheme and
a dead socket, so the bridge cannot classify after the fact without matching on message text —
which is exactly the kind of thing that breaks silently when a message is reworded.

Instead every request-shaped check runs **before** the send: a missing host, an unsupported scheme,
and the core's own header guard via `HttpClient::contextOptionsFor()`, which is public and pure
(ADR-0026's shape) so calling it costs nothing and sends nothing. Anything thrown after that point
is the network's, by construction.

The scheme list is duplicated from the core's `private const` and **kept in step by a test that
asks the real core** — the arrangement `QueryBuilder::LIKE_ESCAPE` already has with
`Sanitizer::LIKE_ESCAPE`, affordable for the same reason: the drift is caught rather than argued
about. Without it, the core narrowing its list would leave the bridge reporting a permanent refusal
as a retryable network failure.

### 3. Both exceptions satisfy both hierarchies

`RequestRefused` and `TransportFailed` extend the core's `HttpException` **and** implement PSR-18's
interfaces. ADR-0004 roots every exception this library throws on `UtilsThrowable` so a consumer has
one thing to catch; PSR-18 requires `ClientExceptionInterface` so ecosystem middleware has one thing
to catch. Those are different audiences, and satisfying only one makes the class wrong for the
other. Nothing is bent to do it: `HttpException` is not `final`, and PSR-18's contracts are
interfaces.

### 4. One pipeline, because the tag names the package

`bridge_release_gate.py`'s tag grammar was the literal `utils-psr7-bridge-v(\d+)...`. It now
captures the package: `^(utils-[a-z0-9]+-bridge)-v(\d+)\.(\d+)\.(\d+)$`, and prints it back with
`--print-package`, so `bridge-release.yml` splits whichever directory the tag named. The shape stays
strict, so a core `vX.Y.Z` tag still cannot match — the separation ADR-0035 refuses by name rather
than trusting a workflow glob.

The split repository becomes a **per-package** variable
(`BRIDGE_SPLIT_REPO_UTILS_PSR18_BRIDGE`), derived from the package name rather than listed, because
one variable cannot name two repositories. The token may be shared.

`BridgePackageBoundaryTest` and `ci.yml`'s contract job are likewise data-driven over the packages.
A third bridge is a row in a provider and a matrix entry, not a copied file — and a copied file is
how one of them quietly stops being checked.

## What implementation changed about the design

**Both conformant PSR-7 vendors refuse to construct a request carrying a CRLF header value** —
nyholm's *"Header values must be RFC 7230 compatible strings"*, guzzle's *"is not valid header
value"*. Measured, not assumed: the test that was written to prove the header-smuggling branch
*errored* on both vendors because the message could not be built.

That branch is therefore unreachable through a conformant implementation. It stays, because PSR-18
hands this client *a* `RequestInterface` and guarantees nothing about who implemented it — and it is
now asserted against a deliberately non-conformant double, with the finding written into the
docblock so the next reader does not mistake it for dead code and delete it.

## Alternatives Considered

- **Reuse `egl/utils-psr7-bridge` for the response conversion.** Rejected in §1: different types,
  nothing to reuse, and a dependency a PSR-18 consumer has no use for.
- **Put the PSR-18 client inside the existing PSR-7 bridge package.** Rejected: it would force
  `psr/http-client` on every consumer of the PSR-7 conversion, and the two have no shared code. It
  would also make the package's name a lie.
- **Ship the PSR-15 `Router` adapter in the same package**, as #93 offers. Rejected: PSR-15 is a
  server middleware contract and `Router` a server concern; bundling two unrelated interop stories
  makes both mandatory. Its own package if it is wanted.
- **Classify PSR-18's exceptions by matching the core's message text.** Rejected in §2 — it breaks
  the day a message is reworded, silently, and in the direction that turns a permanent refusal into
  an infinite retry.
- **Make `HttpClient::ALLOWED_SCHEMES` public instead of duplicating it.** Genuinely attractive and
  rejected on scope: it widens the *core's* frozen public surface (ADR-0059) to serve a bridge, and
  the duplication has an established, tested precedent in this repository. Worth revisiting if a
  third consumer needs it.
- **A second workflow, `bridge-release-psr18.yml`.** Rejected: two copies of an unexercised
  pipeline is two things to keep true, and #93's own acceptance criterion asks for reuse.
- **Wait for #120, per the deferral.** Not the agent's call to overrule — see *Sequencing*. The
  maintainer overrode it on the evidence.

## Consequences

- **The core's dependency surface is unchanged.** `psr/http-client` lives in the new package only;
  `BridgePackageBoundaryTest` asserts it never appears in the core's manifest.
- **The contract suite doubled the matrix**: `ci.yml`'s bridge job is now package × vendor, four
  legs, each installing the core from the working tree.
- **The publication risk the deferral named is unchanged, and is now larger by one package.**
  `bridge-release.yml` has still never run. What bounds it: the pipeline's gates all run *before*
  any push, `bridge_release_gate.py` now has a **15-case self-test** (it had none), and that test
  proves the case that matters most — that a tag publishes the package it names and cannot be
  satisfied by the other one's version. A wrong split cannot be taken back, so that is the failure
  worth having proved.
- **Publishing either bridge still needs the owner's one-time steps** (split repository, token,
  Packagist). Nothing here changes that, and #120 remains the item that closes it.
- **`bridge-release.yml`'s stale header comment is corrected in passing** — it asserted
  `egl/utils: ^0.7` "resolves to nothing today", false on both counts. That is issue #120's second
  acceptance criterion, fixed here because the paragraph was being rewritten around it rather than
  as scope creep.

## References

- Issue [#93](https://github.com/danielPoloWork/egl-util-php/issues/93) and its deferral comment.
- [PSR-18](https://www.php-fig.org/psr/psr-18/) — the client contract, its exception taxonomy, and
  the redirect and error-status rules `HttpClient` already satisfied.
- Spec **03** — the frozen package contract, CFR-01…CFR-10.
- `packages/utils-psr18-bridge/src/test/.../Psr18ClientTest.php` (T-C, 28 tests × 2 vendors) and
  `tools/tests/verify_bridge_release_gate.py` (15 cases).
