#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Daniel Polo
"""Coverage-floor gate for egl-util-php — the mechanical check behind ADR-0007.

`AGENTS.md` §10 and spec NFR-07 both require **≥ 90% line coverage**. Until this tool existed
nothing measured it: the CI `build` job set up `pcov` and then ran `vendor/bin/phpunit` with no
`--coverage` flag and no threshold, so the number was neither produced nor compared. PHPUnit 10
offers a dozen `--fail-on-*` switches and no coverage threshold among them (verified against
`--help`), so the comparison has to live outside it.

Reads a Clover XML report and compares the project's line coverage to a floor.

    php -d pcov.enabled=1 vendor/bin/phpunit --coverage-clover build/logs/clover.xml
    python tools/coverage_gate.py build/logs/clover.xml --min 90

**Absence is failure, never a pass.** A missing report, an unparseable one, or one that measured
zero statements all exit non-zero. A coverage gate that goes green when the driver was not
installed is worse than no gate: it reports a floor nobody is standing on.

Exits 0 when the floor is met, 1 otherwise.
"""

import argparse
import os
import sys
import xml.etree.ElementTree as ET

DEFAULT_MIN = 90.0


class GateError(Exception):
    """A condition that must fail the gate rather than be reported as coverage."""


def load_project_metrics(path):
    """The aggregate `<metrics>` under `<project>`, as (statements, covered).

    Clover nests per-file metrics inside the project element and repeats the totals on the
    project's own `<metrics>` child. That aggregate is the one to compare; summing the per-file
    ones by hand would silently disagree with every other Clover consumer.
    """
    if not os.path.isfile(path):
        raise GateError(
            f"no coverage report at {path}. The report is produced by "
            "`phpunit --coverage-clover <path>` and needs pcov or Xdebug loaded; a missing "
            "report means coverage was never measured, which is a failure and not a pass."
        )

    try:
        root = ET.parse(path).getroot()
    except ET.ParseError as exc:
        raise GateError(f"{path} is not parseable as Clover XML: {exc}") from exc

    project = root.find("project")
    if project is None:
        raise GateError(f"{path} has no <project> element — not a Clover report?")

    metrics = project.find("metrics")
    if metrics is None:
        raise GateError(f"{path} has no project-level <metrics> element")

    try:
        statements = int(metrics.get("statements", "0"))
        covered = int(metrics.get("coveredstatements", "0"))
    except ValueError as exc:
        raise GateError(f"{path} has non-numeric metrics: {exc}") from exc

    if statements == 0:
        raise GateError(
            f"{path} reports zero measurable statements. Either the source filter matched no "
            "files or no driver was active — either way nothing was measured, so there is no "
            "coverage to compare."
        )

    return statements, covered


def files_below(path, minimum):
    """Per-file coverage below `minimum`, worst first, as (file, pct, covered, statements).

    Reported on failure so the output says where to look, rather than only that a number was
    too small.
    """
    root = ET.parse(path).getroot()
    project = root.find("project")
    if project is None:
        return []

    out = []
    for f in project.iter("file"):
        m = f.find("metrics")
        if m is None:
            continue
        statements = int(m.get("statements", "0"))
        if statements == 0:
            continue
        covered = int(m.get("coveredstatements", "0"))
        pct = covered / statements * 100
        if pct < minimum:
            name = f.get("name") or f.get("path") or "<unknown>"
            out.append((name, pct, covered, statements))

    out.sort(key=lambda row: row[1])
    return out


def main(argv=None):
    ap = argparse.ArgumentParser(description="Fail when line coverage is below the floor.")
    ap.add_argument("report", nargs="?", default="build/logs/clover.xml",
                    help="path to the Clover XML report (default: build/logs/clover.xml)")
    ap.add_argument("--min", type=float, default=DEFAULT_MIN,
                    help=f"minimum line coverage percent (default: {DEFAULT_MIN:g})")
    args = ap.parse_args(argv)

    try:
        statements, covered = load_project_metrics(args.report)
    except GateError as exc:
        print(f"coverage gate: FAIL\n\n  {exc}")
        return 1

    pct = covered / statements * 100
    print(f"coverage gate: {covered}/{statements} lines = {pct:.2f}% (floor {args.min:g}%)")

    if pct + 1e-9 < args.min:
        print("\ncoverage gate: FAIL — below the floor required by AGENTS.md §10 and spec NFR-07.\n")
        below = files_below(args.report, args.min)
        if below:
            print(f"  {len(below)} file(s) under {args.min:g}%, worst first:")
            for name, file_pct, file_covered, file_statements in below[:20]:
                print(f"    {file_pct:6.2f}%  {file_covered:4d}/{file_statements:<4d}  {name}")
            if len(below) > 20:
                print(f"    … and {len(below) - 20} more")
        return 1

    print("coverage gate: OK")

    # What this gate does NOT measure, stated in its own output rather than left to the reader
    # (ADR-0007): the figure above is TOTAL line coverage, not the coverage of the lines this
    # change touched. A large well-covered codebase can absorb an untested addition without the
    # total moving. Diff-aware coverage needs the base ref and a per-line comparison, which is
    # deliberately out of scope here.
    print("  measured: total line coverage. NOT measured: per-diff coverage of changed lines.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
