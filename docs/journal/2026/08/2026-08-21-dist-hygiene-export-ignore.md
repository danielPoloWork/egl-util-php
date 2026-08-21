# 2026-08-21 — What Packagist ships, measured rather than sampled

Issue **#119**, first acceptance criterion. Route `fast / medium`; session model Sonnet 5 — matched.

The issue's own sample was a spot-check: 524 files at `v1.0.0`, six named categories
(`src/test`, `src/bench`, `docs`, `tools`, `.github`, `packages`). Measuring the current
tree with `git archive HEAD | tar -t` before writing a single rule found more than the
sample named: `.eados-core/` (the EADOS factory bundle), `orchestrator/`, `.specs/` (the
imported pre-rename spec), and every root governance file — `AGENTS.md`, `ROADMAP.md`,
`ISSUES.md`, `CLAUDE.md`, `CONTRIBUTING.md`, and their siblings. None of that reaches a
consumer's autoloader; all of it was shipping anyway.

**The criterion is stricter than the sample, and the stricter one is what governs.** The
issue's acceptance text says the dist should be "production code + LICENSE + README +
composer.json" — a closed bar, not an open list. So `.gitattributes` excludes by
*silence*: everything not named as needed is `export-ignore`d, rather than an allowlist of
`src/main` alone. An allowlist breaks the moment a new top-level file is added and nobody
remembers the rule exists; a deny-list keyed on the actual measured tree does not.

## Two dead rules caught before they shipped

Before trusting the list, every path was checked against `git ls-files` rather than
assumed from a mental model of the tree. `.claude/`, `.opencode/` and `.gemini/` are never
tracked — three lines of `export-ignore` that would have covered nothing, forever, and
looked like coverage on a read-through. `.php-cs-fixer.cache` is already gitignored for the
same reason. All four removed. **A rule for a path that is never tracked is not a safety
margin — it's a rule that never fires, and it makes the file harder to audit than if it
were simply absent.**

## Found on the way, fixed in passing

`.phpbench/storage/.../13527c...xml` — a benchmark run's own storage artifact — was
committed by accident in PR #42 (item 9.6's milestone) and has shipped in every dist since.
One file, but the deeper problem was `.gitignore` having no rule for `.phpbench/` at all,
so the next local benchmark run would have offered up more of the same. Untracked and
gitignored. Small enough to fold into this PR rather than spawn a separate one — it is the
same category of defect this item exists to fix, just already in the tree instead of newly
proposed.

## What was deliberately not done

The issue's second acceptance criterion asks for a `v1.0.1` release to carry this. Not done
here, on two grounds. First, cutting a release is the maintainer's call (AGENTS.md §11), not
an agent's to schedule. Second, the premise is arguably wrong: Milestone 14 is already
merged and additive, so the *next* tag is `v1.1.0` regardless of this change — a standalone
`v1.0.1` whose only content is a dist-hygiene fix would spend a full release cycle (signing
attempt, gate run, notes, the works) to ship one `.gitattributes` file. Recorded in
`ISSUES.md` rather than silently narrowed: the issue stays open on its second criterion, with
the disagreement stated so the maintainer can overrule it.

## Verification

`git archive HEAD | tar -t` after committing, checked against the criterion's closed bar —
not the sample list, which would have passed with the extra bloat still inside it.
