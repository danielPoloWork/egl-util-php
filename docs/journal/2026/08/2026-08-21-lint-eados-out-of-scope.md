# 2026-08-21 — The link checker merged green and left master red

A defect fix rather than a roadmap item, annotating **ADR-0069** (ROADMAP 13.4). Route
`standard / medium`; session model Opus 5 — matched. Found while working item 13.2 in a worktree,
which is what surfaced it.

## The defect

`consistency_lint.py`'s new `links` check fails on `master` — run 32476537522, six
`link target does not exist` findings, all under `.eados-core/`. PR #146 added that check, proved it
could fail, ran it locally, and merged green.

Both things are true because `.gitignore:82-85` excludes `/.eados-core/*` and re-includes only
`learning/runs/`. The `.eados-core/` bundle is the EADOS factory tooling, copied in to regenerate
this repository and deliberately never committed. So the six targets exist on a maintainer's machine
and in no clone: the check passes for anyone holding the bundle and fails in CI for everyone.

**The verdict depended on the host.** That is the defect — not the six links.

I found it because item 13.2 was done in a `git worktree`, which is a clean checkout of tracked
files only. Working in the main checkout would have shown green, and I would have pushed and let CI
tell me. Isolation bought a diagnosis instead of a red build.

## The fix, and the one distinction that matters

A link whose target `.gitignore` excludes is out of scope, for the same reason an external URL is:
absent from every clone by design, so unresolvable rather than broken. The count of skipped
references is printed beside the count resolved — §3's own idiom, and ADR-0007's before it.

**Keyed on ignore status, not on the file being missing.** This is the whole fix. "Skip links whose
target is absent" would have turned master green while leaving the host-dependence exactly where it
was — and would have gutted the check, since a missing target *is* the thing it looks for. Keying on
ignore status makes the verdict identical everywhere.

The status is asked of `git check-ignore` rather than pattern-matched in the lint, so the rule lives
where the project already states it and cannot drift from `.gitignore` as either file is edited.

## Proved three ways, because turning a red check green is the suspicious direction

A change that silences findings has to demonstrate it did not silence *everything*:

1. a genuinely broken link (`docs/adr/9999-this-does-not-exist.md`) — still **FAIL**;
2. a broken link inside `.eados-core/learning/runs/`, the subtree `.gitignore` **re-includes** —
   still **FAIL**. So the exclusion follows the ignore rule's real boundary, not "anything under
   `.eados-core/`";
3. **the host-independence proof**: create `.eados-core/tools/autotune.py`, one of the six ignored
   targets, and re-run. Counts identical — 711 resolved, 6 skipped. Before this fix, that file's
   mere presence flipped the verdict from `FAIL` to `OK`. That is the defect, demonstrated
   disappearing.

Each plant was reverted and verified by **absence of the original**, not by presence of the
replacement — item 11.2's rule, since a check for the replacement can pass on text that was already
there.

Arithmetic checks out too: 717 resolved before, 711 + 6 skipped after.

## The Windows trap, worth carrying

The first implementation reported **nothing** ignored, and the reason is not obvious. It piped
newline-separated paths to `git check-ignore --stdin` in text mode. On Windows, text mode translates
the outgoing `\n` to `\r\n`, so git receives every path with a trailing carriage return — and then
C-quotes the reply, because a name containing `\r` is unusual:

```
'".eados-core/tools/autotune.py\r"'
```

It matched the ignore rule and came back unrecognisable. Two bugs, one symptom, both invisible
without printing the raw set. Fixed with `-z` over bytes, which removes the delimiter translation
and the quoting together.

## The lesson ADR-0069 §5's proof did not cover

§5 shipped a proof that the check could fail. That is this project's standing method and it is the
right one. It still missed this, because the proof ran on a machine holding files the check depends
on and CI never sees.

**A checker's first green run is not evidence it will stay green elsewhere.** Prove a new gate on a
clean clone — or read its CI run — before treating a local green as the verdict. This is the same
family as items 10.10/10.11 (a figure inherits the machine it was taken on) and 12.3 (a PHP 8.1
constraint probed on 8.3), now for a *pass/fail verdict* rather than a number or a language rule.

Filed by the same reasoning: nothing in CI runs a documentation example either, which is item 13.3's
whole argument. Different check, identical gap.
