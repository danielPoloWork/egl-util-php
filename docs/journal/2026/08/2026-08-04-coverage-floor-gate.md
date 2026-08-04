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

## The first measurement: 82.08%, and the policy had never been met

`phpdbg` was available locally and turned out not to help — PHPUnit 10 requires pcov or Xdebug,
and neither is installed on this machine ("No code coverage driver available"). Downloading a
PHP extension binary was not something to do casually, so **this PR's own CI run was the first
time the coverage of this repository had ever been measured**.

It came back **82.08% (174/212 lines)** — below the floor `AGENTS.md` §10 and NFR-07 have both
stated since day one. That is the finding, not a defect in the gate: the bar was never met, and
nothing could have told anyone.

| File | Coverage | Uncovered | Why |
|---|---|---|---|
| `File.php` | 62.96% | **30** | the failure branches — lock, `chmod`, `rename`, `tempnam` fallback: exactly what ADR-0005 is about, never executed |
| `Version.php` | 0.00% | 1 | contains *only* a private constructor, unreachable by design |
| `Json.php`, `Env.php` | ~86% | 1 each | same private-constructor line |

**The threshold was not lowered.** ADR-0007 argues in writing that lowering it to make a first
run green would defeat the item, so the two honest routes were to raise coverage or to define
exclusions; the maintainer chose to raise it, in this PR.

### What was added, and why it is not coverage theatre

- **`FileFailureModesTest`** — the paths ADR-0005's design exists to handle: an unwritable
  directory, a lock file that cannot be opened, a failed `rename` (a non-empty directory
  standing where the target should be — no permission games needed, works on every platform)
  which must also leave **no temporary file behind**, an unreadable file for both `read()` and
  `mime()`, and a parent path that is a file rather than a directory. Each asserts the contract
  that makes `File` worth using over the native functions: *it fails loudly*. POSIX-mode tests
  skip on Windows, and skip again when modes are not enforced for the process (running as root)
  — detected empirically rather than by assuming the CI user.
- **`StaticUtilityContractTest`** — the private constructors. It asserts the guard **is** there
  (`final`, not instantiable, constructor private and parameterless) *and* drives that
  constructor through reflection to confirm it is **inert**. The pairing is the point: a
  constructor nobody has ever run is a constructor nobody has checked, and a `sleep(10)` or a
  throw hiding in one would stay invisible until the day someone reflected on the class.

One wrinkle worth recording: the "every public method is static" test asserted *inside* a loop,
so `Version` — which has no public methods at all — ran it zero times and PHPUnit flagged the
test risky. With `failOnRisky` on, that would have failed CI. Restructured to collect offenders
and assert once, which always asserts.

159 tests, 328 assertions locally (7 skipped, all Windows-only); PHPStan max clean.

## Next

**Milestone 2 (`v0.2.0`) is complete** once this lands: exception hierarchy, `Str`, `File`,
`Env`, `Json`, the shared reflection cache, the T-05 suite, and now the coverage floor.

Milestone 3 (DTO & data mapping) opens — the first component group that actually consumes the
reflection cache from item 2.5, and where `MissingKeyException` / `UnknownKeyException` /
`TypeMismatchException` from item 2.1 get their real callers. Item 3.1 is flagged
`sets-pattern`.
