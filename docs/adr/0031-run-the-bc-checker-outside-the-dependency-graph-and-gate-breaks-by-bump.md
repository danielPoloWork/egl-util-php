# ADR-0031: Run the BC checker outside this package's dependency graph, and gate breaks by the bump they arrive in

- **Status:** Accepted
- **Date:** 2026-08-05
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 7.2 · spec **NFR-07**, NFR-08 (dependency policy) · imported spec §8
  (the BC policy and the deprecation window this resolves) ·
  [RFC-0001](../rfc/0001-egl-utils-library.md) (versioning) ·
  [ADR-0030](0030-same-runner-ab-because-a-stored-baseline-cannot-carry-a-10-percent-gate.md) (the
  sibling gates, and the same absence-is-failure discipline) ·
  [ADR-0003](0003-pin-ci-actions-by-commit-sha.md) · `docs/workflow/maintenance.md`,
  `docs/workflow/release.md`

## Context

NFR-07 requires *"`roave/backward-compatibility-check` on release PRs"*. Three things stood in the
way, and each was found by trying rather than by reading.

**The tool cannot be a dependency of this package.** Every release from 8.7.0 requires PHP `~8.2`,
later `~8.3` and then `~8.4`. This library supports **PHP 8.1** — that is why
`config.platform.php` is pinned to `8.1.34` and why the CI matrix has an 8.1 cell. A `composer
require --dev` resolves to 8.6.0 at best, or breaks the 8.1 cell, or forces abandoning the 8.1
promise. The dry run states it plainly:

```
roave/backward-compatibility-check[8.12.0, ..., 8.14.0] require php ~8.2.0 || ~8.3.0 || ~8.4.0
  -> your php version (8.1.34; overridden via config.platform) does not satisfy that requirement.
```

**There is no PHAR any more.** The obvious escape — run it as a self-contained binary — is gone:
`gh release view` on the upstream repository shows **no assets** on recent tags.

**It hard-fails on a repository with no tags.** Verified directly:

```
In invariant_violation.php line 16:
  Could not detect any released versions for the given repository
```

This repository has **no tags** (`git tag -l` is empty, `VERSION = '0.0.0'`). The first release PR
is precisely when the check would first run, so a naive wiring would fail the very PR it exists to
protect.

## Decision

### 1. The checker is installed into a throwaway project, not into `composer.json`

`composer require --working-dir="$RUNNER_TEMP/bc-tool"` on PHP 8.3. The tool analyses this
package's source from outside, so its own PHP floor need not be this package's floor — and keeping
it out of the lock file means the 8.1 matrix cell, the `--prefer-lowest` job and NFR-08's dependency
policy are all untouched.

Pinned to `^8.16` rather than left floating: a release gate whose own findings change between runs
is a gate nobody can review.

### 2. The gate skips on a declared condition and self-enables at the first tag

No `v*.*.*` tag means nothing to compare against, so the job emits a notice saying so and passes.
This is lesson **L-0010** applied verbatim — *make it SKIP on a declared condition rather than fail,
and drive that condition from data so the guard self-disables the moment the item lands.* The moment
the first tag exists the check runs, with no edit to the workflow.

### 3. A release PR is detected from `Version.php`, not from a label

Step 1 of `docs/workflow/release.md` is bumping the `VERSION` constant, so the PR's diff against its
base is a mechanical, unforgeable signal. A label would work until someone forgot it, and a gate that
silently does not run is worse than no gate — the failure mode this project has already had to go
back and fix twice.

### 4. Breaks are gated **by the bump they arrive in**, which the checker cannot do

This is the substance of the item. `roave/backward-compatibility-check` answers *"are there
backward-incompatible changes since the last release?"* It cannot answer the question that actually
gates a release: *"are those breaks allowed in **this** bump?"*

Those differ, and pre-1.0 they differ sharply — SemVer 2.0.0 §4 says that under `0.y.z` anything may
change, so a break in `0.7 -> 0.8` is not a violation while the identical break in `0.7.0 -> 0.7.1`
is. `tools/bc_gate.py` encodes the rule:

| previous | bump | breaks | verdict |
|---|---|---|---|
| any | PATCH | yes | **FAIL** — a PATCH promises nothing changed |
| `0.y.z` | MINOR | yes | pass — SemVer §4; pre-1.0 MINOR is this project's declared vehicle |
| `>= 1.0.0` | MINOR | yes | **FAIL** — post-1.0 a MINOR must be additive |
| any | MAJOR | yes | pass — a MAJOR is what a break is for |
| any | any | no | pass |

**A permitted break is never a silent one.** Every passing case still prints what was permitted and
why, because "the gate passed" and "there were no breaks" are different facts, and a release note
that conflates them is how a consumer gets surprised.

### 5. The deprecation window is **one full published MINOR**, and the contradiction is resolved

`docs/workflow/maintenance.md` said deprecations are kept *"for at least the rest of the current
MAJOR line"*. The imported spec §8 says they *"live one minor before removal"*. Both were in the
repository, contradicting each other, and nobody noticed until the item that had to implement them.

The spec is the contract, so the spec wins — and the reconciliation matters, because removing a
public symbol *is* a BC break and therefore cannot land in any bump that forbids one. Stated
precisely:

1. Deprecate in a MINOR; the symbol keeps working.
2. **One full published MINOR** must ship with it present and deprecated. The window is measured in
   released versions, not in time or commits: deprecating and removing inside one release gives no
   consumer a chance to see the warning, whatever the numbers say.
3. Remove in a bump that permits a break — MAJOR after 1.0, MINOR while on `0.y.z`.

Pre-1.0 that reads: deprecate in `0.N`, remove no earlier than `0.N+2`. SemVer's relaxation under
`0.y.z` is about *which bump may carry a break*, not about whether consumers are warned first.

## Alternatives Considered

- **`composer require --dev roave/backward-compatibility-check`** — rejected in §1: its PHP floor is
  above this library's, so it would break the 8.1 matrix cell or the 8.1 promise.
- **Pinning `^8.6`, the last version supporting PHP 8.1** — possible, and rejected: it freezes the
  gate on an old analyser to satisfy a constraint that only exists because of where it was installed.
  Moving the install solves the constraint instead of accommodating it.
- **Raising the platform pin to 8.2** — rejected outright: the spec says PHP 8.1+, and a tooling
  convenience is not a reason to drop a supported runtime.
- **A PHAR** — unavailable (§ Context); upstream ships no release assets.
- **A label to mark release PRs** — rejected in §3: a gate that depends on being remembered.
- **Running the checker on every PR** — rejected: pre-1.0 it would report breaks that are legal by
  §4 on most PRs, and a gate that is routinely and correctly ignored stops being read at all. NFR-07
  places it on release PRs; that is also where it is actionable, since the remedy is the bump.
- **Failing on any break at all** — rejected in §4: it would force a premature 1.0.0 to express a
  change that `0.y` exists to permit.
- **Leaving `maintenance.md` as it stood** — rejected in §5. Two documents disagreeing about the
  deprecation window is worse than either rule, because the answer depends on which one a
  maintainer happens to read.

## Consequences

- New job `quality / backward compatibility` in `ci.yml`, and `tools/bc_gate.py`.
- **The gate is proven to fail before being trusted** (lesson L-0008), ten cases with verified exit
  codes: PATCH+breaks, post-1.0 MINOR+breaks, no bump, a decreasing version, an unparseable version
  and a non-integer exit code all exit 1; pre-1.0 MINOR+breaks, MAJOR+breaks, `0.x -> 1.0` and
  PATCH-without-breaks all exit 0.
- **Release-PR detection proven in both directions**: unchanged `Version.php` is not a release PR; a
  committed bump is detected, with the version read back correctly.
- Until the first `v*.*.*` tag the job emits a notice and passes. That state is visible in CI rather
  than implied by a green tick.
- `docs/workflow/maintenance.md`'s deprecation section is rewritten, and gains the pre-1.0 case it
  did not previously have.
- 30 action pins across three workflows verified upstream (ADR-0003).

## References

- Imported spec §8 — *"`roave/backward-compatibility-check` against the previous tag on every
  release PR; deprecations live one minor before removal"*
- SemVer 2.0.0 §4 — major version zero, and what it does and does not permit
- Verified against `roave/backward-compatibility-check` 8.x: the PHP constraints above, the absence
  of release assets, and the no-tags failure mode
