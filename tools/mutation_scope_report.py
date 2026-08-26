#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Daniel Polo
"""Per-namespace mutation scores from one Infection run — issue #99's sibling, issue #108.

`infection.json5` gates three namespaces (`Security`, `Database`, `Dto`) at NFR-07's 70% floor.
Issue #108 asks whether `Persistence` — data-mapping and injection-adjacent, per its own gateway
injection suite — and `Http` should join them. That is a **spec amendment**, and ADR-0040 was
explicit that the spec owns NFR-07's number: *"a change to it is a spec amendment with its own
reasoning, not a side effect of the run that first measured it."* So a floor cannot be chosen here.
A **number** can be measured, and this is what measures it.

    python tools/mutation_scope_report.py build/logs/infection-fulltree.json

**Why derive this from one full-tree run instead of running Infection once per namespace.**
A matrix leg per namespace would re-run the whole test suite once per leg for the same information:
Infection's JSON log already records every mutant with its `originalFilePath`, so the split is
arithmetic over a report that already exists. One run, every namespace's number.

**The self-check is the point, not a nicety.** This script re-implements Infection's own MSI
formula, and a re-implementation that quietly disagrees with the tool it is quoting would produce
a table of plausible, wrong numbers — the exact shape of finding a maintainer would then set a floor
from. So it computes the *full-tree* MSI from the mutant arrays and compares it against the `msi`
Infection itself wrote into `stats`. If those disagree beyond a rounding tolerance, every
per-namespace row below is untrustworthy and this exits non-zero rather than printing them.

**Absence is failure**, the discipline every gate in `tools/` holds to: a missing log, an
unparseable one, or one with no mutants at all exits non-zero. A report that cannot be read is not
a report saying there is nothing to see.

**The score itself is advisory.** This never fails because a number is low — that judgement belongs
to whoever amends the spec. It fails only when it cannot produce a trustworthy number.
"""

import argparse
import json
import os
import sys

# Infection's JSON log keys, split by how its own Calculator treats them.
# `Calculator::fromMetrics()`: killed = killedByTests + killedByStaticAnalysis,
# errors = error + syntaxError, and MSI = 100 * (killed + errors + timedOut) / testedMutants,
# where testedMutants = total - skipped - ignored.
KILLED_KEYS = ("killed", "killedByStaticAnalysis")
ERROR_KEYS = ("errored", "syntaxErrors")
TIMEOUT_KEYS = ("timeouted",)
ESCAPED_KEYS = ("escaped",)
UNCOVERED_KEYS = ("uncovered",)
# Present in the log and deliberately NOT counted: Infection excludes them from the denominator.
EXCLUDED_KEYS = ("ignored",)

COUNTED_KEYS = KILLED_KEYS + ERROR_KEYS + TIMEOUT_KEYS + ESCAPED_KEYS + UNCOVERED_KEYS

OUTSIDE_ROOT = "(outside the source root)"


class ReportError(Exception):
    """A condition that must fail rather than be reported as a measurement."""


def load(path):
    if not os.path.isfile(path):
        raise ReportError(
            f"no Infection JSON log at {path}. Produced by `logs.json` in the Infection config; "
            "a missing log means nothing was measured, which is a failure and not a pass."
        )

    try:
        with open(path, encoding="utf-8-sig", errors="replace") as handle:
            data = json.load(handle)
    except (OSError, json.JSONDecodeError) as exc:
        raise ReportError(f"{path} could not be read as Infection's JSON log: {exc}") from exc

    if not isinstance(data, dict) or "stats" not in data:
        raise ReportError(
            f"{path} has no `stats` object. Either it is not Infection's JSON log, or the log "
            "format changed — read the file, then fix this parser rather than relaxing it."
        )

    return data


def namespace_of(file_path, root):
    """The namespace directory a mutant's file sits in, relative to the source root."""
    normalised = file_path.replace("\\", "/")
    marker = root.replace("\\", "/").rstrip("/") + "/"
    index = normalised.find(marker)

    if index == -1:
        # Never silently dropped: a mutant outside the root still appears in the table, under a
        # label that says so. A row nobody can attribute is a row worth seeing.
        return OUTSIDE_ROOT

    tail = normalised[index + len(marker):]
    head = tail.split("/", 1)

    # A file directly under the root (no namespace directory) is its own bucket rather than
    # being folded into whichever namespace happens to sort first.
    return head[0] if len(head) > 1 else "(root)"


def tally(data, root):
    """{namespace: {status: count}} over every mutant Infection recorded."""
    groups = {}

    for key in COUNTED_KEYS + EXCLUDED_KEYS:
        for entry in data.get(key, []) or []:
            mutator = entry.get("mutator") or {}
            path = mutator.get("originalFilePath")

            if not isinstance(path, str):
                raise ReportError(
                    f"a mutant in `{key}` has no `mutator.originalFilePath`. Without it the "
                    "mutant cannot be attributed to a namespace, and a table that silently "
                    "omitted it would understate whichever namespace it belongs to."
                )

            groups.setdefault(namespace_of(path, root), {}).setdefault(key, 0)
            groups[namespace_of(path, root)][key] += 1

    return groups


def scores(counts):
    """(tested, detected, msi, covered_msi) for one namespace's status counts."""
    killed = sum(counts.get(k, 0) for k in KILLED_KEYS)
    errors = sum(counts.get(k, 0) for k in ERROR_KEYS)
    timeouts = sum(counts.get(k, 0) for k in TIMEOUT_KEYS)
    escaped = sum(counts.get(k, 0) for k in ESCAPED_KEYS)
    uncovered = sum(counts.get(k, 0) for k in UNCOVERED_KEYS)

    detected = killed + errors + timeouts
    tested = detected + escaped + uncovered
    covered = tested - uncovered

    msi = (100.0 * detected / tested) if tested else 0.0
    covered_msi = (100.0 * detected / covered) if covered else 0.0

    return {
        "killed": killed,
        "errors": errors,
        "timeouts": timeouts,
        "escaped": escaped,
        "uncovered": uncovered,
        "tested": tested,
        "msi": msi,
        "covered_msi": covered_msi,
    }


def main(argv=None):
    ap = argparse.ArgumentParser(
        description="Per-namespace mutation scores from one Infection JSON log (issue #108)."
    )
    ap.add_argument("log", help="Infection's JSON log (`logs.json` in the Infection config)")
    ap.add_argument(
        "--root",
        default="src/main/php/d4np/utils",
        help="source root whose immediate subdirectories are the namespaces (default: %(default)s)",
    )
    ap.add_argument(
        "--tolerance",
        type=float,
        default=0.5,
        help="how far this script's full-tree MSI may differ from Infection's own before the "
        "run is refused as untrustworthy, in percentage points (default: %(default)s)",
    )
    args = ap.parse_args(argv)

    try:
        data = load(args.log)
        groups = tally(data, args.root)

        if not groups:
            raise ReportError(
                f"{args.log} records no mutants at all. An empty run is a failure, not a pass: "
                "it means nothing was mutated."
            )

        overall = {}
        for counts in groups.values():
            for key, value in counts.items():
                overall[key] = overall.get(key, 0) + value

        computed = scores(overall)

        reported = data.get("stats", {}).get("msi")
        if not isinstance(reported, (int, float)):
            raise ReportError(
                "`stats.msi` is missing or not numeric, so this script's arithmetic cannot be "
                "checked against Infection's own. Unchecked, every row below is a guess."
            )

        drift = abs(computed["msi"] - float(reported))
        if drift > args.tolerance:
            raise ReportError(
                f"this script computed a full-tree MSI of {computed['msi']:.2f}% from the mutant "
                f"arrays, while Infection reported {float(reported):.2f}% in `stats.msi` — a "
                f"{drift:.2f} point disagreement, past the {args.tolerance:g} tolerance.\n  The "
                "per-namespace split is the same arithmetic applied to subsets, so if the whole "
                "is wrong every part is. Read the log and fix this parser; do not widen the "
                "tolerance to make it pass."
            )
    except ReportError as exc:
        print(f"mutation scope report: FAIL\n\n  {exc}")
        return 1

    names = sorted(groups)
    width = max(len(n) for n in names + ["ALL (full tree)"])

    print(
        f"{'namespace'.ljust(width)}  {'tested':>7}  {'killed':>7}  {'escaped':>8}  "
        f"{'uncov':>6}  {'MSI':>8}  {'covMSI':>8}"
    )
    for name in names:
        s = scores(groups[name])
        print(
            f"{name.ljust(width)}  {s['tested']:>7}  {s['killed']:>7}  {s['escaped']:>8}  "
            f"{s['uncovered']:>6}  {s['msi']:>7.2f}%  {s['covered_msi']:>7.2f}%"
        )
    print(
        f"{'ALL (full tree)'.ljust(width)}  {computed['tested']:>7}  {computed['killed']:>7}  "
        f"{computed['escaped']:>8}  {computed['uncovered']:>6}  {computed['msi']:>7.2f}%  "
        f"{computed['covered_msi']:>7.2f}%"
    )

    print(
        f"\n  Arithmetic checked: this script's full-tree MSI ({computed['msi']:.2f}%) agrees with "
        f"Infection's own `stats.msi` ({float(reported):.2f}%) to within {args.tolerance:g} point(s)."
    )
    print(
        "\nmutation scope report: OK — advisory. No floor is asserted here: NFR-07 names three\n"
        "  namespaces and ADR-0040 records that the spec owns that number, so widening the scope\n"
        "  is a spec amendment and a maintainer's decision (issue #108). These are the figures\n"
        "  that decision needs, not the decision."
    )

    return 0


if __name__ == "__main__":
    sys.exit(main())
