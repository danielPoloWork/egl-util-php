# 2026-08-11 — The plan pass that had to stop and write an RFC first

Issue **#84**, filed by the 2026-08-09 release review board's Product Manager seat: the post-1.0
roadmap contained no functional direction. Route `standard / high`; session model Opus 5 — matched.

The item asked for a plan pass over seven candidates (#91–#97). It produced an RFC, an approval,
and a milestone — in that order, because the first attempt at the plan pass could not legally
finish.

## Where it stopped, and why that was right

The plan protocol's step 1 is one sentence: *"Each item references an approved RFC — no roadmap
item without a design behind it."*

`refs.rfcs` held `RFC-0001` and `RFC-0002`. Both Accepted, both fully implemented across M1–M12.
And **all seven candidates require a spec amendment by their own acceptance criteria** — five say
*"ADR + spec amendment"* verbatim. The spec is a frozen contract at r16 covering FR-01…FR-44; this
work starts at FR-45.

The tempting move was M13's precedent: a milestone with no RFC behind it, sitting in the file
already. It does not transfer. M13 bought that exemption explicitly, in its own preamble — *"**Not
spec work:** RFC-0001/0002 scope is closed, and every item here concerns the documentation surface
and the release process around it."* M14 is spec work seven times over and cannot say the same
sentence.

So the pass stopped at steps 1–2 (propose, size & route), produced the negotiation artifact, and
put the fork to the maintainer, who chose the design route. RFC-0003 was drafted, approved on PR
#129, and only then did this milestone get written.

## The gate that cannot see the rule it depends on

`traceability.py` implements `roadmap-covers-rfcs`. It is **one-directional**: it asserts
**RFC → ≥1 milestone**, and never **milestone item → an RFC**. Before any of this work it reported:

```
roadmap-covers-rfcs: OK — every RFC is addressed by a milestone.
```

— green, on a roadmap whose M13 references no RFC at all. An M14 written with no design behind it
would have passed identically. The protocol's load-bearing rule is enforced by nothing.

**Then approving RFC-0003 armed it, in the one direction it does check.** With the RFC accepted and
no milestone yet written, the same command went red:

```
roadmap-covers-rfcs: FAIL — no milestone addresses:
  RFC-0003
```

That is the gate doing its actual job for the first time in this sequence: it now guards against
approving a design and forgetting to plan it. Writing M14 turned it green again.

## A second defect in the same gate, found by not trusting the output

The green verdict came with a detail I had not put there. Before the edit:

```
RFC-0001 -> M1, M2, M3, M4, M5, M6, M7, M10, M13
RFC-0002 -> M9, M10, M11, M12, M13
```

After:

```
RFC-0001 -> M1, ..., M10, M13, M14
RFC-0002 -> M9, M10, M11, M12, M14      <- M13 gone
RFC-0003 -> M14
```

M13 lost RFC-0002, and M14 gained RFC-0001 — **and my M14 text contains no mention of RFC-0001 at
all** (`awk` over the milestone's line range returns nothing). The only RFC-0001 mention after M13
is at line 1821, inside the **Spec Coverage Map** — a trailing section that is not a milestone.

The tool attributes trailing content to the last milestone heading it saw. The Spec Coverage Map
was silently inflating M13's coverage; inserting M14 before it moved the inflation one milestone
down. **Neither attribution was ever real.** The `RFC-0003 → M14` link is genuine — it comes from
the five items that write `(RFC-0003)` explicitly — so the green verdict stands, but two of the
three lines above it are noise.

Recorded rather than fixed: `.eados-core/**` is the enterprise-architect's, and a tool change is
not this item's business. But it means the coverage map printed by that gate should not be read as
a claim about anything except the RFC it is being asked about.

## What the negotiation actually decided

Sizing and ordering came out of the tree, not out of the issues' own estimates:

- **14.1 (clock) is the keystone and ships alone first.** `grep -rn "ClockInterface\|psr/clock"
  src/main/ composer.json` returns nothing — there is no time abstraction anywhere — and three of
  the seven candidates name one as their dependency. Three private clocks is the same mistake three
  times.
- **14.3 (pagination) sized down** from the board's estimate: `QueryBuilder` already carries
  `limit()`/`offset()` with non-negative validation, so the item opens **no new SQL door** and stays
  inside the existing `Identifier` allowlist.
- **14.4 (HMAC) does not block on #114** as its issue assumed. ADR-0054's versioned token prefix
  already absorbs key identifiers — they arrive as `v2.` while `v1.` tokens keep verifying. The
  mechanism existed; the item just had to notice.

Two candidates were deferred **on reasons, not on size**, and their issues stay open because
deferred is not rejected: the rate limiter (#91) would ship single-node, and a single-node limiter
behind a load balancer *looks like* protection while removing the pressure to install a real one;
the PSR-18 bridge (#93) would be the second consumer of a publication pipeline whose
cross-repository push and subtree split have never run once, with its first consumer paused.

## The routes were all wrong, seven times, in the same way

The board hand-wrote `standard / medium` or `standard / high` on five of the seven. Every one of
them requires an ADR, and `os/routing`'s `adr-is-decision-heavy` rule makes `label:adr` a
**protected floor** — `route_advice.py --explain` prints *"protected (label:adr) — this floor may
not be lowered to save cost"* — resolving all five to `frontier-reasoning / extra`.

**This is a recurrence.** Item 10.12 hit the identical mismatch, was filed `standard/medium`, and
had its roadmap entry corrected against the same rule (item 1.10's precedent). Recognising it once
did not stop seven new issues from repeating it.

And the tool cannot currently catch it either: `route_advice.py --issue N` returns `fast / low` for
**all seven**, because none of these issues carries a single label. Applying the type labels is
item **13.8** / issue #86, still open. Until it lands, every route in the tracker is hand-asserted
and unverifiable by the tool built to verify it — stated in the M14 preamble rather than left for
someone to discover.

## Left undone, deliberately

The phase machine still reads `scaffold` with only `→ audit` legal, as it has since M9. This pass
did not propose a transition: the ledger gap is the owner's to route, and a plan pass run on
instruction is not the moment to quietly advance a state machine three phases behind the work.

`refs.releases` is still `[]` although v1.0.0 shipped on 2026-08-09. Named in a comment beside the
field rather than fixed — recording a release belongs to the release process, not to this item.
