# ADR-0059: Freeze the API at 1.0.0, with `@internal` symbols outside the frozen surface

- **Status:** Accepted
- **Date:** 2026-08-09
- **Deciders:** tech-lead (drafted), maintainer (decided — both questions below were put to
  them explicitly and answered before this record was written)
- **Related:** [ADR-0031](0031-run-the-bc-checker-outside-the-dependency-graph-and-gate-breaks-by-bump.md)
  (BC gate by bump) · [ADR-0032](0032-verify-the-tag-before-drafting-and-let-packagist-pull.md)
  (signed tags) · [ADR-0022](0022-argon2id-by-default-with-a-fallback-decided-at-construction.md)
  (`Hash::selectAlgorithm` seam) · [ROADMAP](../../ROADMAP.md) (the standing "post-M7 API-freeze
  review") · [maintenance.md](../workflow/maintenance.md) (the deprecation window this ADR arms)

## Context

`ROADMAP.md` has carried one standing commitment since the plan phase: *"the **1.0.0 decision**
is a dedicated post-M7 API-freeze review, not an automatic bump."* This is that review.

The preconditions are met and were checked rather than assumed: **every roadmap item across
M1–M12 is closed** (0 unchecked boxes), `CHANGELOG.md`'s `[Unreleased]` is empty, and CI is green
on the release commit. So 1.0.0 carries **no functional delta**. Its entire content is a promise:
that the surface listed in [spec §5](../specs/01_spec_utils.md) is now stable, and that
[`maintenance.md`](../workflow/maintenance.md)'s deprecation window — deprecate in a MINOR, ship
one full published MINOR deprecated, remove only in a MAJOR — starts binding.

That promise is expensive in exactly one direction, which is what makes this a review rather than
a bump: **pre-1.0, a break is free** (SemVer §4, and `tools/bc_gate.py` permits a break in a
pre-1.0 MINOR — this project has already used that latitude twice on `SqlStatement`, ADR-0039 and
ADR-0041). After 1.0.0 the same correction costs a MAJOR. Anything we would regret must be found
now or lived with.

The review surfaced one finding worth a decision.

### The finding: two public symbols documented `@internal`

PHP has no package-private visibility. Two symbols are therefore `public` for mechanical reasons
and `@internal` by intent:

| Symbol | Why it is public | Why it is `@internal` |
|---|---|---|
| `Security\SecretKey::bytes()` | `Crypto` needs the raw key material and lives in another class | exposing raw key bytes to consumers "would defeat the point of wrapping them" (its own docblock) |
| `Security\Hash::selectAlgorithm()` | extracted as a pure function so the refusal, the bcrypt selection and the WARNING level are assertable (ADR-0022) | "the availability argument has exactly one honest value in production, and that value is supplied by the constructor" |

`roave/backward-compatibility-check` reasons about **public symbols**, not docblocks. Frozen
naively, `SecretKey::bytes()` becomes part of the 1.x contract — a getter for raw key material
that we could never remove without a MAJOR, and whose existence in the frozen list invites the
use it was written to discourage.

## Decision

**Freeze the public API at 1.0.0**, with `@internal`-documented symbols **outside** the frozen
surface.

1. **What is frozen** — the surface enumerated in [spec §5](../specs/01_spec_utils.md): the PSR-4
   namespace `D4np\Utils\`, the public signatures of the nine groups, the exception hierarchy's
   shape, `DatabaseConnection`'s pinned-default semantics, and strict-mode hydration behavior.
   The bridge package versions on its own tag line (ADR-0033) and is not frozen by this ADR.
2. **What is not** — a symbol carrying `@internal` in its docblock is **not** part of the frozen
   surface. Removing or changing one is permitted in a MINOR. It is documented as internal at the
   point of definition, so a consumer calling it has been told.
3. **The mechanical consequence, stated rather than wished away.** The BC checker will still
   report such a removal as a break, because it reads visibility and not intent. When that day
   comes the gate is overridden **with a written reason naming this ADR** — a deliberate, visible,
   reviewed act, not a silent exclusion list that rots. `likely`: Roave offers no `@internal`
   suppression this project can configure from the throwaway-project install ADR-0031 uses; this
   was not proved, so the decision does not depend on it either way.
4. **1.0.0 supersedes the unpublished v0.11.0.** That version was tagged and never published —
   the tag was unsigned, `verify-tag` refused it (ADR-0032), no Release was drafted, Packagist
   had no such package. Its tag is deleted and its changelog re-cut as `v1.0.0`, so the first
   installable version is the one the release gate approved. No code differs.

## Alternatives

1. **Freeze `@internal` symbols as public API too** — the maximally honest reading ("if it is
   `public`, it is API"). Rejected by the maintainer: it permanently pins a raw-key-bytes getter
   into the 1.x contract to buy tooling convenience, and the docblock already tells consumers the
   truth. The cost lands on the wrong party — consumers keep a footgun so that maintainers avoid
   one future gate override.
2. **Restrict the two symbols before freezing** (the free-break window): fold the key bytes into a
   `Crypto`-facing seal, drop the test seam. Rejected as scope: it is code and test work inside a
   release PR that otherwise contains no code, and it would trade a documented, reviewed exception
   for a redesign of two working, tested classes on the last day of the free window — the worst
   moment to redesign a security type.
3. **Defer 1.0.0** and keep shipping 0.x. Rejected: every milestone is closed and the surface has
   been stable through twelve of them; staying pre-1.0 tells consumers "not ready" while the real
   state is "no planned changes", and it postpones the deprecation discipline that makes the
   library safe to depend on.
4. **Publish v0.11.0 first, freeze later.** Rejected with the maintainer: it requires either
   publishing from an unsigned tag — which is what ADR-0032 exists to prevent — or a signed
   re-tag of a version that would be superseded days later, leaving two first releases where one
   will do.

## Consequences

**Easier:** consumers get a SemVer promise with teeth, `composer require egl/utils:^1.0` is a
safe constraint, and the deprecation window becomes a real contract instead of documentation.
Post-1.0 the decision tree in `maintenance.md` answers version questions without a debate.

**Harder / accepted costs:** the free-break window closes — the two `SqlStatement` corrections
that pre-1.0 latitude allowed would now each cost a MAJOR. A future `@internal` removal requires a
written BC-gate override (point 3), which is friction by design. And the freeze is a claim about a
surface **no external consumer has yet exercised**: this library has never been installed from
Packagist, so 1.0.0 states confidence earned from tests, benchmarks and review rather than from
field use. That is the honest basis, and it is recorded here rather than implied by the version
number.
