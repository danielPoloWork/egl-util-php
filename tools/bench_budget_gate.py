#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Daniel Polo
"""Absolute-budget check for phpbench runs — the NFR ceilings, and NFR-05's range.

`bench_regression_gate.py` catches a change that makes something slower than it was.
It cannot catch slow drift: twenty commits at +9% each pass every one of its checks and still
double the runtime. That is what an absolute ceiling is for, and why both gates exist.

**Why these are gated on CI hardware at all, when ADR-0018 declined to.** ADR-0018's reasoning was
sound and its evidence was thin: a slower runner might fail for a reason unrelated to a regression.
Measured, the headroom turns out to be large — NFR-01's warm hydration runs ~0.97 µs against a 5 µs
ceiling, NFR-03's five-condition build ~3.46 µs against 10 µs — while the *worst* observed
cross-runner spread is 40%. A ceiling with 2.9x of headroom is not something 40% of noise reaches.
If GitHub's runners ever change enough to breach one, that is itself worth knowing, and the failure
message says so rather than pretending a regression happened.

These are **not** the reference machine of spec NFR-06 (a Ryzen 7 5800X). A pass here means "not
breached on this runner", which is weaker than the specification's claim and is why the message
prints the measured value rather than only OK.

**A range, not just a ceiling, for NFR-05.** `Hash::make` is budgeted at 50-200 ms and the *lower*
bound is the serious one: a hash that got faster is a hash that got weaker, which no ceiling would
ever notice.

    phpbench run --dump-file=build/logs/bench.xml
    python tools/bench_budget_gate.py build/logs/bench.xml \\
        --budget benchHydrateWarm=5 --range benchArgon2idDefaults=50000..200000

Times are in the report's own unit, which is microseconds for phpbench — so NFR-05's milliseconds
are expressed as microseconds above, deliberately, rather than the tool guessing a scale.

**Absence is failure, never a pass**, as in `coverage_gate.py` and the sibling bench gates: a
missing report, or a named subject that is not in it, exits non-zero. A budget that silently
matches nothing is worse than no budget.
"""

import argparse
import os
import sys
import xml.etree.ElementTree as ET


class GateError(Exception):
    """A condition that must fail the gate rather than be reported as a measurement."""


def mode_times(path):
    """Every subject's `mode` time in a phpbench `--dump-file` XML, keyed by subject name."""
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
    for subject in root.iter("subject"):
        name = subject.get("name")
        if name is None:
            continue
        variant = subject.find("variant")
        if variant is None:
            continue
        stats = variant.find("stats")
        if stats is None or stats.get("mode") is None:
            continue
        try:
            times[name] = float(stats.get("mode"))
        except ValueError as exc:
            raise GateError(f'subject "{name}" mode value is not numeric: {exc}') from exc

    if not times:
        raise GateError(
            f"{path} contains no subject with a stats/mode value. An empty report is a failure, "
            "not a pass."
        )

    return times


def parse_budget(raw):
    """`NAME=MAX` into (name, None, max)."""
    if "=" not in raw:
        raise GateError(f'--budget expects NAME=MAX, got "{raw}"')
    name, _, ceiling = raw.partition("=")
    try:
        return name, None, float(ceiling)
    except ValueError as exc:
        raise GateError(f'--budget ceiling for "{name}" is not numeric: {exc}') from exc


def parse_range(raw):
    """`NAME=MIN..MAX` into (name, min, max)."""
    if "=" not in raw or ".." not in raw:
        raise GateError(f'--range expects NAME=MIN..MAX, got "{raw}"')
    name, _, span = raw.partition("=")
    low, _, high = span.partition("..")
    try:
        return name, float(low), float(high)
    except ValueError as exc:
        raise GateError(f'--range bounds for "{name}" are not numeric: {exc}') from exc


def main(argv=None):
    ap = argparse.ArgumentParser(description="Check phpbench subjects against absolute NFR budgets.")
    ap.add_argument("report", help="path to a phpbench --dump-file XML report")
    ap.add_argument("--budget", action="append", default=[], metavar="NAME=MAX",
                    help="fail if the subject's mode time exceeds MAX (repeatable)")
    ap.add_argument("--range", action="append", default=[], metavar="NAME=MIN..MAX",
                    help="fail if the subject's mode time falls outside [MIN, MAX] (repeatable)")
    args = ap.parse_args(argv)

    if not args.budget and not args.range:
        print("bench-budget gate: FAIL\n\n  no --budget or --range given; there is nothing to check.")
        return 1

    try:
        times = mode_times(args.report)
        checks = [parse_budget(b) for b in args.budget] + [parse_range(r) for r in args.range]
    except GateError as exc:
        print(f"bench-budget gate: FAIL\n\n  {exc}")
        return 1

    failures = []
    width = max(len(name) for name, _, _ in checks)

    for name, low, high in checks:
        if name not in times:
            print(f"bench-budget gate: FAIL\n\n  no subject named \"{name}\" in {args.report}. A "
                  "budget that matches nothing is worse than no budget.")
            return 1

        measured = times[name]
        bounds = f"<= {high:g}" if low is None else f"{low:g}..{high:g}"
        breached = measured > high or (low is not None and measured < low)

        print(f"{name.ljust(width)}  {measured:>12.3f}  budget {bounds:<18} "
              f"{'BREACHED' if breached else 'ok'}")

        if breached:
            failures.append((name, measured, low, high))

    if failures:
        print(f"\nbench-budget gate: FAIL — {len(failures)} budget(s) breached:")
        for name, measured, low, high in failures:
            if low is not None and measured < low:
                print(f"  {name}: {measured:.3f} is BELOW the {low:g} floor. For a work-factor "
                      "budget this means the work got cheaper, which means weaker — not faster.")
            else:
                print(f"  {name}: {measured:.3f} exceeds {high:g}.")
        print(
            "\nNote: this runner is not spec NFR-06's reference machine. A breach here is either a "
            "real regression or a change in the measurement environment; both are worth knowing, "
            "and the numbers above say which is plausible."
        )
        return 1

    print("\nbench-budget gate: OK — every budget respected on this runner.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
