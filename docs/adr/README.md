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
| [0015](0015-identifier-allowlist-closed-keywords-and-the-anchor-hole.md) | Refuse identifiers rather than escape them, close every keyword, and anchor the allowlist with `\z` | Accepted |
| [0016](0016-closure-scoped-transactions-with-savepoint-nesting.md) | Closure-scoped transactions, savepoint nesting, and keeping the original exception | Accepted |
| [0017](0017-prove-binding-at-the-pdo-boundary-and-defer-t02s-like-leg.md) | Prove binding at the PDO boundary, and ship T-02 with its LIKE leg openly deferred | Accepted |
| [0018](0018-querybuilder-benchmark-scope-and-the-measured-build-time-gap.md) | QueryBuilder's benchmark measures NFR-03 now; the ~23µs build-time gap is deliberately deferred | Accepted |
| [0019](0019-four-escaping-contexts-and-the-unquoted-attribute-assumption.md) | Four escaping contexts, no general `escape()`, and assume the attribute is unquoted | Accepted |
| [0020](0020-correct-the-nfr03-workload-and-resolve-the-driver-once.md) | Correct NFR-03's benchmarked workload, resolve the driver once, and defer the residual to the reference machine | Accepted |
| [0021](0021-delegate-rich-html-and-escape-like-wildcards-with-a-portable-character.md) | Delegate rich-HTML sanitization, and escape LIKE wildcards with a portable character | Accepted |
| [0022](0022-argon2id-by-default-with-a-fallback-decided-at-construction.md) | Argon2id by name, not by `PASSWORD_DEFAULT`, with the fallback decided at construction | Accepted |
| [0023](0023-snapshot-for-drift-invariants-for-safety-idempotence-for-mxss.md) | Snapshots catch drift, invariants catch wrong, and idempotence catches mutation XSS | Accepted |
| [0024](0024-assert-the-work-factor-not-the-wall-clock.md) | Assert the work factor, not the wall clock — NFR-05 split by what each half can actually prove | Accepted |
| [0025](0025-typed-http-wrappers-that-refuse-rather-than-coerce.md) | HTTP wrappers that mirror PSR-7's naming, and refuse rather than coerce | Accepted |
| [0026](0026-session-hardening-as-a-value-and-csrf-through-a-seam.md) | Session hardening as a value, CSRF through a seam, and a mechanism asserted because behaviour cannot see it | Accepted |
| [0027](0027-constant-time-comparison-is-asserted-by-mechanism-not-by-timing.md) | Constant-time comparison asserted by mechanism, not by timing — the spec amended on measured evidence | Accepted |
| [0028](0028-container-exceptions-live-in-the-container-group-and-get-carries-a-type.md) | Container exceptions in the Container group, a typed `get()`, and a benchmark that measured the harness | Accepted |
| [0029](0029-result-carries-a-throwable-and-production-withholds-the-message-too.md) | A `Result` failure carries a throwable, `map()` does not catch, and production withholds the message too | Accepted |
| [0030](0030-same-runner-ab-because-a-stored-baseline-cannot-carry-a-10-percent-gate.md) | A same-runner A/B, because a stored baseline cannot carry NFR-06's 10% gate — and all three deferred budgets are met | Accepted |
| [0031](0031-run-the-bc-checker-outside-the-dependency-graph-and-gate-breaks-by-bump.md) | Run the BC checker outside this package's dependency graph, gate breaks by the bump, and settle the deprecation window | Accepted |
| [0032](0032-verify-the-tag-before-drafting-and-let-packagist-pull.md) | Verify the tag before a draft exists, ask GitHub about the signature, and let Packagist pull | Accepted |
| [0033](0033-bridge-source-in-the-monorepo-published-through-a-generated-split-repository.md) | The bridge's source lives in this monorepo; a generated, read-only split repository is only its publication target | Accepted |
| [0034](0034-whole-collection-readers-on-request.md) | Whole-collection readers on `Request`, because a key-scoped reader cannot enumerate | Accepted |
| [0035](0035-guard-the-ref-shape-rather-than-trust-a-glob-and-never-skip-release-mode.md) | Guard the ref shape rather than trust a glob, and never skip release mode | Accepted |
| [0036](0036-refuse-the-downgrade-and-the-characters-parse-url-launders.md) | Refuse the scheme downgrade, and refuse the characters `parse_url()` launders | Accepted |
| [0037](0037-disable-phps-escape-character-and-keep-the-formula-guard-opt-in.md) | Disable PHP's CSV escape character, and keep the formula guard opt-in | Accepted |
