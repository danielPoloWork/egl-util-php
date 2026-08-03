# ADR-002: HTTP — native lightweight wrappers with optional PSR-7 bridge

| | |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-07-14 |
| **Related spec** | [d4np-php.md](d4np-php.md) (§2 items 13–15) |

## Context
v1 specified custom `Request`/`Response` wrappers over superglobals without addressing the ecosystem standard: PSR-7 (`psr/http-message`) plus PSR-15 middleware is how interoperable PHP HTTP code is written, with mature implementations (nyholm/psr7, guzzle/psr7). The question: are the custom wrappers a parallel universe (bad) or a deliberate lightweight tier with a bridge (defensible)?

## Options considered

**A. Native lightweight wrappers + optional PSR-7 bridge** *(chosen)*
- ✅ The target audience includes framework-less/legacy PHP apps where superglobals are the reality; a typed, security-defaulted reader over `$_GET/$_POST/$_FILES` (with the §2 item 15 session hardening) is immediate value with zero dependencies.
- ✅ Interop preserved: `Request::toPsr7()` / `Request::fromPsr7()` (and the Response equivalents) live in an optional bridge that depends on `psr/http-message` + `psr/http-factory`, letting the library's HTTP types cross into PSR-15 middleware stacks when the consumer has one.
- ✅ Immutability semantics stay simple (the wrappers are read-mostly facades), avoiding PSR-7's with-er cloning cost where it buys nothing.
- ❌ Two HTTP vocabularies exist. Contained: the bridge is the only sanctioned crossing point, and the wrappers deliberately mirror PSR-7 naming (`getQueryParams`-style) to keep the mental model aligned.

**B. Implement PSR-7 directly**
- ✅ One standard vocabulary.
- ❌ Implementing PSR-7 well (streams, immutability, uploaded-file abstraction) is a real project already solved by nyholm/guzzle; doing it again adds maintenance without differentiation, and forces stream semantics on users who just want `$request->postString('email')`.

**C. Depend on nyholm/psr7 and expose PSR-7 types only**
- ✅ Standards-pure.
- ❌ Framework-less users now need factory wiring and stream handling for a form read; the library's session/CSRF integration would still need helper facades — ending in the same wrapper layer plus a mandatory dependency.

## Decision
**Option A.** Core ships `Request`/`Response`/`Session` with no HTTP dependencies; the `d4np/php-psr7-bridge` optional package provides bidirectional conversion using any PSR-17 factory. Naming mirrors PSR-7 conventions; the spec documents the bridge as the integration path for middleware stacks.

## Consequences
- Framework-less consumers get typed, hardened HTTP handling out of the box; PSR-15 shops lose nothing.
- The wrappers must never grow middleware ambitions — routing/middleware requests are answered by "use a PSR-15 stack via the bridge" per this ADR.
- Bridge conversion fidelity (headers, uploaded files, immutability boundaries) gets its own contract tests in CI.
