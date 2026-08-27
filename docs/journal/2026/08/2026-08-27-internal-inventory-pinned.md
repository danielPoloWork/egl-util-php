# 2026-08-27 — The inventory had already grown, and nothing had noticed that either

Issue **#111**, both criteria. Route `fast / medium`; session model Sonnet 5. **ADR-0082**
annotated.

The issue is precise about the gap: `bc_gate.py` catches a symbol being *removed* from
`@internal`'s frozen exclusion, because that's a visibility change the checker can see. It cannot
catch a symbol being *added* to the exclusion, because a public method growing a docblock tag is
invisible to a tool that reads signatures. The fix sounded like it would be small — grep `src/main`
for `@internal`, compare to ADR-0059's table of two, done.

## The inventory was already five, not two

`grep -rl "@internal" src/main/` returns five files, not two. `Base64Url` and `Uint64` — whole
classes, extracted shared codecs — and `Page::__construct()` all carry the tag, all shipped after
ADR-0059 was written, in the additive MINOR that followed it. None of this is a violation: a symbol
that did not exist at the `v1.0.0` baseline cannot "break" by being excluded from a contract it was
never part of. But it is exactly the shape of thing this issue is about — nothing had recorded that
the inventory was five, so nothing would have told the difference between this legitimate growth
and someone quietly tagging an already-frozen method to dodge a BC break. Both would have looked
identical to every tool this project runs, right up until this check existed to look.

So the pinned list seeded here is five, with each of the three later entries annotated with which
ADR and which item introduced it — not because the check needs the annotation to function, but
because a reviewer six months from now, staring at a diff that adds a sixth line, should be able to
tell in one glance whether it looks like the first five or not.

## Getting the "internal" definition precise enough to not lie to itself

`Base64Url.php`'s own class docblock — written months before this check existed — contains the
literal text `` `@internal` `` twice: once inside backtick-quoted prose explaining why the class
carries the tag ("`@internal` for the same reason `SecretKey::bytes()` is"), and once as the actual
tag on its own line further down. A substring search for `@internal` anywhere in the docblock would
have matched on the first occurrence and been correct only by accident — it would also have matched
a class whose docblock merely *discusses* internal-ness without adopting it, and this codebase
apparently already had that exact ambiguity sitting in a real file before I ever wrote the check.

The fix was making the regex require the tag to be the first thing on its own docblock line
(`^\s*\*\s*@internal\b`), which correctly separates the two occurrences in `Base64Url.php` — and
I did not trust that separation until `verify_internal_inventory.py` reproduced the exact shape as
a fixture and asserted the prose-only version is *not* flagged. Real bugs live in the gap between
"this regex looks right" and "this regex survives contact with the file that already has the
ambiguity in it," and this project had one sitting in its own tree.

## Proving it fails, twice, before trusting it — and then again, repeatably

The issue's second criterion asks for exactly this: stamp a third symbol, watch the check catch it.
I did that by hand against the real tree in both directions — `@internal` planted on `Str::slug()`
(an already-frozen public symbol, the actual attack this check exists to catch) and `@internal`
removed from `SecretKey::bytes()` (an unguarded widen-by-removal) — reverting each mutation
immediately after confirming the failure message named the right symbol for the right reason.

That demonstration is real evidence and it is also gone the moment the branch is clean, which is
exactly the shape `verify_link_check.py` and `verify_bc_gate.py` already solved for other checks in
this file. `verify_internal_inventory.py` copies the real linter into a throwaway git repo, rewrites
its `EXPECTED_INTERNAL` constant to a small fixture set (a function replacement in `re.sub`, not a
string one — the fixture symbols carry literal backslashes that a template replacement would
misparse as escapes, the second small bug this session's own tests caught before CI would have), and
runs seven cases: both failure directions, both recognised shapes, the prose-vs-tag distinction
above, and that multiple violations are all reported rather than only the first.

## Where this leaves the project

No production code changed. One new consistency-lint check, its proof script, one `ci.yml` step,
this ADR. `python tools/consistency_lint.py` passes clean against the real five-symbol inventory,
and every other `tools/tests/verify_*.py` proof — nine of them, now ten — still passes unmodified.
