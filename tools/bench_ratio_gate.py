#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Daniel Polo
"""Cross-subject ratio check for phpbench runs — spec NFR-01's "≤ 3× manual construction".

PHPBench's own `@Assert` can compare a subject against `variant` (this run) or `baseline` (a
PREVIOUS run tagged with `--ref`) — checked directly against the expression-language docs and
the example benchmark before writing this tool — but **not** against another subject in the
*same* run. NFR-01's relative budget needs exactly that: hydration vs. manual construction,
measured together, on the same hardware, in the same process family.

The ratio is safely comparable across machines in a way an absolute microsecond figure is not:
both subjects run on identical hardware in the same invocation, so clock-speed and virtualization
noise apply to both numerator and denominator alike and mostly cancel out of the ratio.

    php vendor/bin/phpbench run src/bench/php/d4np/utils/HydrationBench.php \\
        --report=aggregate --dump-file=build/logs/hydration-bench.xml
    python tools/bench_ratio_gate.py build/logs/hydration-bench.xml \\
        --numerator benchHydrateWarm --denominator benchManualConstruction --max-ratio 3

Reads the `mode` time of each named subject from PHPBench's `--dump-file` XML and computes
numerator / denominator. **Absence is failure, never a pass**: a missing report, a missing
subject, or a zero-time denominator (which would make the ratio meaningless, not merely large)
all exit non-zero — the same discipline as `coverage_gate.py`.

Advisory today, not yet wired into CI: see roadmap item 3.5's journal entry and the follow-up
item it filed. Run standalone to get the current measured ratio.
"""

import argparse
import os
import sys
import xml.etree.ElementTree as ET


class GateError(Exception):
    """A condition that must fail the gate rather than be reported as a ratio."""


def mode_time_of(path, subject_name):
    """The `mode` time (in the report's own unit, PHPBench's base is microseconds) of the
    first `<subject name="...">` matching `subject_name` in a `--dump-file` XML report.
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

    for subject in root.iter("subject"):
        if subject.get("name") != subject_name:
            continue
        variant = subject.find("variant")
        if variant is None:
            raise GateError(f'subject "{subject_name}" in {path} has no <variant> element')
        stats = variant.find("stats")
        if stats is None or stats.get("mode") is None:
            raise GateError(f'subject "{subject_name}" in {path} has no stats/mode value')
        try:
            return float(stats.get("mode"))
        except ValueError as exc:
            raise GateError(f'subject "{subject_name}" mode value is not numeric: {exc}') from exc

    raise GateError(f'no subject named "{subject_name}" found in {path}')


def main(argv=None):
    ap = argparse.ArgumentParser(description="Compare two phpbench subjects' mode time as a ratio.")
    ap.add_argument("report", help="path to a phpbench --dump-file XML report")
    ap.add_argument("--numerator", required=True, help="subject name expected to be slower")
    ap.add_argument("--denominator", required=True, help="subject name to compare against")
    ap.add_argument("--max-ratio", type=float, required=True, help="fail if numerator/denominator exceeds this")
    args = ap.parse_args(argv)

    try:
        numerator = mode_time_of(args.report, args.numerator)
        denominator = mode_time_of(args.report, args.denominator)
    except GateError as exc:
        print(f"bench-ratio gate: FAIL\n\n  {exc}")
        return 1

    if denominator <= 0:
        print(
            f"bench-ratio gate: FAIL\n\n  denominator subject \"{args.denominator}\" measured "
            f"{denominator} — a zero or negative time makes the ratio meaningless, not merely "
            "large."
        )
        return 1

    ratio = numerator / denominator
    print(
        f"bench-ratio gate: {args.numerator} ({numerator:.3f}) / {args.denominator} "
        f"({denominator:.3f}) = {ratio:.2f}x (budget {args.max_ratio:g}x)"
    )

    if ratio > args.max_ratio:
        print(f"\nbench-ratio gate: FAIL — {ratio:.2f}x exceeds the {args.max_ratio:g}x budget.")
        return 1

    print("bench-ratio gate: OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
