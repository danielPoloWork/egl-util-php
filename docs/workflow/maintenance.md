# Post-Release Maintenance Protocol

How `egl-util-php` is governed in the maintained-product phase (post-`v1.0.0`): how to
decide a release's SemVer level, how a fix reaches users, how security issues and
deprecations are handled. The mechanical release steps are in [`release.md`](release.md);
the agent-vs-human boundary is [`AGENTS.md`](../../AGENTS.md) §11.

## What the version number protects

The "public API" the version protects is the project's stable surface: the
public functions/types/endpoints and any documented compatibility guarantees (incl. ABI
where applicable), plus any user-visible configuration knobs and the
package/target name.

## Decision tree — which level?

1. **Does the change remove, rename, or alter the signature/semantics of any public symbol,
   knob, or target — such that existing consumers fail to compile/link or behave
   differently?** → **MAJOR.** Own ADR (justifying the break) + a migration note. Prefer the
   deprecation path below over an abrupt break.
2. **Does it add new public surface or an opt-in capability while every existing use keeps
   working?** → **MINOR.** (Closing a roadmap milestone is the canonical MINOR.) New
   capabilities are planned on the roadmap first — usually a new milestone.
3. **Otherwise** (bug fix, docs, packaging, perf, CI with no public-API change) → **PATCH.**

When ambiguous, treat it as the **higher** level — a wrongly-low version number breaks
consumers who trusted SemVer. Record the call in the release notes.

## Bug lifecycle

Defects are tracked in [`docs/bugs/`](../bugs/) (source of truth). A fix: (1) is recorded as
a `confirmed` ledger file; (2) has its SemVer level assessed; (3) lands through the hotfix
flow below — in the **same PR**, flip the record to `status: fixed`, set `fixed-in`, link
the PR, and add the `CHANGELOG` `Fixed` (or `Security`) line.

## Hotfix & backport

- **`master` is releasable** (common case): fix on a `fix/<name>` branch off
  `master`, add a test + `Fixed` changelog line, merge, cut the next PATCH.
- **`master` has unreleasable WIP**: branch `hotfix/v<X.Y.Z+1>` **from the
  released tag**, apply the minimal fix + test, cut the PATCH from that branch, then
  **forward-port** (cherry-pick) to `master`. The forward-port is mandatory.

A hotfix is always the smallest change that fixes the defect — no refactors ride along.

## Supported versions

[`SECURITY.md`](../../SECURITY.md) defers here for the window, and this is it. **Supported = the
latest release of the current MAJOR line.** A fix reaches consumers by their upgrading to that
release; older releases are not patched in place. Post-1.0 that upgrade is safe to take by
construction — within a MAJOR, SemVer plus the 1.x freeze
([ADR-0059](../adr/0059-freeze-the-api-at-1-0-0-with-internal-symbols-outside-the-frozen-surface.md))
forbid the break that would otherwise make "just upgrade" a real cost.

When a new MAJOR opens, the **previous** MAJOR's final release receives **security fixes only**
until the new line has shipped one full MINOR (`X+1.1.0`). The window is measured in *published
releases*, not in calendar time — the same reasoning the deprecation policy below uses, and for
the same reason: a window nobody shipped through gave no consumer a chance to move.

| Line | Bug fixes | Security fixes |
|---|---|---|
| latest release, current MAJOR | ✅ | ✅ |
| older releases, current MAJOR | ❌ | ❌ — upgrade within the MAJOR |
| previous MAJOR, final release | ❌ | ✅ until `X+1.1.0` ships |
| older MAJOR lines | ❌ | ❌ |

Two honest limits. This is a **solo-maintained** library: the table says which line a fix lands on,
not how fast it arrives. [`SECURITY.md`](../../SECURITY.md) § *Response-time targets* names numbers
sized to solo capacity (issue #104, **ADR-0080**, which narrows but does not reverse ADR-0060's
original stance) — but they are stated as **targets, not a guarantee**: a solo maintainer can commit
to effort, not to an outcome. And the previous-MAJOR row has **never been exercised**: `1.x` is the
only line that has ever existed, so that row is a commitment made in advance, not a described
practice. Recorded in **ADR-0060**.

## Security fixes

Report privately (see [`SECURITY.md`](../../SECURITY.md)); triage & fix under embargo;
coordinated release then advisory; record under a `Security` changelog entry with the
advisory/CVE; backport to every supported line — which the section above defines.

## Deprecation policy

The window is **one full published MINOR**, per the imported spec §8: *"deprecations live one minor
before removal."* An earlier version of this section said "at least the rest of the current MAJOR
line", which contradicted that; the spec is the contract and wins. Resolved in **ADR-0031**.

1. **Deprecate in a MINOR** — mark it deprecated in the API docs, add a `Deprecated` changelog line,
   and record the replacement in an ADR. **The symbol keeps working**, unchanged.
2. **Let one full MINOR ship with it deprecated.** The window is measured in *published releases*,
   not in time or commits: a symbol deprecated and removed inside the same release gave no consumer
   any chance to see the warning, whatever the version numbers say.
3. **Remove in a release whose bump permits a break** — a MAJOR after 1.0, or a MINOR while still on
   `0.y.z`, where SemVer 2.0.0 §4 permits it. With the breaking-change ADR and a migration note.

Removing a public symbol **is** a backward-incompatible change, which is why step 3 is about the
bump and not merely about the window: satisfying the window does not license a removal in a PATCH.
`tools/bc_gate.py` enforces exactly that boundary on release PRs — it permits detected breaks in a
MAJOR bump, and in a pre-1.0 MINOR, and refuses them in a PATCH or in a post-1.0 MINOR.

### While the version is still `0.y.z`

SemVer 2.0.0 §4 says anything may change under `0.y.z`, and this project's pre-1.0 line bumps MINOR
per completed milestone. So pre-1.0 the policy reads: deprecate in `0.N`, remove no earlier than
`0.N+2` — one full published MINOR (`0.N+1`) must have shipped with the symbol present and
deprecated. The relaxation SemVer grants is in *which bump may carry a break*, not in whether
consumers get a warning first.

## Consistency lint — failure → remediation

`python tools/consistency_lint.py` runs before every PR and in CI. Each failure prints
`[check] message`; fix per the check's intent (version lockstep, ADR index, pattern rows,
spec coverage map, milestone agreement, bug-ledger integrity). See the lint's docstring for
the full contract.
