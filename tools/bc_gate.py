#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Daniel Polo
"""Decide whether detected BC breaks are permitted by the version bump — spec NFR-07, ADR-0031.

`roave/backward-compatibility-check` answers one question: *are there backward-incompatible
changes since the previous release?* It cannot answer the question that actually gates a release,
which is *are those breaks allowed in **this** bump?* Those differ, and pre-1.0 they differ a lot:
SemVer 2.0.0 §4 says that under `0.y.z` "anything MAY change at any time", so a break in a 0.7 ->
0.8 bump is not a violation — while the same break in 0.7.0 -> 0.7.1 is, because a PATCH promises
nothing changed.

    php .../roave-backward-compatibility-check --from=v0.7.0 ; echo $? > bc.code
    python tools/bc_gate.py --previous 0.7.0 --current 0.8.0 --bc-exit "$(cat bc.code)"

The rule, which is this project's own policy and not SemVer alone:

| previous | current | breaks found | verdict |
|---|---|---|---|
| any       | PATCH bump   | yes | **FAIL** — a PATCH may never break |
| `0.y.z`   | MINOR bump   | yes | pass — SemVer §4, and pre-1.0 MINOR is this project's declared vehicle |
| `>=1.0.0` | MINOR bump   | yes | **FAIL** — post-1.0 a MINOR must be additive |
| `>=1.0.0` | MAJOR bump   | yes | pass — a MAJOR is what a break is for |
| `0.y.z`   | MAJOR bump   | yes | pass — 0.x -> 1.0 is the API freeze |
| any       | any          | no  | pass |

**A break is never silently allowed.** Every permitted case still prints what was permitted and
why, because "the gate passed" and "there were no breaks" are different facts and a release note
that conflates them is how a consumer gets surprised.

**Absence is failure**, as in the sibling gates: a version that does not parse, a non-increasing
bump, or a missing argument exits non-zero rather than being treated as "probably fine".
"""

import argparse
import re
import sys

SEMVER = re.compile(r"^(\d+)\.(\d+)\.(\d+)")


class GateError(Exception):
    """A condition that must fail the gate rather than be reported as a verdict."""


def parse(version, label):
    """`1.2.3` (with an optional leading `v` and trailing pre-release) into (1, 2, 3)."""
    cleaned = version.strip().lstrip("vV")
    match = SEMVER.match(cleaned)
    if match is None:
        raise GateError(
            f'the {label} version "{version}" is not SemVer. A release that cannot be compared '
            "cannot be gated, which is a failure and not a pass."
        )
    return tuple(int(part) for part in match.groups())


def bump_level(previous, current):
    """'major', 'minor' or 'patch' — which component increased."""
    if current[0] != previous[0]:
        return "major"
    if current[1] != previous[1]:
        return "minor"
    if current[2] != previous[2]:
        return "patch"
    raise GateError(
        f"the version did not change ({'.'.join(map(str, previous))}). A release PR must bump it; "
        "the consistency lint's version lockstep covers the same ground from the other side."
    )


def main(argv=None):
    ap = argparse.ArgumentParser(
        description="Gate detected BC breaks against the version bump they arrive in."
    )
    ap.add_argument("--previous", required=True, help="version of the previous release (the tag)")
    ap.add_argument("--current", required=True, help="version being released (Version.php)")
    ap.add_argument(
        "--bc-exit",
        required=True,
        help="exit code from roave/backward-compatibility-check: 0 means no breaks",
    )
    args = ap.parse_args(argv)

    try:
        previous = parse(args.previous, "previous")
        current = parse(args.current, "current")

        if current < previous:
            raise GateError(
                f"the current version {'.'.join(map(str, current))} is lower than the previous "
                f"{'.'.join(map(str, previous))}. Releases move forward."
            )

        level = bump_level(previous, current)

        try:
            bc_exit = int(args.bc_exit)
        except ValueError as exc:
            raise GateError(f'--bc-exit "{args.bc_exit}" is not an integer: {exc}') from exc
    except GateError as exc:
        print(f"bc gate: FAIL\n\n  {exc}")
        return 1

    breaks = bc_exit != 0
    pre_one_zero = previous[0] == 0

    print(
        f"bc gate: {'.'.join(map(str, previous))} -> {'.'.join(map(str, current))} "
        f"({level.upper()} bump), breaks detected: {'yes' if breaks else 'no'}"
    )

    if not breaks:
        print("\nbc gate: OK — no backward-incompatible change since the previous release.")
        return 0

    if level == "patch":
        print(
            "\nbc gate: FAIL — backward-incompatible changes in a PATCH bump.\n\n"
            "  A PATCH promises that nothing changed for a consumer. Either drop the breaking\n"
            "  change from this release, or make it a MINOR (pre-1.0) / MAJOR (post-1.0) bump."
        )
        return 1

    if level == "minor" and not pre_one_zero:
        print(
            "\nbc gate: FAIL — backward-incompatible changes in a MINOR bump after 1.0.\n\n"
            "  Post-1.0 a MINOR must be additive; a break needs a MAJOR. If the symbol was\n"
            "  deprecated, check it has been published as deprecated for at least one full MINOR\n"
            "  before removal (docs/workflow/maintenance.md)."
        )
        return 1

    if level == "minor":
        print(
            "\nbc gate: OK — breaks are PERMITTED in a pre-1.0 MINOR bump (SemVer 2.0.0 §4:\n"
            "  under 0.y.z anything may change). They are permitted, not invisible: list them\n"
            "  under a `Breaking` heading in the changelog and the release notes, because a\n"
            "  consumer reading only the version number has no other warning."
        )
        return 0

    print(
        "\nbc gate: OK — breaks are PERMITTED in a MAJOR bump, which is what a MAJOR is for.\n"
        "  Record them under a `Breaking` heading with a migration note (ADR required per\n"
        "  docs/workflow/maintenance.md)."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
