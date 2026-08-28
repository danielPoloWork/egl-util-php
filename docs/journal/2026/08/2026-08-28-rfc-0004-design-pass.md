# 2026-08-28 — Twenty units, zero new requires, and a gate that is red on purpose

`/eados design`, acting role **tech-lead**; session model Fable 5. Deliverable:
[RFC-0004](../../../rfc/0004-batteries-included-utility-surface.md) (In review), `refs.rfcs`
cross-link (manifest_rev 8, state-writer step, authority-checked for both roles), this
checkpoint. **No production code changed.**

## What the pass decided

The maintainer's direction — make `egl/utils` the batteries-included must-have — had already
produced twenty screened candidate issues over two review passes (#187–#194, #196–#197,
#199–#208; #195 filed and closed invalid in between). The RFC therefore does not re-screen;
what it adds is the part no issue can hold alone:

- **the shared architecture** — eight governing constraints, the load-bearing ones being
  *zero new `require` entries* (three `suggest` lines are the whole footprint, verified per
  unit in review finding A2), *composition is the converter* (one canonical shape, N codecs,
  no registry), and the *missing-value grammar* (asserting/defaulting/probing) promoted from
  `Lookup` precedent to named convention;
- **the sequence** — four milestones (M15 foundations → M16 validation → M17 formats → M18
  security & resilience) whose partition is dependency-driven, with the two load-bearing edges
  named: `#[MapFrom]` needs `Str`'s case conversions, `Archive` needs `Path`, and the
  validation `Length` rule needs codepoint-safe `Str::length()` or it counts bytes;
- **one spec reversal** — FR-49's circuit-breaker non-goal, whose stated premise ("shared
  cross-call state, no seam") dissolved when 14.7 shipped the CAS store; reversed on #91's
  lifted-deferral precedent, annotated never erased, placement left to the ADR;
- **the second wave of standing exclusions** — PSR-14, Config, JWT, caching, linear algebra,
  country-specific validators, MessagePack, XLSX — each with its citable reason, so the next
  breadth request has answers instead of re-arguments.

## The review that ran, and what it changed

The protocol's reviewer and enterprise-architect seats were worn by the session agent and the
Approval section says so (RFC-0003's honest precedent, upgraded from "no pass ran" to a real
findings table). Six findings, all resolved in the document; three changed it materially:
**R2** caught #200's floated reuse of `Database\Sort` in `Dto` — a deptrac-forbidden edge the
landing ADR could have inherited; **A1** settled the validation `Email` rule against
`Mail\EmailAddress` duplication with a shared-corpus parity test in the test tree (tests may
cross groups; production may not); **A3** bound `#[MapFrom]` to NFR-01's existing budget so a
mapping-aware hydrator cannot tax the estate's unmapped DTOs.

## The gate that went green on a pending approval — the catalogued class, third sighting

`rfc_check.py` was expected red until the maintainer approves. **Its first run reported OK on a
document whose record says "pending."** Cause, read from the checker's own source
(`rfc_check.py:45`): it text-searches the whole document for the first marker-shaped line, and
the Approval section's *prose* — which quoted the marker while explaining who fills it —
matched. That is the exact failure class this repository has now met three times: BUG-0001
(the constant-time registry blind to prefixed calls), the `bridge_release_gate` heading check
satisfied by a sentence quoting the heading (#170's fix), and now the vendored core's own RFC
gate. The recorded rule held on contact: **in this repo a text search finds the prose
discussing the mechanism** — so the fix was item 10.7's, *describe the tag, never spell it* —
the prose now names the marker's shape without containing it, and the re-run is **red with "no
approval record", which is the gate working.** The checker's weakness itself is an upstream
EADOS finding, noted here for the next `/eados upgrade` conversation rather than patched in the
vendored bundle (this repo governs itself and does not fork its pinned tooling mid-PR).

## The second gate behaving correctly, recorded so nobody "fixes" it

The phase machine still reads `scaffold` (only `→ audit` legal), as it has since 2026-08-03;
this design pass, like RFC-0002's and RFC-0003's, runs under the owner's routing of that
recorded ledger gap. Step 7 of the design procedure (propose `design → plan`) is therefore
structurally unavailable and deliberately not forced.

## Carried forward

The plan pass (RFC-0003 → #84's shape) turns FR-51…FR-71 into numbered M15–M18 items with
sizes and routes — machine-verifiable this time, because all twenty issues carry their type
labels from filing. The #195 lesson rides along where it applies: *a surface survey is not a
contract survey*; two of this RFC's own claims (Csv's existing streaming, the exact spec tail
r29/FR-50/NFR-15/T-15) were re-verified against the source before being written down.
