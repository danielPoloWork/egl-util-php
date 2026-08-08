#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Daniel Polo
"""Regression check between two phpbench runs — spec NFR-06's "regression > 10% fails".

**Why this compares two dump files rather than using phpbench's own `--ref`/`--assert`.**
PHPBench can assert against a stored previous run, and the expression
`mode(variant.time.avg) < mode(baseline.time.avg) +10%` works — verified, exit 0 within the
threshold and exit 2 outside it. But its result store lives in the working directory, and the
comparison this gate needs is between *two checkouts*, which means either a shared store path or
two worktrees fighting over one. Reading two `--dump-file` XMLs is the same information without
that problem, and matches `bench_ratio_gate.py`'s existing shape.

**Why a same-runner A/B and not a stored baseline.** Measured, not assumed. Across nine CI runs
on `master` where `QueryBuilder` and its benchmark were provably unchanged (`git diff` over both
paths, empty), `benchBuildFiveConditionSelect` ranged **2.684–3.767 µs — 40.4% peak to peak** on
GitHub's shared runners. A 10% gate against a stored baseline would fire on that noise alone.
Five consecutive phpbench passes *inside one job* instead spread **0.4–1.5%** for subjects above a
microsecond, so comparing base and head on the same runner leaves the 10% threshold roughly six
times the noise it has to clear.

    phpbench run --dump-file=build/logs/base.xml   # in a worktree at the base commit
    phpbench run --dump-file=build/logs/head.xml   # at HEAD
    python tools/bench_regression_gate.py build/logs/base.xml build/logs/head.xml --max-regression 10

**Absence is failure, never a pass** — the discipline `coverage_gate.py` and `bench_ratio_gate.py`
already hold to. A missing report, an unparseable one, or a subject whose baseline time is zero
all exit non-zero.

A subject present at HEAD but absent from the baseline is reported as **new** and does not fail:
a benchmark added by the very change under test has nothing it could have regressed against. A
subject that has *disappeared* is likewise reported, because a benchmark deleted quietly is how a
regression stops being visible.

**`--exclude` (roadmap item 10.9, ADR-0045).** Measured, not assumed: item 10.5's own CI run — a
test-only PR touching no file under `src/main` — failed this gate at
`FileSequenceBench::benchSequenceNext +40.10%` and `HashBench::benchVerifyArgon2id +13.75%`, and
the identical commit passed on re-run. Not ADR-0030's stored-baseline problem (base and HEAD were
measured on the same runner, as that ADR requires) — a narrower one: a same-runner A/B is still
not precise enough for a subject dominated by filesystem locking or by memory-hard hashing, where
the runner's own noise floor sits in the same order as the 10% budget. `--exclude NAME`
(repeatable, `Benchmark::subject` form) drops a named subject from the **pass/fail** decision
without dropping it from the **report** — it prints with a `skipped` marker rather than a
percentage, so an exclusion is a visible, auditable fact and never a silent one. The excluded
subject stays fully covered by its own **absolute** budget in `bench_budget_gate.py`, which is
what NFR-10 and NFR-05 actually specify (a ceiling, or a range); the relative check on top of that
was demanding 10%-precision these two subjects' underlying mechanism cannot supply on a shared
runner. ADR-0045 names the exact criterion for which subjects qualify, so this stays a rule, not
a per-name carve-out.

**`--control` (roadmap item 12.6, ADR-0057) — a different failure mode from `--exclude`'s.**
`--exclude` answers "this one subject is too noisy to judge on its own." Item 12.4's CI produced
a case `--exclude` cannot fix: the *same commit*, measured twice, failed **two different gates on
two different runs**. Run 1 failed the relative gate on five subjects, +11.19%…+19.44%, one of
them `RowNormalizerBench::benchInlineTrimHundredRows` — a hand-written inline loop that calls no
library code and therefore cannot regress. Run 2 passed the relative gate and failed the absolute
ceiling instead, with **every** subject 27–103% slower than run 1 on identical code. Both runs
measured a **slow runner**, not a code change: ADR-0030's same-runner A/B measures base and head
*sequentially*, so a runner that changes speed *between* the halves shifts every subject in one
direction, and the gate reports it as a regression in whichever half ran second.

A subject that calls no code this project owns cannot have regressed. If it moves anyway, the
*comparison* is what broke, not the code — and every other subject's number in that same run is
equally untrustworthy, because they were measured on the same compromised A/B. `--control
Benchmark::subject` (repeatable) names such a subject; when its **absolute** delta — regression or
improvement, since a runner change is directionally symmetric — exceeds `--max-regression`, the
gate reports the run **invalid** rather than reporting individual regressions it cannot vouch for,
and exits with a distinct code (`2`) so a human — or, eventually, CI automation — reads "re-run
this" rather than "investigate this diff." A name may not appear in both `--control` and
`--exclude`: a control's whole value is being a clean signal, and excluding it would silently
defeat the one property this flag depends on.
"""

import argparse
import os
import sys
import xml.etree.ElementTree as ET


class GateError(Exception):
    """A condition that must fail the gate rather than be reported as a measurement."""


def mode_times(path):
    """Every subject's `mode` time in a phpbench `--dump-file` XML, keyed `Benchmark::subject`.

    The benchmark class is included in the key because two benchmark files may legitimately
    contain a subject of the same name, and silently collapsing them would compare unrelated
    measurements.
    """
    if not os.path.isfile(path):
        raise GateError(
            f"no benchmark report at {path}. Produced by `phpbench run --dump-file=<path>`; "
            "a missing report means nothing was measured, which is a failure and not a pass."
        )

    try:
        root = ET.parse(path).getroot()
    except ET.ParseError as exc:
        raise GateError(f"{path} is not parseable as PHPBench's dump-file XML: {exc}") from exc

    times = {}
    for benchmark in root.iter("benchmark"):
        bench_name = (benchmark.get("class") or "?").rsplit("\\", 1)[-1]
        for subject in benchmark.iter("subject"):
            subject_name = subject.get("name")
            if subject_name is None:
                continue
            variant = subject.find("variant")
            if variant is None:
                continue
            stats = variant.find("stats")
            if stats is None or stats.get("mode") is None:
                continue
            try:
                times[f"{bench_name}::{subject_name}"] = float(stats.get("mode"))
            except ValueError as exc:
                raise GateError(
                    f'subject "{subject_name}" mode value is not numeric: {exc}'
                ) from exc

    if not times:
        raise GateError(
            f"{path} contains no subject with a stats/mode value. An empty report is a failure, "
            "not a pass: it means the suite did not measure anything."
        )

    return times


def main(argv=None):
    ap = argparse.ArgumentParser(
        description="Fail when any phpbench subject is more than N% slower than its baseline."
    )
    ap.add_argument("baseline", help="phpbench --dump-file XML from the base commit")
    ap.add_argument("current", help="phpbench --dump-file XML from the commit under test")
    ap.add_argument(
        "--max-regression",
        type=float,
        default=10.0,
        help="fail if any subject is slower than its baseline by more than this percentage "
        "(default 10, per spec NFR-06)",
    )
    ap.add_argument(
        "--exclude",
        action="append",
        default=[],
        metavar="Benchmark::subject",
        help="report this subject but never fail on it (repeatable) — for a subject whose "
        "absolute budget in bench_budget_gate.py is the real spec requirement and whose "
        "same-runner variance exceeds --max-regression on its own (ADR-0045)",
    )
    ap.add_argument(
        "--control",
        action="append",
        default=[],
        metavar="Benchmark::subject",
        help="a subject that calls no code this project owns (repeatable) — if its measured "
        "delta, in EITHER direction, exceeds --max-regression, the run is reported INVALID "
        "(exit 2) instead of reporting individual regressions a compromised A/B cannot "
        "vouch for (ADR-0057)",
    )
    args = ap.parse_args(argv)
    excluded = set(args.exclude)
    controls = set(args.control)

    overlap = sorted(excluded & controls)
    if overlap:
        print(
            "bench-regression gate: FAIL\n\n  named as both --control and --exclude: "
            f"{', '.join(overlap)}. A control's value is being a clean signal; excluding it "
            "would silently defeat the property --control depends on."
        )
        return 1

    try:
        base = mode_times(args.baseline)
        head = mode_times(args.current)
    except GateError as exc:
        print(f"bench-regression gate: FAIL\n\n  {exc}")
        return 1

    unknown_excludes = sorted(excluded - set(head))
    if unknown_excludes:
        print(
            "bench-regression gate: FAIL\n\n  --exclude named a subject absent from "
            f"{args.current}: {', '.join(unknown_excludes)}. An exclusion for a subject that "
            "does not exist silently stops meaning anything the day the subject is renamed."
        )
        return 1

    unknown_controls = sorted(controls - set(head))
    if unknown_controls:
        print(
            "bench-regression gate: FAIL\n\n  --control named a subject absent from "
            f"{args.current}: {', '.join(unknown_controls)}. A control that does not exist "
            "cannot detect anything, which is worse than not naming one at all — the run would "
            "look protected when it is not."
        )
        return 1

    rows = []
    regressions = []
    new_subjects = []
    skipped = []
    control_breaches = []

    for name in sorted(head):
        if name not in base:
            new_subjects.append(name)
            rows.append((name, None, head[name], None))
            continue

        before, after = base[name], head[name]
        if before <= 0:
            print(
                f"bench-regression gate: FAIL\n\n  baseline for \"{name}\" measured {before} — a "
                "zero or negative time makes the comparison meaningless, not merely large."
            )
            return 1

        delta = (after - before) / before * 100.0
        rows.append((name, before, after, delta))
        if name in controls and abs(delta) > args.max_regression:
            control_breaches.append((name, before, after, delta))
        if name in excluded:
            skipped.append((name, before, after, delta))
        elif delta > args.max_regression:
            regressions.append((name, before, after, delta))

    disappeared = sorted(set(base) - set(head))

    width = max(len(name) for name, *_ in rows)
    print(f"{'subject'.ljust(width)}  {'baseline':>10}  {'current':>10}  {'change':>9}")
    for name, before, after, delta in rows:
        if before is None:
            print(f"{name.ljust(width)}  {'—':>10}  {after:>10.3f}  {'new':>9}")
        elif name in excluded:
            print(f"{name.ljust(width)}  {before:>10.3f}  {after:>10.3f}  {'skipped':>9}")
        else:
            print(f"{name.ljust(width)}  {before:>10.3f}  {after:>10.3f}  {delta:>+8.2f}%")

    for name in new_subjects:
        print(f"\nbench-regression gate: NOTICE — \"{name}\" is new; nothing to compare against.")
    for name in disappeared:
        print(
            f"\nbench-regression gate: NOTICE — \"{name}\" was measured in the baseline and is "
            "gone at HEAD. A deleted benchmark is how a regression stops being visible."
        )
    for name, before, after, delta in skipped:
        print(
            f"\nbench-regression gate: NOTICE — \"{name}\" is excluded from pass/fail "
            f"(ADR-0045); measured {delta:+.2f}% here. Its absolute budget in "
            "bench_budget_gate.py is what actually gates it."
        )

    if control_breaches:
        print(
            f"\nbench-regression gate: INVALID — {len(control_breaches)} control subject(s) "
            f"moved by more than {args.max_regression:g}% while calling no code this project "
            "owns (ADR-0057):"
        )
        for name, before, after, delta in control_breaches:
            print(f"  {name}: {before:.3f} -> {after:.3f} ({delta:+.2f}%)")
        print(
            "\n  This run's own A/B comparison is not trustworthy: a runner-wide slowdown (or "
            "speed-up) moves every subject together, indistinguishably from a real regression. "
            "Any subject above also flagged as a regression in this run may be a real one, or "
            "may be this same noise — re-run the job on a fresh runner rather than investigating "
            "the diff."
        )
        return 2

    if regressions:
        print(
            f"\nbench-regression gate: FAIL — {len(regressions)} subject(s) exceeded the "
            f"{args.max_regression:g}% budget (spec NFR-06):"
        )
        for name, before, after, delta in regressions:
            print(f"  {name}: {before:.3f} -> {after:.3f} ({delta:+.2f}%)")
        return 1

    print(
        f"\nbench-regression gate: OK — no subject regressed by more than "
        f"{args.max_regression:g}%."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
