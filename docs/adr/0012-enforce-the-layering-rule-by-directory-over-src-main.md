# ADR-0012: Enforce RFC-0001's layering rule by directory, over `src/main` only

- **Status:** Accepted
- **Date:** 2026-08-04
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 3.6 · [RFC-0001](../rfc/0001-egl-utils-library.md) §Decision, §Placement
  notes (A-9) · [ADR-0003](0003-pin-ci-actions-by-commit-sha.md) and
  [ADR-0007](0007-measure-total-line-coverage-against-a-floor.md) (the other two CI gates) ·
  [ADR-0006](0006-shared-reflection-metadata-cache.md),
  [ADR-0010](0010-collection-generics-by-attribute.md) (both designed around the rule this
  now enforces)

## Context

RFC-0001 states the library's one structural rule — *"groups depend downward on Support only; no
cross-group imports"* — and names its enforcement in the same sentence: *"enforced by `deptrac`
in CI"*. Nothing enforced it. The CI `layering` job has been self-skipping on the absence of
`deptrac.yaml` since item 1.9, so the rule has been a prose claim for three milestones.

Until item 3.1 there was only one group, and the rule was vacuous. It is not vacuous now: `Dto`
depends on `Support`, which is a real edge with a real direction, and two prior ADRs already had
to *design around* this rule without a machine checking their work —

- **ADR-0006** kept `ParameterMetadata::$attributes` as uninterpreted `list<object>` specifically
  so `Support` would not have to name a `Dto` type.
- **ADR-0010** re-affirmed that, rejecting "name the `CollectionOf` attribute inside `Support`"
  as *"it inverts the dependency RFC-0001 fixes"*.

Both were correct, and both were held in place by review attention alone. The next four
milestones each add a group that could violate the rule.

## Decision

**Ship `deptrac.yaml` declaring one layer per RFC-0001 group plus `Support` and `Version`,
collected by `directory`, analysed over `src/main` only.** The CI `layering` job self-enables on
the file's presence and fails the build on any violation.

Three choices inside that are worth recording:

- **Layers are collected by `directory`, not by a `classLike` name regex.** RFC-0001 §A-9 already
  binds the grouping to the source tree — *"the deptrac layer globs are defined against that exact
  tree, with the group directories (`Dto/`, `Container/`, …) case-exact below it"*. A name-pattern
  collector would restate the same grouping in a second, independently-driftable vocabulary; a
  class could then match a layer it does not live in, or live in a directory no layer claims.
- **Only `src/main` is analysed; tests and benchmarks are out of scope.** The rule constrains the
  *library's* architecture. A test legitimately reaches across groups (a `Dto` test asserts on
  `Support` exceptions; `HydrationBench` hydrates a DTO), and holding test code to a production
  layering rule would report violations that are not violations — which trains readers to ignore
  the gate, the failure mode a gate exists to avoid.
- **`Version` gets its own layer rather than being left uncollected.** It is a lone version
  constant at the namespace root, in no group. Declaring it makes *"every production class belongs
  to a declared layer"* a fact the config asserts and deptrac confirms (`Uncovered: 0`) rather than
  an assumption nobody checks.

**`Support: ~` — the empty ruleset — is the load-bearing half.** It is what makes a `Support → Dto`
import a build failure instead of a code-review opinion, and it is precisely the constraint
ADR-0006 and ADR-0010 were manually respecting.

`deptrac/deptrac` is pinned at `^4.4`, not the current `^4.7`: 4.7 requires PHP `^8.2`, and
`config.platform.php: 8.1.34` (item 1.3) correctly refused to resolve it. That is the platform
pin doing its job — the library supports 8.1, so its tooling must run there.

## Alternatives Considered

- **`classLike` / `classNameRegex` collectors** — rejected above: a second source of truth for a
  grouping RFC-0001 already binds to the directory tree.
- **Including `src/test` and `src/bench` in the analysed paths**, with tests as their own layer
  allowed to depend on everything — rejected as ceremony that buys nothing: such a layer forbids
  no edge that matters, while adding config that must be maintained as groups are added.
- **Leaving `Version` uncollected** — rejected: deptrac would report it as uncovered, and the
  honest choices are then to declare it or to suppress the report. Declaring it is cheaper and
  says something true.
- **Relying on PHPStan or code review instead of a dedicated tool** — rejected: PHPStan checks
  types, not architecture, and review is exactly what ADR-0006/ADR-0010 already depended on. RFC-
  0001 named deptrac specifically.
- **Adding the rule but not proving it can fail** — rejected on this project's standing practice:
  a gate that has never been observed failing is indistinguishable from a gate that cannot fail.

## Consequences

- The rule RFC-0001 stated is now mechanical. Verified in all three directions before landing,
  each probe reverted and the tree confirmed byte-identical afterward:
  1. `Support → Dto` (the inversion ADR-0006/ADR-0010 designed around) — **1 violation, exit 1**,
     naming the file and line.
  2. `Http → Dto` (a peer cross-group import) — **1 violation**.
  3. `Http → Support` (the allowed downward direction) — **0 violations**, allowed edges 33 → 34,
     confirming the gate distinguishes rather than simply rejecting.
- Current state: 0 violations, 0 uncovered, 33 allowed dependencies.
- Adding a group to another group's `ruleset` list is now an explicit, reviewable act — which is
  the point. A future genuine need to cross groups has to argue for itself in a diff to this file
  rather than arriving unnoticed inside a feature PR.
- The `layering` CI job runs for real from this commit, so it now costs a composer install and an
  analysis on every push, and can block a merge.
- deptrac's `directory` collector compiles case-**insensitively** (`#…#i`), so this file is not
  what enforces RFC-0001's "case-exact" wording. `tools/consistency_lint.py` owns that, and PSR-4
  autoloading fails loudly on a case mismatch long before deptrac runs — noted so a reader does
  not credit this gate with a guarantee it does not provide.

## References

- RFC-0001 §Decision (the rule and its named enforcement), §Placement notes A-9 (layer globs bound
  to the source tree)
- ADR-0006, ADR-0010 — the two decisions that manually respected this rule before it was enforced
- `vendor/deptrac/deptrac/src/DefaultBehavior/Layer/DirectoryCollector.php` (case-insensitive
  regex over the normalized path)
- `vendor/deptrac/deptrac/src/Contract/Config/CollectorType.php` (the collector vocabulary)
