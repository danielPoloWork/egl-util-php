# ADR-0002: Adopt the cross-language source layout

- **Status:** Accepted
- **Date:** 2026-01-01
- **Deciders:** Maintainer
- **Related:** ADR-0001, AGENTS.md §5

## Context

`egl-util-php` is one of a family of projects intended to share the same technical-
enterprise structure regardless of implementation language. Source trees vary widely by
language ecosystem (`src/`, flat package roots, `pkg/`, crate roots). Without a fixed shape,
sibling projects diverge and the agent's mental model has to be relearned per repo.

## Decision

We adopt a **Maven-style cross-language source tree**:

```text
src/main/php/d4np/utils/    # production sources
src/test/php/d4np/utils/    # test sources
src/bench/php/d4np/utils/   # benchmarks (where applicable)
```

For this repository `<lang>` = `php` and the namespace/package is `D4np\Utils`,
mirroring the path. Subdivision inside `utils/` is by **component**, not by file
type. This layout is **normative** for every sibling project; only the `<lang>` segment and
the language's native namespace idiom change.



## Alternatives Considered

- **The language's default flat layout.** Rejected — it optimizes for one ecosystem at the
  cost of cross-project consistency, which is the whole point of the series.
- **A bespoke per-project layout.** Rejected — defeats the goal of a reproducible enterprise
  structure that an agent can navigate identically everywhere.

## Consequences

- Build tooling is configured to treat `src/main/php/...` as the source root; some
  ecosystems need a small shim (e.g. a build manifest pointing at the nested path).
- The layout is enforceable: code outside the tree is a review failure, and changing the
  shape requires superseding this ADR.
- Consumers import the public surface via `use D4np\Utils\Dto\DataTransferObject;`.

## References

- AGENTS.md §5 (Source Tree & Cross-Language Layout).
