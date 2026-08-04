# 2026-08-04 — Closing the coverage floor, and measuring it for the first time

Roadmap item **2.7**, the last of Milestone 2. Route `standard / medium`; session model matched.

## The gap this closes

`AGENTS.md` §10 required "new code ≥ 90% line (finalized in an ADR)" and spec NFR-07 required
"PHPUnit line coverage ≥ 90%". **Nothing measured either.** The CI `build` job set up `pcov` and
then ran `vendor/bin/phpunit` with no `--coverage` flag and no threshold — driver loaded, no
report produced, no number compared. The matrix *looked* like it was enforcing a floor while
enforcing nothing, which is worse than an obviously absent check.

Filed at item 2.1, when that item could not verify its own coverage claim. Same shape as
ADR-0003: a policy stated in prose that no mechanism could contradict.

## Three things had to be settled first

1. **What compares the number.** PHPUnit 10 ships a dozen `--fail-on-*` switches
   (`--fail-on-risky`, `--fail-on-skipped`, …) and **no coverage threshold** among them —
   verified against `--help`, not assumed. So the comparison had to live outside PHPUnit.
2. **What "new code ≥ 90%" means.** `AGENTS.md` explicitly deferred this to an ADR, and the two
   readings differ enormously in cost. [ADR-0007](../../adr/0007-measure-total-line-coverage-against-a-floor.md)
   settles it as **total** line coverage — and, more importantly, has the tool print
   `NOT measured: per-diff coverage of changed lines` on every successful run. Per-diff is the
   stronger check; claiming it while measuring the total is the failure this avoids.
3. **What happens with no report.** The failure mode that makes a coverage gate worthless is
   passing when nothing was measured. `tools/coverage_gate.py` fails on a missing file, an
   unparseable one, a report with no project metrics, and — the important one — a report with
   **zero measurable statements**, which is exactly what a run with no driver produces.

## Proved it can fail before trusting it

All five paths exercised against synthetic Clover reports, since the item demands the gate be
shown failing rather than believed because it went green once:

| Case | Result |
|---|---|
| 95/100 lines, floor 90 | `OK`, exit 0 |
| 70/100 lines, floor 90 | `FAIL`, exit 1, **and names `/src/Bad.php` at 40%** worst-first |
| zero measurable statements | `FAIL` — "nothing was measured, so there is no coverage to compare" |
| unparseable XML | `FAIL` with the parse error |
| missing file | `FAIL` — "a missing report means coverage was never measured, which is a failure and not a pass" |

## Measured once, and `pcov` dropped from the matrix

Line coverage does not vary by PHP version, so three matrix cells would produce three identical
numbers at three times the cost. A dedicated `coverage` job measures on 8.3; the build matrix
proves the tests *pass* everywhere, the coverage job proves how much they *reach*. The matrix now
sets `coverage: none` — removing both the unused driver and the appearance that it was measuring
something.

## The number is genuinely unknown as this lands

`phpdbg` was available locally and turned out not to help — PHPUnit 10 requires pcov or Xdebug,
and neither is installed on this machine ("No code coverage driver available"). Downloading a
PHP extension binary was not something to do casually, so **this PR's own CI run is the first
time the coverage of this repository has ever been measured**.

If it comes back below 90, that is the finding — the stated policy was never met — and not a
defect in the gate. The threshold stays at the number `AGENTS.md` §10 and NFR-07 both state;
lowering it to make a first run green would defeat the item. `--coverage-text` runs alongside
the machine-read report precisely so the log shows the actual figure either way.

## Next

**Milestone 2 (`v0.2.0`) is complete** once this lands: exception hierarchy, `Str`, `File`,
`Env`, `Json`, the shared reflection cache, the T-05 suite, and now the coverage floor.

Milestone 3 (DTO & data mapping) opens — the first component group that actually consumes the
reflection cache from item 2.5, and where `MissingKeyException` / `UnknownKeyException` /
`TypeMismatchException` from item 2.1 get their real callers. Item 3.1 is flagged
`sets-pattern`.
