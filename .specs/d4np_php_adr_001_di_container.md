# ADR-001: Ship a minimal PSR-11 container instead of depending on PHP-DI

| | |
|---|---|
| **Status** | Accepted |
| **Date** | 2026-07-14 |
| **Related spec** | [d4np-php.md](d4np-php.md) (§2 items 4–5, NFR-02) |

## Context
The library promises a "minimal DI container" (v1 item 4) without deciding the obvious question: why not depend on PHP-DI, Symfony DI, or league/container, all mature PSR-11 implementations? A utilities library imposing a full DI framework on consumers is heavy; shipping a naive container that silently underperforms mature ones is worse. The decision needed recording with its scope limits.

## Options considered

**A. Minimal custom PSR-11 container with explicit scope limits** *(chosen)*
- ✅ Zero dependencies for the core package (a design goal); consumers embedding the library in legacy/native projects get working DI with one class.
- ✅ **PSR-11 is the escape hatch by construction:** everything in the library consumes `Psr\Container\ContainerInterface`, so swapping in PHP-DI/Symfony DI is a one-line change — the custom container is a default, not a lock-in.
- ✅ Scope is deliberately small and documented: constructor autowiring via reflection (cached), singleton/factory definitions, `ServiceProvider` registration. **Non-goals stated:** no compilation, no attribute/annotation config, no lazy proxies, no circular-dependency resolution (throws with the dependency path).
- ❌ Will never match compiled-container performance for huge graphs. Bounded honestly by NFR-02 (≤ 30 µs first autowire, ≤ 2 µs warm) — adequate for the target use (small/medium service graphs).

**B. Depend on PHP-DI**
- ✅ Mature, feature-complete.
- ❌ Forces a framework-sized dependency on every consumer including those using the library *inside* Symfony/Laravel apps that already have a container — dependency-conflict surface for negative value.

**C. Suggest-only (no container shipped)**
- ✅ Cleanest dependency story.
- ❌ Guts item 4's promise; native-PHP consumers (the stated audience) lose the batteries-included experience.

## Decision
**Option A.** The container implements `Psr\Container\ContainerInterface`, documents its non-goals, and fails loudly where mature containers add features (circular deps, union-typed parameters without definitions). The spec's own components resolve through the interface, never the concrete class.

## Consequences
- NFR-02 keeps the container honest; if consumer graphs outgrow it, migration is interface-compatible by design.
- Container feature requests (compilation, attributes) are answered by this ADR: adopt a mature container instead — the library will not grow one.
- The reflection cache used by the container is shared infrastructure with the DTO hydrator (one metadata cache, spec §2 item 1).
