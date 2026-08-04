# 2026-08-04 — A probe that passed, and the claim it disproved

Roadmap item **5.4**. Route `frontier-reasoning / extra`, session Opus 5 (standard) — same mismatch
as 5.3, which the maintainer already chose to accept. Recorded and proceeded rather than re-asking:
this is a test-corpus item, not a cryptographic policy.

## What a corpus adds that unit tests don't

Item 5.1 already covers each escaper's per-method contract, so it's worth being clear what this is
for. Two things, and they're different:

**Drift.** Escaper output is long, mechanical and unmemorable. Nobody reviewing a diff notices that
`&#x2F;` became `&#47;` across forty payloads, and no hand-written assertion covers *every* payload
against *every* context.

**Mutation XSS**, which ordinary assertions structurally cannot catch. An mXSS payload is inert when
parsed once and becomes executable when the sanitized output is re-serialised and parsed again —
usually by escaping a foreign-content or raw-text context (`<svg>`, `<math>`, `<noscript>`,
`<style>`, `<xmp>`) that changes how the parser reads everything after it. Asserting *"no `<script>`
in the output"* doesn't catch it, because **there is no `<script>` in the output.**

## The pairing that is the actual decision

A snapshot proves **stability**, not **safety**. A snapshot of broken output is a perfectly valid
snapshot. So: snapshot for drift, invariants for correctness, never one alone — written into
`Snapshot`'s own docblock, because conflating them is exactly how snapshot suites end up certifying
bugs.

Re-recording is `UPDATE_SNAPSHOTS=1` and never automatic. An assertion that repairs itself on
failure isn't an assertion. The file is sorted and pretty-printed with unescaped slashes and
unicode for one reason: its only job is to produce a diff a human will actually read.

For mXSS the load-bearing check is **idempotence** — `richText(richText($x)) === richText($x)`.
Output that changes on a second pass is output whose parse isn't stable, and instability under
re-parse *is* the mXSS signature, whether or not this corpus happens to contain a payload that
exploits it. It's a property of the mechanism rather than a check against a list of known attacks,
which is what makes it hold for payloads nobody has written yet.

And a `testLegitimateRichTextSurvives()`, because `return '';` passes every security assertion in
the file. That one test is what lets the negative assertions be written strictly.

## The finding: a probe that passed

Planting defects to check the suite isn't vacuous:

| planted | result |
|---|---|
| `&#x%02X;` → `&#%d;` in `attr()` | snapshot fails ✓ |
| output made non-idempotent | 22 failures ✓ |
| `richText()` returns `''` | 2 failures ✓ |
| **`javascript:` added to allowed schemes** | **passed** ✗ |

That last one should have failed. I'd written in ADR-0021 that the scheme allowlist *"is what
refuses `javascript:` and `data:`"*.

Investigating: `symfony/html-sanitizer` refuses `javascript:` **unconditionally** — allowlist or
not. Re-ran with `data` instead, and *that* fails the suite. So:

| scheme | what actually refuses it |
|---|---|
| `javascript:` | upstream, unconditionally — allowlist is defence in depth |
| `data:` (incl. `data:text/html`) | **the allowlist, and only the allowlist** |

The restriction is still correct and still worth keeping — two barriers beat one. But my *causal
claim* was half wrong, and it matters: someone deciding whether to widen that list needs to know
which entries are load-bearing. Corrected inline in ADR-0021 and in the class docblock.

**The thing worth remembering:** a probe that doesn't fail is evidence about the code *or* about the
claim. I found this by running the probe, not by re-reading what I'd written — and I'd read that
ADR several times since writing it without noticing.

## Bar

1010 tests / 2309 assertions green (up from 706). `--group T-06` runs 386. PHPStan max clean,
deptrac 0/0, consistency lint OK. Two snapshots committed under `src/test/resources/snapshots/`,
per ADR-0002's Maven-style layout.

The snapshots will need re-recording when `symfony/html-sanitizer` is upgraded. That's intended —
an upgrade changing what survives sanitization is precisely the event someone should have to look
at — and the failure message says so.

## Next

**5.5** — Hash matrix tests + NFR-05 timing, which closes Milestone 5. Item 5.3 already made the
fallback policy testable via `selectAlgorithm()`, so what 5.5 still owes is the timing measurement
and the wider matrix.
