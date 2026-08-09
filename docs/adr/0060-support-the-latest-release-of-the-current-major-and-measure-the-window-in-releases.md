# ADR-0060: Support the latest release of the current MAJOR, and measure the window in releases

- **Status:** Accepted
- **Date:** 2026-08-09
- **Deciders:** project architect (agent), maintainer (`danielPoloWork`)
- **Related:** ADR-0031 (deprecation window), ADR-0059 (API freeze at 1.0.0), `SECURITY.md`,
  [`docs/workflow/maintenance.md`](../workflow/maintenance.md), roadmap item 7.5

## Context

`SECURITY.md` has, since the repository was generated, said this:

> Until `egl-util-php` reaches `v1.0.0`, only the latest released minor line receives security
> fixes. After `1.0.0`, the supported window is defined in `docs/workflow/maintenance.md`.

Both halves stopped working on 2026-08-09, when `v1.0.0` shipped (ADR-0059) — and they failed in
opposite directions. The **first** half became inapplicable: its table offered exactly two rows,
`latest released 0.x` and `older 0.x`, and no `0.x` was ever published (a `v0.11.0` was tagged and
never released), so at a `1.0.0` HEAD the policy's table had no row a consumer could be standing on.
The **second** half was a promise with nothing behind it: `maintenance.md` has never had a supported
versions section. The deferral pointed at a document that did not answer.

That second failure is the one worth naming, because it survived review for the entire pre-1.0 line.
A cross-reference is only as good as its target, and nothing in the repository checked this one —
`consistency_lint.py` verifies version lockstep, the ADR index, pattern rows, the spec coverage map,
milestone agreement and bug-ledger integrity, but not that a document referenced by name for a
definition actually contains one. The pre-1.0 clause masked it: while the first half applied, nobody
had cause to follow the pointer.

`maintenance.md` §*Security fixes* compounded it — "backport to every supported line" — using a term
the document never defined.

## Decision

**Supported means the latest release of the current MAJOR line**, and a fix reaches consumers by
their upgrading to it; older releases are not patched in place. When a new MAJOR opens, the previous
MAJOR's final release receives **security fixes only**, until the new line has shipped one full
MINOR (`X+1.1.0`). The window is counted in **published releases, not calendar time**.

The definition lives in `maintenance.md` § *Supported versions* — the location `SECURITY.md` already
promised — and `SECURITY.md` carries only the consumer-facing table and the pointer.

Two properties make this coherent rather than merely convenient. Within a MAJOR, "upgrade to the
latest" is not a cost the policy quietly pushes onto consumers: SemVer plus ADR-0059's freeze forbid
the break that would make it one, so the remedy the policy prescribes is a remedy it has already
guaranteed is safe. And counting in releases rather than months is the same instrument ADR-0031
chose for the deprecation window, for the same stated reason — a window nobody shipped through gave
no consumer a chance to move.

## Alternatives Considered

- **Support the latest PATCH of every published MINOR.** Rejected: backporting to N lines is work
  this project's staffing cannot honor, and a support promise that outruns capacity is worse than a
  narrow one — it is discovered to be false at the worst moment. It also buys little here, since the
  freeze already makes moving within 1.x cheap.
- **A calendar window ("previous MAJOR supported for 12 months").** Rejected on the precedent this
  repository set for itself in ADR-0031: time-based windows can elapse with no release in them,
  which satisfies the letter while giving consumers nothing to upgrade *to*. Release-counted windows
  cannot expire vacuously.
- **Support only the single latest release, with no previous-MAJOR clause.** Rejected as too sharp
  at the only moment the policy is load-bearing: a MAJOR bump is precisely when upgrading is *not*
  free, so dropping the old line the day the new one ships strands the consumers with the highest
  migration cost.
- **Leave `SECURITY.md` deferring and define nothing.** Rejected — that is the defect under repair,
  not an option. Under the enterprise posture (AGENTS.md §7) a security-relevant policy is not an
  undocumented judgment call.

## Consequences

- **API / compatibility:** none. This is policy, not code; no public symbol changes.
- **Documentation:** `SECURITY.md`'s table now has a row that applies at HEAD;
  `maintenance.md` gains § *Supported versions*, and its "every supported line" phrase acquires a
  referent. This ADR is the rationale both point back to.
- **Process:** a new MAJOR now carries an obligation the release checklist must honor — the previous
  line stays open for security fixes until `X+1.1.0`. Nothing enforces this mechanically today.
- **Testing/tooling:** none. Deliberately noted as a gap rather than claimed as covered: no lint
  asserts that a document referenced for a definition contains one, which is the exact class of
  defect this ADR repairs. Filed as roadmap item 13.4 rather than fixed here, because a
  cross-reference checker is a tool change, not a policy decision.
- **Known limitation, stated rather than discovered:** the previous-MAJOR row has never been
  exercised — `1.x` is the only line that has ever existed. It is a commitment made in advance, and
  the first `2.0.0` is where it gets tested. The window also says which line a fix lands on, not how
  fast it arrives: this is a solo-maintained library, `SECURITY.md` § *What to expect* commits to a
  sequence and to no timeframe, and no response-time SLA is stated or implied anywhere.

## References

- ADR-0031 — deprecation window measured in published MINORs (the instrument reused here).
- ADR-0059 — the 1.0.0 API freeze that makes intra-MAJOR upgrading a safe remedy.
- [Semantic Versioning 2.0.0](https://semver.org/) §§ 4, 8.
- [`SECURITY.md`](../../SECURITY.md), [`docs/workflow/maintenance.md`](../workflow/maintenance.md).
