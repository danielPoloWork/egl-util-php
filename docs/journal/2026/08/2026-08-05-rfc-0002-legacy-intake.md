# 2026-08-05 — Two applications, one RFC, and the numbers that wrote it

Not a roadmap item. This session ran the **design procedure** (`/eados design`, tech-lead
role, authority check green) against new scope: the maintainer opened two production
applications from the library's target estate for private survey, and asked the question
this library was built to answer — which of their mechanisms deserve to become shared,
tested components, layer by layer (query / DAO / CRUD / DTO / builder / service /
controller).

## What the survey found

The estate is disciplined in shape and ad hoc in mechanism. The same layering everywhere —
and every cross-cutting concern solved locally, at counts worth recording because they are
the prioritization argument: **199** SQL interpolation sites against **0** bound
parameters; **74** swallowed `Throwable`s; **160** per-level logger factory calls; **three**
coexisting response-envelope implementations; **17** copies of one row-cleanup pipeline;
**37** copied per-endpoint front controllers. None of these numbers survive into the
library as code — they survive as anti-requirements: *no silent sentinel returns* is now a
stated error-model rule in RFC-0002.

The survey material itself stays private. It sits untracked, is additionally pinned out of
version control locally (`.git/info/exclude`), and the RFC carries only aggregated,
anonymized counts — no identifiers, no schema or host names, no domain vocabulary. A
leak-gate grep over the staged diff enforced that before this commit existed.

## The deliverable

[RFC-0002](../../../rfc/0002-application-layer-groups-from-legacy-intake.md), **In
review**, deliberately without an approval record — the boundary says no RFC
self-approves, so `rfc_check` is *expected* red on exactly that field until the maintainer
decides. Scope: two new groups (`Persistence`, `Mail`), additions to four existing ones,
eighteen FR-shapes (FR-27…FR-44), six advisory NFR budgets, nine new suites. The per-layer
answer is one principle applied eighteen times: **the library ships contracts and
mechanics; per-entity classes stay in the application** — no code generator, no ORM.

## Two governance facts recorded rather than resolved

1. **The phase machine has no design re-entry.** `phase_runner` reports the manifest at
   `scaffold` with `→ audit` as the only legal move. Authoring an RFC is what §7's "a
   genuinely new capability is planned first" requires, but the formal ledger cannot record
   a design pass from here. The RFC lands as a documentation artifact; how the ledger tells
   this story is the maintainer's call, noted in the RFC's Consequences.
2. **Item 7.4 is in flight in a parallel session** (branch `docs/psr7-bridge-packaging`,
   pushed, PR not yet open at the time of writing). This session's branch was therefore cut
   from `origin/master` in an isolated worktree, touching nothing of that work. Two
   PR-ready branches now exist and **the maintainer sequences them**; the roadmap-item one
   (7.4) has the standing claim to go first. RFC-0002's advisory milestones deliberately
   carry no numbers for the same reason — the bridge milestone takes its slot before the
   plan phase numbers anything from this RFC.

## Lesson

A survey number beats a survey adjective: "199 interpolation sites, 0 bound parameters"
ended a scoping debate that "the SQL layer needs help" would have prolonged for a page.
Count first, then propose.
