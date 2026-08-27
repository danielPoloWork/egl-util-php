# 2026-08-27 — Narrowing a decision instead of pretending it never happened

Issue **#104**, its one criterion. Route `fast / low`; session model Sonnet 5. **ADR-0080**
annotated.

Small on paper — add a table to `SECURITY.md` — and it would have been genuinely small if
`docs/workflow/maintenance.md` had not already recorded a reason not to.

## The issue was asking to reverse a documented decision, not fill a gap

`maintenance.md` § *Supported versions* has a paragraph, cited to **ADR-0060**, stating flatly that
`SECURITY.md` "deliberately commits to no timeframe — there is no response-time SLA here, and none
is implied." That is not silence nobody thought about; it is a decision, made on the same
2026-08-09 review board issue #104 comes from, by the same Senior Security Engineer seat, for a
reason ADR-0060 states explicitly: a solo maintainer should not promise an outcome they cannot
reliably keep.

So before writing a table, the actual question was whether #104 has a real answer to that objection
or is asking to undo it by not noticing it. It has one, and it is in the issue's own wording:
*"Numbers sized to solo-maintainer capacity — honest beats impressive."* That is not a request for
an SLA. It is a request for a **target**, and the two are different claims: a guarantee promises an
outcome regardless of circumstance, a target commits to effort under normal ones. ADR-0060 ruled out
the first. Nothing in it rules out the second, and the issue is careful to ask for exactly that.

## Sizing the numbers to something checkable, not to a vibe

5 business days to acknowledge, 15 to a triage verdict. The temptation with a policy document is to
write a number that sounds responsible and move on; I wanted numbers that meant something against
this project's own history. This repository's PR turnaround, observed across the sessions in this
log, is same-day to next-day — so 5 days to *acknowledge* a report is deliberately generous rather
than aspirational: a security report can land during exactly the gap between two of those fast PRs,
and a target that only holds when the maintainer is already at the keyboard isn't a target, it's a
description of the best case.

The escalation path took more thought than the numbers. `SECURITY.md` already refuses a public
issue for a security problem, and there's no second private channel to point a reporter at — adding
a backup email is one more thing that has to stay current, and a stale backup contact is worse than
none because a reporter trusts it. So the escalation is: bump the same thread first (cheap, and the
most likely reason for silence is a missed notification, not abandonment); and at 30 days of total
silence, the reporter is released to their own disclosure timeline. That path depends on nothing the
maintainer has to operate. It is a statement of what silence means, not a mechanism.

## What stayed as it was, on purpose

`docs/workflow/maintenance.md`'s "no SLA" sentence is now stale, and I corrected it in the same PR
rather than leaving a document to visibly contradict `SECURITY.md`. What I did *not* do is edit
ADR-0060 itself. It recorded a real decision made with the information available that day, and
rewriting it would erase that it happened — this project already has a convention for a later
decision that narrows an earlier one (ADR-0027's own Related line does the same thing to an earlier
ADR), and ADR-0080 follows it: the relationship is stated in the new document's *Related* line,
labeled "narrowed by this decision," not folded silently into the old one.

## Where this leaves the project

No code changed. Two files (`SECURITY.md`, `maintenance.md`) plus this ADR. The targets are
unenforced and unenforceable by design — no bot, no calendar reminder — and that is recorded as a
known limitation in ADR-0080 rather than left implicit. A future item to add enforcement is real and
is explicitly out of scope for a documentation-only PR.
