# Architecture Decision Records

One numbered Markdown file per decision, in the lightweight
[Michael Nygard](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions)
format. Numbering is sequential and never reused or renumbered. Template:
[`template.md`](template.md).

Open an ADR when a choice affects the public surface or compatibility, when two reasonable
options exist and the rationale is non-obvious, when a **design pattern** is adopted, or
when superseding a prior decision. Do **not** open one for routine implementation details
or trivially reversible choices.

Status transitions: `Proposed` → `Accepted` → (`Superseded by ADR-XXXX` | `Deprecated`).

## Index

| ADR | Title | Status |
|-----|-------|--------|
| [0001](0001-record-architecture-decisions.md) | Record architecture decisions | Accepted |
| [0002](0002-adopt-cross-language-source-layout.md) | Adopt the cross-language source layout | Accepted |
| [0003](0003-pin-ci-actions-by-commit-sha.md) | Pin CI actions by commit SHA | Accepted |
| [0004](0004-root-the-exception-hierarchy-on-an-interface.md) | Root the exception hierarchy on an interface, and close its leaves | Accepted |
| [0005](0005-atomic-file-writes-with-a-sidecar-lock.md) | Atomic file writes, with the lock on a sidecar rather than the target | Accepted |
| [0006](0006-shared-reflection-metadata-cache.md) | Shape the shared reflection-metadata cache as plain instances, without an interface | Accepted |
| [0007](0007-measure-total-line-coverage-against-a-floor.md) | Enforce the coverage floor as total line coverage, measured once | Accepted |
| [0008](0008-dto-hydration-strictness-and-shared-hydrator.md) | Strict-by-default hydration, and how a static entry point reaches shared state | Accepted |
| [0009](0009-withers-rebuild-rather-than-clone.md) | Withers rebuild through the constructor rather than cloning | Accepted |
| [0010](0010-collection-generics-by-attribute.md) | Declare collection element types with an attribute, not a docblock parser | Accepted |
| [0011](0011-benchmark-scope-and-the-measured-hydration-ratio.md) | Benchmarks measure NFR-01/NFR-04 now; absolute regression tracking and the measured ~15× ratio gap are deliberately deferred | Accepted |
| [0012](0012-enforce-the-layering-rule-by-directory-over-src-main.md) | Enforce RFC-0001's layering rule by directory, over `src/main` only | Accepted |
| [0013](0013-compile-a-hydration-closure-for-the-scalar-shape.md) | Compile a hydration closure for the scalar shape, keep the interpreter for everything else | Accepted |
| [0014](0014-pin-pdo-defaults-on-a-consumer-owned-connection.md) | Pin PDO's safe defaults on a consumer-owned connection, and refuse one that will not take them | Accepted |
