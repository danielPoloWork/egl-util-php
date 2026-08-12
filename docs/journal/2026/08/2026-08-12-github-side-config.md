# 2026-08-12 — The labels existed, the milestones existed, and neither had ever been applied

Issue **#86** (mirrors ROADMAP 13.8), filed by the Release Manager seat of the 2026-08-09 review
board. Route `fast / low`; session model Opus 5.

The item's own acceptance criteria opened with a line the plan-pass procedure has learned to
respect: *"Naming convention reconciled first — a maintainer call."* Two of the last three items
this session (#84, #85) had already found real facts hiding in an issue's stated scope; this one
had one too, and finding it before touching GitHub kept a bad convention from becoming permanent.

## The maintainer call, and the evidence that made it easy

`AGENTS.md` §6.4 said GitHub milestones should be titled `MN — name`. The live repository's eleven
milestones were all `vX.Y.0`. Before asking, I checked whether `MN — name` had ever actually been
used anywhere — and it hadn't: **all fourteen** `## Milestone N — <name> (\`TAG\`)` headers in
`ROADMAP.md` already carry a version tag in the parenthetical, not the `MN` form the rule
described. The rule had never been followed even in the document that states it.

Put to the maintainer with that evidence, the answer was immediate: `vX.Y.Z`, correct `AGENTS.md`
to match reality. Reconciling a rule to the convention already used fourteen times is a smaller
change than reformatting fourteen ROADMAP headers and renaming eleven closed GitHub milestones to
match a form nobody had ever chosen.

## What was actually broken, verified before being touched

- **Labels**: `.github/labels.yml` defined ten Conventional-Commit type labels; the repository
  carried only GitHub's stock set (`bug`, `enhancement`, …). Confirmed by listing both.
- **Milestones**: all eleven existing ones (`v0.1.0`…`v0.11.0`) had `open_issues: 0` — every item
  assigned to them was already closed. Confirmed via the API, not assumed from "M1–M11 are done."

## Two gaps the issue's own text did not name

**`release` was a real commit type with no label.** `git log` shows four merged commits using it
(`release: v0.11.0`, `release: v1.0.0`) — and it existed in neither `.github/labels.yml`'s ten
labels nor `AGENTS.md` §6.2's branch-naming type enum. `security` had the mirror problem: in the
label set, missing from §6.2. Both added together rather than fixed one at a time, since leaving
either half-corrected would have reproduced the same class of drift the item exists to close.

**M8 had never gotten a milestone at all.** It versions under its own bridge-scoped tag
(`utils-psr7-bridge-v0.1.0`, ADR-0033), so it was invisible to a quick scan of `v0.1.0`…`v0.11.0` —
and it has been fully closed since 2026-08-05 with no GitHub milestone ever created for it. The
corrected seeder created it; it was closed in the same pass once confirmed against `ROADMAP.md`
that all three of M8's items are `[x]`.

## The seeder itself was the same bug as the rule, in code

`.eados-core/tools/seed_milestones.py` hardcoded `milestone_title()` to `MN — <name>`, independent
of what any header said — the tool-form of the rule that had just been corrected. Running it
unmodified would have created `M13 — Documentation & release hygiene` and `M14 — Post-1.0
functional seams`, immediately wrong for the newly-settled convention.

Fixing it needed the header parser to capture the parenthetical, which turned out to have two
shapes already live in the real file: backtick-quoted (`` `v0.11.0` ``, `` `utils-psr7-bridge-v0.1.0` ``)
and bare (`post-1.0`, M13's case — no backticks, no version). The regex change and the fallback
(`m["tag"] or f"M{m['number']} — {m['title']}"` for a header this parser might see with no tag at
all) were both verified against every one of the 14 real headers before running `--run` for real,
not just the two new ones — a change to a shared parser that only gets exercised on the two
milestones being added that day would have left the other twelve unverified by construction.

This was `.eados-core/tools/**` and `.github/**` — both exclusively the enterprise-architect's per
`authority.yaml`, confirmed with `authority_check.py` before editing either. The scaffold phase's
acting role is the architect, and "apply the GitHub-side configuration" is scaffold-phase work by
its own description, so the authority to touch both was already in scope rather than borrowed.

**One consequence of where the fix landed, caught before writing it up as done:** `.eados-core/`
is gitignored — the whole bundle except `learning/runs/` — because it is factory tooling copied
into a repo to (re)generate it, never itself committed to one. `git add` refused the file
silently ignorable, confirming it. The correction is real and the `--run` invocation against it
produced the real, permanent GitHub milestones above; the corrected *source* ships in no commit
here and would be lost on the bundle's next refresh unless the architect carries it forward
separately. Said plainly in ROADMAP/CHANGELOG rather than left to look like a shipped fix.

## The gap RFC-0003 had already predicted, confirmed the hard way

RFC-0003's own preamble said plainly: *"none of these issues carries any label — the routes above
are resolved from the derived `adr` signal, and become machine-verifiable only when item 13.8
applies the type labels."* Applying the type labels and re-running `route_advice.py --issue N` on
the M14 candidates still returned `fast / low` for every one of them.

The reason was a second missing label the type-label sweep never touched: `adr` itself. `grep`
across `os/routing/routing.yaml` for every `label:` signal it reads turned up three —
`label:adr`, `label:security`, `label:severity:*` — and only `security` had ever existed as a real
GitHub label. `adr` was cited in ADR-0048's own text, in a journal entry, in this week's RFC-0003
and M14 preamble — and had never once been created. A routing policy that reads a label nobody
creates resolves the same as a policy with no rule at all, and the difference is invisible until
someone actually runs the check.

Created it, and applied it only to the **nine** issues whose own acceptance criteria state an ADR
requirement in so many words — checked one by one against each issue's text (`grep` first, then
read each match), not inferred from a `feat:`/`security:` title prefix. Two near-misses worth
naming: #101 merely *cites* an existing ADR as background and needs no new one; #113's issue body
already stated its own route as `(adr — touches the frozen surface additively)`, which the filer
had reasoned out but which no label anywhere reflected until this pass.

`severity:*` is the third signal `os/routing` reads and it stays unapplied. Assigning a severity
is a judgment this item's own scope — apply existing labels — does not license inventing.

## One correction to my own work from twenty minutes earlier

The M14 candidate issues (#92, #94–#97) were assigned the `v1.1.0` milestone, and so, briefly,
were #91 and #93 — before I re-read RFC-0003 for the second time this pass and remembered they
were **deferred**, not accepted. Cleared both immediately, verified the final state
issue-by-issue rather than trusting the batch loop's own echoed "OK". A milestone assignment is a
public, visible claim about what ships when; a deferred item carrying it would have told a reader
the opposite of what RFC-0003 decided, in the one place — the milestone board — most likely to be
read without the RFC alongside it.

## Left exactly as found

`severity:*` labels, as stated above. Branch protection and the merge-strategy/Discussions/Pages
steps in `docs/workflow/github-setup.md` §§1, 3, 4 — issue #86 named only labels and milestones,
and widening scope to "every step in the setup doc" was not asked for and not done.
