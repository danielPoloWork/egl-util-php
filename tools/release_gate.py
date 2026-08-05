#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Daniel Polo
"""Validate that a pushed tag matches the tree it points at — spec §8, ADR-0032.

`consistency_lint.py`'s `version-lockstep` already keeps three things in agreement *inside* the
tree: the `VERSION` constant, the README's `Status-vX.Y.Z` badge, and the latest released
changelog/release file. It cannot check the one thing that only exists at release time — **the
tag** — because a lint that runs on a working copy has no tag to compare against.

That gap is the whole failure mode this gate closes. `git tag -a v0.2.0` on a tree whose constant
still says `0.1.0` produces a release that installs as 0.2.0 and reports itself as 0.1.0, and
nothing in the repository disagrees with itself, so no existing check notices. Packagist would
serve it, and `composer show` would contradict the package's own constant.

    python tools/release_gate.py --tag v0.1.0

Checks, all of which must hold:

1. the tag is `vMAJOR.MINOR.PATCH`;
2. it equals the `VERSION` constant in the version file;
3. `docs/releases/v<X.Y.Z>.md` exists — the notes a human publishes;
4. `docs/changelog/v<MAJOR>/v<X.Y.Z>.md` exists — the per-version changelog split;
5. `CHANGELOG.md` carries an index row pointing at that changelog file.

**Absence is failure, never a pass**, as in the sibling gates: a missing file, an unreadable
version file, or a tag that does not parse all exit non-zero. A release is the one artifact that
cannot be corrected in place once published, so "probably fine" is not an available verdict.

`--root` exists so the gate can be exercised against fixture trees; it defaults to the repository
this file lives in.
"""

import argparse
import os
import re
import sys

TAG = re.compile(r"^v(\d+)\.(\d+)\.(\d+)$")
VERSION_IN_FILE = re.compile(r"(\d+\.\d+\.\d+)")

VERSION_FILE = os.path.join("src", "main", "php", "d4np", "utils", "Version.php")


class GateError(Exception):
    """A condition that must fail the gate rather than be reported as a finding."""


def read(root, *parts):
    path = os.path.join(root, *parts)
    if not os.path.isfile(path):
        raise GateError(f"{os.path.join(*parts)} does not exist")
    with open(path, encoding="utf-8") as handle:
        return handle.read()


def check(root, tag):
    """Every problem found, as a list of human-readable strings."""
    match = TAG.match(tag)
    if match is None:
        raise GateError(
            f'the tag "{tag}" is not vMAJOR.MINOR.PATCH. The release workflow triggers on '
            '"v*.*.*", so a tag reaching this gate that does not parse means the pattern and this '
            "check disagree, which is worth stopping for."
        )

    major, minor, patch = match.groups()
    version = f"{major}.{minor}.{patch}"
    problems = []

    # 1. The constant.
    try:
        found = VERSION_IN_FILE.search(read(root, *VERSION_FILE.split(os.sep)))
    except GateError as exc:
        raise GateError(f"cannot read the version constant: {exc}") from exc

    if found is None:
        raise GateError(f"no X.Y.Z version found in {VERSION_FILE}")

    if found.group(1) != version:
        problems.append(
            f"the tag says {version} but {VERSION_FILE} says {found.group(1)}. A release that "
            "installs as one version and reports itself as another is the defect this gate exists "
            "for, and nothing inside the tree disagrees with itself, so no lint would catch it."
        )

    # 2. The release notes a human publishes.
    notes = os.path.join("docs", "releases", f"v{version}.md")
    if not os.path.isfile(os.path.join(root, notes)):
        problems.append(f"{notes} does not exist (step 4 of docs/workflow/release.md)")

    # 3. The per-version changelog split.
    split = os.path.join("docs", "changelog", f"v{major}", f"v{version}.md")
    if not os.path.isfile(os.path.join(root, split)):
        problems.append(f"{split} does not exist (step 2 of docs/workflow/release.md)")

    # 4. And an index row pointing at it, so the split is reachable rather than merely present.
    try:
        changelog = read(root, "CHANGELOG.md")
    except GateError as exc:
        problems.append(str(exc))
    else:
        expected = f"docs/changelog/v{major}/v{version}.md"
        if expected not in changelog:
            problems.append(
                f"CHANGELOG.md has no index row linking {expected}. An unlinked per-version file "
                "is one nobody reaches from the changelog they actually open."
            )

    return version, problems


def main(argv=None):
    ap = argparse.ArgumentParser(
        description="Validate that a release tag agrees with the tree it points at."
    )
    ap.add_argument("--tag", required=True, help="the pushed tag, e.g. v0.1.0")
    ap.add_argument(
        "--root",
        default=os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
        help="repository root to inspect (defaults to this file's repository)",
    )
    args = ap.parse_args(argv)

    try:
        version, problems = check(args.root, args.tag)
    except GateError as exc:
        print(f"release gate: FAIL\n\n  {exc}")
        return 1

    if problems:
        print(f"release gate: FAIL — {len(problems)} problem(s) with the {args.tag} release:")
        for problem in problems:
            print(f"\n  - {problem}")
        print(
            "\nA published release cannot be corrected in place. Fix the tree, delete the "
            "unpublished tag, and re-tag."
        )
        return 1

    print(
        f"release gate: OK — {args.tag} agrees with the version constant, and both the release "
        f"notes and the v{version} changelog split exist and are indexed."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
