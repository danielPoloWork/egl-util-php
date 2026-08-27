# ADR-0082: Pin the `@internal` inventory, so widening the carve-out is visible

- **Status:** Accepted
- **Date:** 2026-08-27
- **Deciders:** project architect (agent), maintainer
- **Related:** issue [#111](https://github.com/danielPoloWork/egl-util-php/issues/111) ·
  [ADR-0059](0059-freeze-the-api-at-1-0-0-with-internal-symbols-outside-the-frozen-surface.md)
  (the carve-out this pins) ·
  [ADR-0031](0031-run-the-bc-checker-outside-the-dependency-graph-and-gate-breaks-by-bump.md)
  (the BC gate this closes the other direction of) · `tools/consistency_lint.py` check 10

## Context

ADR-0059 froze the public API at `v1.0.0` and carved two symbols out of it —
`Security\SecretKey::bytes()` and `Security\Hash::selectAlgorithm()` — documented `@internal`
because PHP has no package-private visibility and both are `public` for mechanical reasons only.
The ADR states the mechanical consequence plainly: `roave/backward-compatibility-check` reads
visibility, not docblocks, so **removing** an `@internal` symbol still trips the BC gate, and the
override requires "a written reason naming this ADR — a deliberate, visible, reviewed act, not a
silent exclusion list that rots."

Issue #111 (Staff Software Engineer, API Review Board, 2026-08-09) names the direction that
guard does not cover: **adding** `@internal` to an already-frozen public symbol moves it outside
the 1.x contract just as surely as deleting it would, and nothing checks for that. The BC gate
cannot — a symbol that stays `public` and merely grows a docblock tag is, to a tool reading
signatures, unchanged.

**The inventory had already grown by the time this was investigated.** ADR-0059's table names two
symbols; `src/main` today carries five. `Security\Base64Url` and `Security\Uint64` (whole classes,
extracted shared codecs from RFC-0003's items 10.4/10.5) and `Persistence\Page::__construct()`
(RFC-0003's pagination unit) each shipped `@internal` from the day they were introduced — additive,
not excised from a frozen baseline, and therefore not a violation of anything. But their existence
is exactly the fact this issue is about: nothing had recorded that the inventory was five rather
than two, so nothing would have distinguished that legitimate growth from the illegitimate kind —
a maintainer or a future edit quietly tagging `Str::slug()` `@internal` to dodge a BC break would
have looked identical in every tool this project runs.

## Decision

**`consistency_lint.py` gains a tenth check, `internal-inventory`, asserting the `@internal`
symbols found in `src/main` equal exactly a pinned set, `EXPECTED_INTERNAL`, seeded with today's
five. Widening or narrowing that set is a one-line, reviewed edit to the linter itself.**

### 1. A regex scan, not a parser — consistent with every other check in this file

Two shapes are recognised: a docblock immediately followed by a class/interface/trait/enum
declaration (the symbol is the class itself — `Base64Url`'s and `Uint64`'s case), and a docblock
immediately followed by a method declaration (the symbol is `Class::method()` — `SecretKey::bytes()`
and `Hash::selectAlgorithm()`'s case, and `Page::__construct()`'s). The enclosing class for a
method-level tag is found by taking the last class declaration before the docblock in the same
file — sound for this codebase because PSR-4 autoloading already commits every file here to one
class per file.

**The tag itself is matched strictly**, not by a substring search for `@internal` anywhere in the
docblock. `Base64Url.php`'s own class docblock contains the literal text `` `@internal` `` twice:
once in backtick-quoted prose explaining *why* the class carries the tag, and once as the actual
tag on its own line. A substring match would not have cared which; this check requires a line that,
after its leading `*`, begins with `@internal` — recognising the second occurrence and not the
first. Proved directly: `tools/tests/verify_internal_inventory.py` reproduces exactly this shape
(a class whose docblock mentions `@internal` only in prose) and asserts it is **not** flagged.

### 2. Known limit, stated rather than hidden

A regex recognises two shapes. An `@internal` tag inside a union-type property's inline doc, an
enum case, or anywhere else this project has never actually put one would not be matched by either
`_CLASS_DECL` or `_METHOD_DECL`, and the check reports that explicitly — `"found @internal but
could not identify the class or method it documents"` — rather than silently passing it through.
That failure mode is the safe one: a shape this check cannot parse is a **loud** finding, never a
quiet gap, which is the same posture `dist_gate.py` and `release_gate.py` already take toward
"could not verify."

### 3. Proved in both directions, per the issue's own second criterion

The issue asks that the rule be shown failing before it is trusted — "stamp a third symbol in a
throwaway branch." Done twice, by hand, against the real tree, before writing a single line of
test code: `@internal` planted on `Str::slug()` (an already-frozen public symbol — the exact attack
this check exists to catch) reported it correctly; `@internal` removed from `SecretKey::bytes()`
(simulating an unguarded widen-by-removal) reported that too. Both were reverted immediately after.

That demonstration is real but single-use — the next reviewer cannot re-run "the state of the tree
before I fixed it." So the repeatable half is `tools/tests/verify_internal_inventory.py`,
`verify_link_check.py`'s shape adapted for a check whose expected set lives inside the linter
itself: each of its seven cases copies the real `consistency_lint.py` into a throwaway git
repository, **rewrites its `EXPECTED_INTERNAL` constant** to a small fixture set, writes synthetic
PHP source, and runs `--only internal_inventory`. It covers both failure directions, the
class-level and method-level shapes, the prose-vs-tag distinction above, and that multiple
violations are all reported rather than only the first — and it runs on every PR (`ci.yml`), so the
proof is not this session's alone.

## Alternatives Considered

- **A PHP-native check** (a PHPStan rule, a Roave configuration flag). Rejected: ADR-0059 itself
  already noted "Roave offers no `@internal` suppression this project can configure from the
  throwaway-project install," and every other structural check in this repository is Python/regex
  over the tree for the same reason `consistency_lint.py`'s docstring gives — dependency-free,
  agent-runnable from anywhere. A second toolchain for one check would be the opposite of that.
- **Freeze the inventory at exactly ADR-0059's original two.** Rejected: `Base64Url`, `Uint64` and
  `Page::__construct()` are legitimate, already-shipped exclusions on symbols that did not exist at
  the frozen baseline — pinning the check to "two" would fail on the very first run against this
  repository's real state, for growth nothing did wrong.
- **A substring match for `@internal` anywhere in a docblock**, skipping the stricter tag-line
  regex. Rejected in §1: it is indistinguishable from a prose mention of the word, and
  `Base64Url.php`'s own docblock — written before this check existed — already contains exactly
  that ambiguity.
- **Suppress the check when `EXPECTED_INTERNAL` and the found set merely reorder.** Not applicable
  — both sides are Python sets, so order was never a dimension the check could see, and no case
  needed to reason about it.
- **Skip the offline proof-of-fail script and rely on the hand-run demonstration alone.** Considered
  given the issue's own criterion is satisfied by the manual proof. Rejected on this project's own
  established precedent: `verify_link_check.py`, `verify_bc_gate.py` and `verify_dist_gate.py` all
  exist specifically because a single hand-run demonstration is not repeatable, and a check that
  regresses silently between the day it was proved and the day someone next reads this ADR is a
  check nobody re-verified.

## Consequences

- **No production code changes.** `tools/consistency_lint.py` (one new check),
  `tools/tests/verify_internal_inventory.py` (its proof), one `ci.yml` step, this ADR.
- **Widening the carve-out is now a one-line, reviewed diff** to `EXPECTED_INTERNAL` — exactly the
  "deliberate, visible, reviewed act" ADR-0059 already asks for on the removal side, extended to
  cover addition.
- **The full suite was re-run clean**: `python tools/consistency_lint.py` passes with the real
  five-symbol inventory, and every other `tools/tests/verify_*.py` proof script still passes
  unmodified — this check adds a tenth invariant without touching the other nine.
- **Known limitation, stated rather than discovered:** two recognised shapes (class-level,
  method-level), and a third shape this project has never used would fail loudly rather than pass
  silently — recorded in the check's own comment, not only here.

## References

- Issue [#111](https://github.com/danielPoloWork/egl-util-php/issues/111) — 2026-08-09 Release
  Review Board, Staff Software Engineer / API Review Board seat.
- [ADR-0059](0059-freeze-the-api-at-1-0-0-with-internal-symbols-outside-the-frozen-surface.md) —
  the original two-symbol carve-out and the removal-side guard this pins the addition side of.
- `tools/tests/verify_internal_inventory.py` — the repeatable proof, seven cases.
