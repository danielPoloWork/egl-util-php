# ADR-0080: Response-time targets sized to solo capacity, not a guarantee

- **Status:** Accepted
- **Date:** 2026-08-27
- **Deciders:** project architect (agent), maintainer
- **Related:** issue [#104](https://github.com/danielPoloWork/egl-util-php/issues/104) ·
  [ADR-0060](0060-support-the-latest-release-of-the-current-major-and-measure-the-window-in-releases.md)
  (narrowed by this decision — its Consequences record "no response-time SLA is stated or implied
  anywhere", which this ADR partially reverses, deliberately and for a stated reason) ·
  `SECURITY.md` § *Response-time targets* · `docs/workflow/maintenance.md` § *Supported versions*

## Context

`SECURITY.md` routes a vulnerability report to GitHub private reporting and lists a four-step
sequence — acknowledge, triage under embargo, coordinated release, backport — with no timeframe
attached to any step. That silence was a deliberate decision, not an oversight: ADR-0060 recorded it
explicitly, reasoning that a solo-maintained project should not state a commitment it cannot
reliably keep.

Issue #104 (Senior Security Engineer seat, same 2026-08-09 review board as ADR-0060's own findings)
argues the silence has a cost ADR-0060 did not weigh: **a reporter who hears nothing cannot tell
"in triage" from "lost".** Four days of silence and forty days of silence look identical from
outside, and the difference matters to someone deciding whether to wait, follow up, or move toward
their own disclosure. The issue's own framing anticipates the objection ADR-0060 raised — *"numbers
sized to solo-maintainer capacity — honest beats impressive"* — asking for a target, not a promise.

## Decision

**`SECURITY.md` gains a small table of response-time targets, explicitly labeled as targets rather
than a guarantee, plus a stated escalation path for when the window passes.**

| Milestone | Target |
|---|---|
| Acknowledgement | within 5 business days |
| Triage verdict (confirmed + severity, declined, or "still investigating" with a reason) | within 15 business days of acknowledgement |

**"Targets, not a guarantee" is the load-bearing phrase, not a hedge added for cover.** ADR-0060's
objection was that a solo maintainer cannot guarantee an *outcome* — illness, a day job, a dead week
are all real and none of them are the project's to promise around. A **target** commits to effort
without promising the outcome: it tells a reporter what to expect from a maintainer who is actively
working the report, which is different information from "this will definitely happen by day N." The
distinction is stated in `SECURITY.md` itself, not left for a reader to infer.

**The numbers are sized to what this project's own history can support, not to industry norm.**
5 business days to acknowledge is generous next to this repository's own PR turnaround (same-day to
next-day, observed across dozens of merged PRs) — deliberately so: a security report can arrive
during exactly the gap the numbers exist to cover, and a target that only works when the maintainer
is already at the keyboard is not a target. 15 business days for a triage verdict allows for
reproducing, root-causing and sizing a fix under embargo, which is real work a rushed number would
pressure into being skipped.

**The escalation path costs the maintainer nothing to honor, because it does not depend on the
maintainer.** `SECURITY.md` already refuses a public issue or PR for a security problem, and there
is no second private channel to add — a backup email address is one more thing to keep current and
checked, which for a solo maintainer is itself a reliability risk. So escalation is: bump the
existing private thread first (cheapest, fastest to actually reach a maintainer who is behind on
notifications); and at 30 calendar days of total silence, the reporter is **released from any
implied embargo** and may proceed under their own disclosure timeline — industry-standard practice
(a 90-day disclose-regardless deadline) is offered as a default for a reporter with no policy of
their own. This is not a mechanism the maintainer operates; it is a statement of what silence means,
which a reporter can act on without needing anything further from this project.

## Alternatives Considered

- **Leave `SECURITY.md` as ADR-0060 left it — no timeframe at all.** Rejected: it is the status quo
  the issue argues against, and the argument holds. Silence that could mean "actively triaging" or
  "never seen" is a real cost to a reporter deciding what to do next, and it was not weighed against
  anything in ADR-0060 beyond the risk of overcommitting — a risk a *target* rather than an SLA
  avoids.
- **A hard SLA with consequences for missing it (refund, public apology, automatic disclosure at
  exactly N days).** Rejected: this is what ADR-0060 correctly ruled out, and issue #104 does not
  ask for it — its own framing ("honest beats impressive") is an explicit steer away from this
  option.
- **Tighter numbers to look more responsive** (e.g., 1-day acknowledgement, 5-day triage). Rejected:
  a target the maintainer cannot sustain across a bad week is worse than an honest one, because
  missing it silently is indistinguishable from the exact "in triage or lost" ambiguity this ADR
  exists to remove. Sized instead to what a solo maintainer with a day job can actually hold to.
- **A backup contact (personal email, a second GitHub account) as the escalation path.** Rejected:
  it is one more channel to keep monitored and current, which for a solo maintainer is itself
  something that can silently rot — and a stale backup contact is worse than none, because a
  reporter trusts it. The chosen escalation depends on nothing the maintainer has to maintain.
- **Amend ADR-0060's own text to remove the "no SLA" clause.** Rejected: ADR-0060 recorded a real
  decision made with the information available on 2026-08-09, and rewriting it would erase that
  history. The relationship is instead stated in this ADR's *Related* line — narrowed, not reversed
  in place — which is this project's existing convention for a later decision that revisits an
  earlier one (see ADR-0027's own Related line for the same pattern).

## Consequences

- **No code changes.** `SECURITY.md` and `docs/workflow/maintenance.md` § *Supported versions*
  are the entire diff, plus this ADR.
- **A reporter now has a concrete number to measure silence against**, and a stated,
  maintainer-independent path for what to do if that number is exceeded.
- **The targets are unenforced and unenforceable**, by design — there is no tooling, no CI check, no
  calendar reminder. Recorded as a known limitation rather than a gap nobody noticed: adding
  enforcement (a bot that flags an open advisory past its target) is a reasonable future item and is
  explicitly out of scope here, which is a documentation-only change.
- **`docs/workflow/maintenance.md`'s "no SLA" sentence is now stale** and is corrected in the same
  PR to point at this ADR rather than restate ADR-0060's original claim as though nothing had
  changed — the exact defect class ADR-0060 itself was written to repair elsewhere (a cross-reference
  whose target moved).
- **No spec amendment.** Response-time targets are project policy, not a library requirement; no
  `FR`/`NFR` names them and none needed to change.

## References

- Issue [#104](https://github.com/danielPoloWork/egl-util-php/issues/104) — 2026-08-09 Release
  Review Board, Senior Security Engineer seat (minor findings).
- [ADR-0060](0060-support-the-latest-release-of-the-current-major-and-measure-the-window-in-releases.md)
  — the original "no SLA" decision, narrowed rather than reversed here.
- [`SECURITY.md`](../../SECURITY.md) § *Response-time targets*.
