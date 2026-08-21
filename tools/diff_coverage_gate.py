#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Daniel Polo
"""Per-diff coverage gate for egl-util-php — the hole ADR-0007 documented in itself.

`tools/coverage_gate.py` enforces **total** line coverage against spec NFR-07's 90% floor, and
says on every run what it does not measure: with the suite well above the floor, an untested
addition rides inside the headroom without the total moving enough to notice. ADR-0007 recorded
that as its own limitation; issue #109 is the SDET Lead asking for the other half.

This tool intersects a Clover report with the lines a change actually touched:

    git fetch --no-tags origin master
    php -d pcov.enabled=1 vendor/bin/phpunit --coverage-clover build/logs/clover.xml
    python tools/diff_coverage_gate.py build/logs/clover.xml --base origin/master --min 90

**The floor is NFR-07's own 90%, applied to a different denominator — not a new number.** ADR-0040
reserves budget numbers for the spec, and inventing a second coverage threshold here would be
exactly that. The argument for reusing it: a change that is itself at least 90% covered cannot drag
a 90%-covered library below its floor.

**Only coverable statements count.** A changed line appears in the denominator only if Clover
reports it as a statement, so blank lines, comments, docblocks, closing braces and declarations are
neither credit nor penalty. That is also what makes `@codeCoverageIgnore` the one escape hatch:
PHPUnit drops annotated lines from the report entirely, so they leave the denominator rather than
sitting in it uncovered. There are **zero** uses of it in this tree at the time this gate landed, so
`grep -rn codeCoverageIgnore src/` is the review list — the property ADR-0041 built for
`SqlStatement::composed()`.

**Absence is failure, never a pass** — the stance `coverage_gate.py` already takes. A missing
report, an unparseable one, a base ref git cannot resolve, or a report that measured zero statements
all exit non-zero. A gate that goes green because it could not run is worse than no gate.

**A change touching no coverable statement passes**, loudly. A documentation-only or
configuration-only diff has an undefined per-diff percentage, and the honest answer is to say so
rather than to divide by zero in either direction.

Exits 0 when the floor is met or nothing coverable changed, 1 otherwise, 2 when it could not run.
"""

import argparse
import os
import re
import subprocess
import sys
import xml.etree.ElementTree as ET

DEFAULT_MIN = 90.0
DEFAULT_PATHS = ("src/main",)

# `@@ -old,count +new,count @@` — the counts are optional and default to 1.
HUNK = re.compile(r"^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@")


class GateError(Exception):
    """A condition that must fail the gate rather than be reported as coverage."""


def changed_lines(base, head, paths):
    """Line numbers added or modified per file, as {repo_relative_path: {line, ...}}.

    `--unified=0` so there is no context to mistake for a change, and `--diff-filter=d` so a
    deleted file contributes nothing — its lines do not exist to be covered. A renamed file's new
    side is reported as an addition, which is the correct reading: the lines are new at that path.

    The three-dot range is deliberate. `base...head` diffs against the merge base, so a change is
    judged on what it did rather than on whatever else landed on the base branch meanwhile — with
    two dots, every commit merged into master since the branch started would count as this
    change's untested lines.
    """
    command = [
        "git", "diff", "--unified=0", "--diff-filter=d", "--no-color",
        f"{base}...{head}", "--", *paths,
    ]

    try:
        completed = subprocess.run(command, capture_output=True, text=True, check=False)
    except OSError as exc:
        raise GateError(f"cannot run git: {exc}") from exc

    if completed.returncode != 0:
        raise GateError(
            f"`{' '.join(command)}` failed:\n\n    {completed.stderr.strip()}\n\n"
            "In CI this usually means the base ref was never fetched: actions/checkout defaults "
            "to a single commit, so `origin/master` does not exist until it is fetched or "
            "`fetch-depth: 0` is set. Refusing rather than treating an unresolvable base as an "
            "empty diff, which would report 100% of nothing."
        )

    per_file = {}
    current = None

    for line in completed.stdout.splitlines():
        if line.startswith("+++ "):
            target = line[4:].strip()
            current = None if target == "/dev/null" else target[2:] if target.startswith("b/") else target
            continue

        if current is None:
            continue

        match = HUNK.match(line)
        if match is not None:
            start = int(match.group(1))
            count = 1 if match.group(2) is None else int(match.group(2))
            if count:
                per_file.setdefault(current, set()).update(range(start, start + count))

    return per_file


def coverage_by_file(report):
    """Statement coverage per file, as {repo_relative_path: {line: hit_count}}.

    Clover records absolute paths from the machine that produced the report, so paths are matched
    by their tail against the repository-relative names the diff produced. Only `type="stmt"`
    lines are statements; Clover also emits `type="method"` rows, and counting those would put a
    method's signature line in the denominator twice over.
    """
    if not os.path.isfile(report):
        raise GateError(
            f"no coverage report at {report}. It comes from "
            "`phpunit --coverage-clover <path>` with pcov or Xdebug loaded; a missing report "
            "means coverage was never measured, which is a failure and not a pass."
        )

    try:
        root = ET.parse(report).getroot()
    except ET.ParseError as exc:
        raise GateError(f"{report} is not parseable as Clover XML: {exc}") from exc

    project = root.find("project")
    if project is None:
        raise GateError(f"{report} has no <project> element — not a Clover report?")

    per_file = {}
    total_statements = 0

    for element in project.iter("file"):
        name = element.get("name") or element.get("path")
        if not name:
            continue

        lines = {}
        for line in element.iter("line"):
            if line.get("type") != "stmt":
                continue
            try:
                number = int(line.get("num", ""))
                count = int(line.get("count", "0"))
            except ValueError:
                continue
            lines[number] = count

        total_statements += len(lines)
        per_file[name.replace("\\", "/")] = lines

    if total_statements == 0:
        raise GateError(
            f"{report} reports zero statement lines. Either the source filter matched no files "
            "or no coverage driver was active — either way nothing was measured, so there is no "
            "coverage to intersect."
        )

    return per_file


def match_report_path(relative, report_files):
    """The report's key for a repository-relative path, or None.

    Suffix matching, longest first, so that `src/main/php/d4np/utils/Support/Str.php` cannot be
    satisfied by some unrelated `Str.php` deeper in a vendor tree that happens to share a tail.
    """
    normalized = relative.replace("\\", "/")

    if normalized in report_files:
        return normalized

    candidates = [key for key in report_files if key.endswith("/" + normalized)]
    candidates.sort(key=len)

    return candidates[0] if candidates else None


def evaluate(report, base, head, paths):
    """(covered, coverable, [(path, line), ...]) over the changed, coverable lines."""
    diff = changed_lines(base, head, paths)
    report_files = coverage_by_file(report)

    covered = 0
    coverable = 0
    uncovered = []

    for path in sorted(diff):
        key = match_report_path(path, report_files)
        if key is None:
            # A changed file the report does not mention is not silently excused: it is either a
            # file the coverage source filter excludes (a benchmark, a fixture) or one where every
            # changed line is a comment. Both contribute no statements, which is what the
            # per-file report says too, so there is nothing to add either way.
            continue

        statements = report_files[key]
        for line in sorted(diff[path]):
            if line not in statements:
                continue
            coverable += 1
            if statements[line] > 0:
                covered += 1
            else:
                uncovered.append((path, line))

    return covered, coverable, uncovered


def main(argv=None):
    ap = argparse.ArgumentParser(
        description="Fail when the lines a change touched are covered below the floor.",
    )
    ap.add_argument("report", nargs="?", default="build/logs/clover.xml",
                    help="path to the Clover XML report (default: build/logs/clover.xml)")
    ap.add_argument("--base", default="origin/master",
                    help="ref the change is measured against (default: origin/master)")
    ap.add_argument("--head", default="HEAD", help="ref being measured (default: HEAD)")
    ap.add_argument("--min", type=float, default=DEFAULT_MIN,
                    help=f"minimum percent of changed statements covered (default: {DEFAULT_MIN:g})")
    ap.add_argument("--path", action="append", dest="paths", metavar="PATH",
                    help=f"limit to this path, repeatable (default: {' '.join(DEFAULT_PATHS)})")
    ap.add_argument("--report-only", action="store_true",
                    help="print the figure and exit 0 even when it is below the floor")
    args = ap.parse_args(argv)

    paths = tuple(args.paths) if args.paths else DEFAULT_PATHS

    try:
        covered, coverable, uncovered = evaluate(args.report, args.base, args.head, paths)
    except GateError as exc:
        print(f"diff coverage gate: CANNOT RUN\n\n  {exc}")
        return 2

    if coverable == 0:
        print("diff coverage gate: no coverable statements changed under "
              f"{', '.join(paths)} between {args.base} and {args.head}.")
        print("  Nothing to measure, so nothing to enforce — a docs-only or config-only change "
              "reports this rather than a percentage.")
        return 0

    pct = covered / coverable * 100
    print(f"diff coverage gate: {covered}/{coverable} changed statements covered = "
          f"{pct:.2f}% (floor {args.min:g}%)")

    if pct + 1e-9 >= args.min:
        print("diff coverage gate: OK")
        # Named even on success, and that is the point. The first three real readings this gate
        # ever produced were 100%, 100% and 95.43% — and the 95.43% meant eight statements in the
        # rate limiter were never executed, which nobody could see because the first version of
        # this tool enumerated the lines only when it failed. A passing run that knows exactly
        # which lines are dead and says nothing is withholding the actionable half of its own
        # measurement.
        if uncovered:
            print(f"\n  {len(uncovered)} changed statement(s) inside the floor are still never "
                  "executed:")
            for path, line in uncovered[:40]:
                print(f"    {path}:{line}")
            if len(uncovered) > 40:
                print(f"    … and {len(uncovered) - 40} more")
        return 0

    print(f"\ndiff coverage gate: {'FAIL' if not args.report_only else 'BELOW FLOOR (report-only)'} "
          f"— {len(uncovered)} changed statement(s) are never executed by the suite.\n")
    for path, line in uncovered[:40]:
        print(f"    {path}:{line}")
    if len(uncovered) > 40:
        print(f"    … and {len(uncovered) - 40} more")

    print("\n  Cover them, or — if a line provably cannot execute — say so in the source with "
          "@codeCoverageIgnore\n  and a comment giving the reason. That annotation removes the "
          "line from the report rather than\n  leaving it uncovered, and there were zero uses of "
          "it in this tree when this gate landed, so\n  `grep -rn codeCoverageIgnore src/` stays "
          "the whole review list.")

    return 0 if args.report_only else 1


if __name__ == "__main__":
    sys.exit(main())
