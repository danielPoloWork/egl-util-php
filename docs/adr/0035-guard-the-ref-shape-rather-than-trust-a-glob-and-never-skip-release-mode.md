# ADR-0035: Guard the ref shape rather than trust a glob, and never skip release mode

- **Status:** Accepted
- **Date:** 2026-08-05
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 8.3 (closes Milestone 8) ·
  [`docs/specs/02_spec_psr7_bridge.md`](../specs/02_spec_psr7_bridge.md) §6 (the pipeline this
  implements, amended to r3 here) ·
  [ADR-0033](0033-bridge-source-in-the-monorepo-published-through-a-generated-split-repository.md)
  §3 and §5 (independent versioning; the authenticity chain) ·
  [ADR-0032](0032-verify-the-tag-before-drafting-and-let-packagist-pull.md) (the signature
  mechanism reused) · [ADR-0031](0031-run-the-bc-checker-outside-the-dependency-graph-and-gate-breaks-by-bump.md)
  (the skip-on-a-declared-condition precedent this one deliberately does **not** follow)

## Context

Item 8.3 builds the pipeline ADR-0033 §6 specified: verify a signed bridge tag, prove the package
installs and passes against the **released** core, split `packages/utils-psr7-bridge/` and push it
to the generated repository with a translated tag.

Two of its details could not be built as written, for opposite reasons — one because the plan was
unverifiable, one because the plan was verifiable and would have been wrong.

## Decision

### 1. Each workflow guards its own ref shape; the glob is not trusted

Spec 02 r1 said item 8.3 would *"verify with a real tag"* that the core's `tags: ["v*.*.*"]` filter
does not match `utils-psr7-bridge-v*`. That plan has two faults. Testing a glob means pushing a
throwaway tag to a public repository and deleting it — a side effect on the artifact this project
treats as uncorrectable. And a verified glob is verified only until GitHub changes its matcher; the
result would be a fact with an expiry date nobody is watching.

So both workflows refuse a ref that is not theirs, by name and first:

- `release.yml` requires `^v[0-9]+\.[0-9]+\.[0-9]+$` before any other step;
- `bridge-release.yml` requires `^utils-psr7-bridge-v(\d+)\.(\d+)\.(\d+)$`, in
  `tools/bridge_release_gate.py`.

This is **stronger** than what was planned, not a retreat from it: whatever the glob does, a tag
reaching the wrong workflow is refused rather than processed. The matcher's behaviour stops being a
dependency. Verified locally in both directions — each gate refuses the other's tag, and
`v0.7`/`v0.7.0-rc1` are refused too.

### 2. Release mode is a hard requirement and is never skipped

This project has a standing pattern, from lesson L-0010 and applied at ADR-0031 and item 8.1: when a
gate cannot run yet, skip it on a declared condition and self-enable later. It is the right shape
for a *check*.

It is the wrong shape here, and the distinction is worth stating because the precedent points the
other way. Release mode is not a check that happens to be unavailable — it is the **only** evidence
for the package's central published claim, that `egl/utils: ^0.7` resolves and works. Skipping it
does not defer a check; it **publishes an unverified package**. A skipped gate on a pull request
costs a later discovery. A skipped gate here costs a release that cannot be corrected in place.

So: no release, no publication. Today `egl/utils: ^0.7` resolves to nothing — the core has no tag —
and the pipeline therefore **cannot succeed at all**. That is correct, not an oversight, and the
failure says so in the words a maintainer needs: *cut the core release first*.

### 3. Prerequisites fail early and name themselves

The split repository and its push token are one-time maintainer actions. When they are missing the
job fails with what to configure and where, and says explicitly that the gates passed and only the
push is absent — because a tag has already been pushed by then, and "it failed" without "here is
what is missing" is a bad thing to hand someone mid-release.

### 4. A `workflow_dispatch` dry run

The pipeline can be run manually against an existing tag; it validates everything and pushes
nothing. Given how much of this machinery cannot be exercised until a real release exists (§ below),
a way to run the gates without cutting a version is worth its handful of lines.

### 5. The changelog anchors a bridge tag, because nothing else can

`release_gate.py` anchors a core tag to the `VERSION` constant. The bridge has no such constant —
a Composer library does not carry its own version, by design — so `bridge_release_gate.py` anchors
the tag to the `## [X.Y.Z]` heading in the package's own changelog, the one place its version is
written down. It also re-checks, on the exact tagged tree, the two manifest invariants
`BridgePackageBoundaryTest` checks on pull requests: no `repositories` entry, no `@dev` core
constraint. Duplication on purpose — that tree is the artifact that gets published.

## Alternatives Considered

- **Pushing a throwaway tag to verify the glob**, as spec r1 planned — rejected in §1: a side effect
  on a public repository to establish a fact with an expiry date, when an explicit guard makes the
  fact unnecessary.
- **Skipping release mode until the core ships** (the L-0010 shape) — rejected in §2. It is the
  right pattern for a check and the wrong one for the sole evidence behind a published claim.
- **Loosening the core constraint so release mode passes today** (`*`, or `>=0.0.0`) — rejected: it
  would make the pipeline green by making the package's claim meaningless.
- **Publishing with `GITHUB_TOKEN`** — not possible: it cannot write to another repository. A
  scoped token is required, which is why the split repository is a configured prerequisite rather
  than something this workflow can arrange for itself.
- **Signing the split repository's tags in CI** — rejected, consistent with ADR-0032: a CI-held key
  would make the pipeline, not the maintainer, the identity a release asserts. The split tags stay
  build artifacts of a verified signed source.

## Consequences

- `.github/workflows/bridge-release.yml`; `tools/bridge_release_gate.py`; an early ref-shape guard
  in `release.yml`. 36 action pins across four workflows verified upstream.
- **The gate is proven to fail before being trusted** (lesson L-0008), five cases with verified exit
  codes: a complete fixture passes and prints the translated version; a tag whose changelog heading
  is missing, a core tag reaching the bridge gate, a committed `repositories` entry and a `@dev`
  core constraint all exit 1. The core gate refuses a bridge tag symmetrically.
- Spec 02 → **r3**: §6's "verifies with a real tag" is replaced by the guard, and the release-mode
  requirement is stated as non-skippable.
- **Most of this pipeline has never run, and cannot until a core release exists.** The signature
  check, the release-mode install, the subtree split and the cross-repository push are all
  unexercised — the third item in a row (7.2, 7.3, now 8.3) shipping machinery whose first real run
  is its first real use. What *can* be tested away from a tag has been: the gate's five cases, both
  ref-shape guards, the YAML, the pins. Named here rather than implied to be fine.
- Milestone 8 closes. The bridge is written, contract-tested against two PSR-17 implementations, and
  ready to publish the moment the core has a version to depend on.
