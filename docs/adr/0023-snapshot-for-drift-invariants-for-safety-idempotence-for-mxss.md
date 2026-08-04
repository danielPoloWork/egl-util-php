# ADR-0023: Snapshots catch drift, invariants catch wrong, and idempotence catches mutation XSS

- **Status:** Accepted
- **Date:** 2026-08-04
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 5.4 · spec §7 (*"OWASP XSS cheat-sheet corpus per Escaper context
  (snapshot suite); DOM-bypass corpus for richText()"*) ·
  [ADR-0019](0019-four-escaping-contexts-and-the-unquoted-attribute-assumption.md) (the escapers) ·
  **corrects a claim in** [ADR-0021](0021-delegate-rich-html-and-escape-like-wildcards-with-a-portable-character.md)

## Context

Spec §7 asks for two corpora and names the shape of one of them: the OWASP corpus is to be a
**snapshot suite**. Item 5.1 already covers each escaper's per-method contract, so the question
here is what a corpus adds that focused unit tests do not.

Two things, and they are different:

1. **Drift.** Escaper output is long, mechanical, and unmemorable. Nobody reviewing a diff notices
   that `&#x2F;` became `&#47;` across forty payloads, and no hand-written assertion covers the
   combination of *every* payload with *every* context.
2. **Mutation XSS.** `richText()` faces a threat ordinary assertions structurally cannot catch. An
   mXSS payload is **inert when parsed once** and becomes executable when the sanitized output is
   re-serialised and parsed again — typically by escaping a foreign-content or raw-text context
   (`<svg>`, `<math>`, `<noscript>`, `<style>`, `<xmp>`) that changes how the parser treats
   everything after it. Asserting *"no `<script>` in the output"* does not catch it, because
   **there is no `<script>` in the output**.

## Decision

### 1. Snapshot **and** invariants, never one alone

The snapshot records what each escaper produces for every payload × context. The invariants assert
what must be *true* of that output regardless of what it is.

**This pairing is the decision.** A snapshot proves **stability**, not **safety** — a snapshot of
broken output is a perfectly valid snapshot, and a suite that only compares against a recording
will happily bless a regression the moment someone re-records it. Invariants alone would miss a
change that stays within them. Stated explicitly in `Snapshot`'s own docblock, because conflating
the two is exactly how snapshot suites end up certifying bugs.

Re-recording is deliberate — `UPDATE_SNAPSHOTS=1` — and never automatic. A snapshot that rewrites
itself on failure asserts nothing; the entire value is that a human reads the diff and agrees with
it. The file is written with sorted keys and pretty-printed, unescaped slashes and unicode, for the
same reason: its only job is to produce a diff someone can actually review.

### 2. Idempotence is the mutation-XSS assertion

`richText(richText($x))` must equal `richText($x)`.

Output that changes when fed back through the sanitizer is output whose parse is not stable, and
**instability under re-parse is the mXSS signature** — whether or not this particular corpus
contains a payload that exploits it. It is a property of the *mechanism* rather than a check
against a list of known attacks, which is what makes it hold for payloads nobody has written yet.

### 3. A "destroys everything" assertion, because the safe answer is also the useless one

`return '';` passes every security assertion in the suite. `testLegitimateRichTextSurvives()` is
what stops that being a valid implementation, and its presence is why the negative assertions can
be written strictly.

### 4. The corpora assert their own coverage

A payload list that quietly loses its tag-less or mutation entries still passes every other
assertion while testing far less. Both suites assert that the techniques they claim to cover are
still named, and that the corpus has not shrunk.

## The claim this corrects in ADR-0021

ADR-0021 said the scheme allowlist *"is what refuses `javascript:` and `data:`"*. Building this
corpus disproved half of it.

A probe adding `javascript` to the allowed schemes and re-running the whole suite **passed** —
`symfony/html-sanitizer` refuses that scheme unconditionally, allowlist or not. The same probe
using `data` **fails** the suite. So:

| scheme | what refuses it |
|---|---|
| `javascript:` | upstream, unconditionally — the allowlist is *defence in depth* |
| `data:` (incl. `data:text/html`) | **the allowlist, and only the allowlist** |

The restriction is still correct and still worth having. The *causal claim* about it was imprecise,
and it matters because a reader deciding whether to widen that list needs to know which entries are
load-bearing. ADR-0021 carries the correction inline; the class docblock does too.

Worth noting how it surfaced: not by re-reading the ADR, but because a planted defect **passed**
when it should have failed. A probe that does not fail is evidence about the code *or* about the
claim.

## Alternatives Considered

- **Hand-written expected values for every payload × context** — rejected: ~180 long mechanical
  strings that nobody would review, and which would be updated by copy-pasting actual output the
  moment one changed, i.e. a snapshot with extra steps and no tooling.
- **Snapshot only** — rejected in §1: it certifies stability and says nothing about safety.
- **Invariants only** — rejected: no visibility into behaviour changes that stay within them, which
  is precisely where a subtle escaping regression lives.
- **Automatic snapshot rewrite on mismatch** — rejected: an assertion that repairs itself is not an
  assertion.
- **Asserting mXSS by listing known-bad output substrings** — rejected in §2: mutation payloads
  produce output containing no dangerous token, so a substring check cannot see them. Idempotence
  is a property of the mechanism instead.
- **A DOM-based re-parse comparison** rather than string idempotence — considered and not needed:
  string equality after a second pass is strictly stronger than DOM equivalence, and needs no extra
  dependency.

## Consequences

- 1010 tests total (up from 706); `--group T-06` runs 386. Two committed snapshots under
  `src/test/resources/snapshots/`, following ADR-0002's Maven-style layout.
- Any change to escaper or sanitizer output is now a reviewable diff. Verified by planting
  `&#x%02X;` → `&#%d;` in `Escaper::attr()` and watching the snapshot fail.
- The invariants were verified non-vacuous independently: non-idempotent output (22 failures),
  `richText()` returning `''` (2 failures — the "safe but useless" case), and the `data:` scheme
  allowed (1 failure).
- **The `javascript:` probe passing is recorded as a finding, not filed away as a nuisance.** It is
  what corrected ADR-0021.
- The snapshots will need re-recording when `symfony/html-sanitizer` is upgraded. That is intended:
  a sanitizer upgrade changing what survives is exactly the event that should require someone to
  look, and the failure message says so.
- Coverage of the escapers rose as a side effect, but that is not what these suites are for — they
  exist for the two failure modes in §1 and §2.

## References

- Spec §7 (both corpora, and the "snapshot suite" wording)
- ADR-0019 — the four contexts and their per-method contracts (item 5.1)
- ADR-0021 — delegation to `symfony/html-sanitizer`, and the scheme claim corrected above
- OWASP XSS Filter Evasion cheat sheet; the mutation-XSS family (`<noscript>`/`<style>`/`<xmp>`
  context escapes) that motivates RFC-0001's *"no hand-rolled tag stripper"*
