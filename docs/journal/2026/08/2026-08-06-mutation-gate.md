# 2026-08-06 — A gate that waited ten milestones, and a review that found it

Not a planned item. This session began as a **review of item 10.1**, requested after that
item shipped, and produced two roadmap items: **10.7** (filed only) and **10.8** (this
commit's deliverable). Both are things the review found, which is the argument for reviewing
one's own completed work at all.

## The one that matters most: a gate green on nothing

`infection.json5` never existed. The `mutation` job has carried the self-enabling config
guard since item 1.9 — *"job self-enables when it lands"* — and it never landed, so for ten
milestones the job resolved `present=false`, skipped every step, and reported **pass in about
seven seconds**. Spec NFR-07's *"≥ 70% mutation score on Security/Database/Dto"* has been
unenforced since it was written.

This is **item 2.7's finding, verbatim in shape**: there, the `build` job installed pcov and
ran PHPUnit with no `--coverage` flag, so the 90% floor was *"neither produced nor compared"*.
Two instances make it a pattern worth naming: the self-enabling guard (lesson L-0010) is a
good idea with one blind spot — **nothing ever asks whether the thing it waits for arrived.**
A guard still waiting after ten milestones is, in the checks column, indistinguishable from a
gate that passes.

I found it while reviewing what I had written in PR #57's body, where I had listed
`quality / infection mutation score` among the passing checks. True of the job. False of the
requirement. That is the specific way this failure survives: it is reported honestly by
everyone who reads the checks column instead of the job.

## Three obstacles, all found by running the step instead of reading it

1. **`vendor/bin/infection` never existed and cannot.** Infection is not in `require-dev`, and
   it cannot be: from 0.29.10 every release needs PHP `^8.2`/`^8.3` against this library's 8.1
   floor, and every older release conflicts with versions already locked here
   (`justinrainbow/json-schema` 6.10 vs their `^5.2`, `fidry/cpu-core-counter` 1.3 vs `^0.4`,
   `composer/xdebug-handler` 3.0 vs `^1.3`). This is item 7.2's obstacle in a new costume, and
   it takes item 7.2's answer: a throwaway project (ADR-0031), so the 8.1 matrix cell and
   `--prefer-lowest` never learn about it.
2. **`--only-covered` is not an Infection option.** The generated step passed it. Modern
   Infection excludes uncovered code by default and spells the inverse `--with-uncovered`. So
   the step had *two* independent reasons to fail the moment its guard opened.
3. **Infection could not find PHPUnit** across two vendor directories — its finder asks
   `composer config bin-dir`, falls back to `getcwd() . '/vendor/bin'` and edits `PATH`.
   Replaced with an explicit `phpUnit.customPath`: a heuristic that happens to work on one
   platform is not a configuration.

## The number, and the number I did not use

First real run: **MSI 79%** — 443 mutants killed, 117 covered mutants escaped, mutation code
coverage 100%, 2m16s. NFR-07's 70% is met with nine points of headroom.

The tempting move is to set the floor at 79 and lock the current level in. I did not, and the
reason is not caution: **the spec owns that number.** Raising it here would be this item
inventing a requirement, in an ADR, as a side effect of the run that first measured it. If 79
should be the floor, that is a spec amendment with its own argument.

The 117 escaped mutants are now visible — kept as a CI artifact with a per-mutator breakdown —
and deliberately not chased in this item. Infection's own output warns that some are
inevitably harmless, and the first one it reported proves the point: `0` → `-1` in a
`DatabaseException`'s unused *code* argument.

## Proving it, because a threshold nobody has seen reject anything is not a gate

Floor temporarily raised to 95 against the same 79% measurement: **CI red**. Reverted in the
next commit. Both commits are in this PR's history on purpose — the same measurement, opposite
verdicts, which is what distinguishes a gate from a decoration.

## The other finding, filed not fixed: item 10.7

Item 10.1 shipped `SqlStatement` and I wrote in ADR-0039 that *"a type-level guarantee catches
what a string never announces"* — while shipping the version with no type-level guarantee. The
Alternatives section weighed a *runtime* assertion and rejected it correctly, and never
considered the static one: **`@param literal-string`**, which PHPStan enforces at the max level
this project already runs. Verified against this repository's own `phpstan.neon` before filing:
an interpolated `"… {$v} …"` and a `'…' . $v` are both **rejected**, while hand-written literal
SQL with placeholders — exactly what FR-33 exists to permit — passes.

Filed as 10.7 rather than fixed here, at the maintainer's direction, with the note that it
should land before 10.3/10.4 — `Repository` and `TableGateway` are the callers the guarantee is
for.

## Lesson

Read the job, not the checks column: a gate can be green because it ran and passed, or green
because it ran nothing, and the two look identical from outside.
