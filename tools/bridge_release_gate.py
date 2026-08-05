#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Daniel Polo
"""Validate a bridge release tag against the package it will publish — spec 02 §6, ADR-0035.

The bridge versions independently of the core (ADR-0033 §3): tags in this monorepo are
`utils-psr7-bridge-vMAJOR.MINOR.PATCH`, translated to a plain `vMAJOR.MINOR.PATCH` on the generated
split repository. That independence is the reason this gate exists and cannot be
`release_gate.py`: the core's gate anchors a tag to the `VERSION` constant, and the bridge has no
such constant — a Composer library does not carry its own version, by design.

So what anchors a bridge tag to the tree it points at is its **changelog**. `## [X.Y.Z]` in the
package's own `CHANGELOG.md` is the one place the version is written down, which makes it the one
thing a tag can be checked against.

    python tools/bridge_release_gate.py --tag utils-psr7-bridge-v0.1.0

Checks, all of which must hold:

1. the tag is exactly `utils-psr7-bridge-vMAJOR.MINOR.PATCH` — the **shape guard**, which is also
   what keeps the two tag grammars from crossing (see below);
2. the package's `CHANGELOG.md` carries a `## [X.Y.Z]` heading for that version;
3. the committed manifest has **no `repositories` entry** — a path repository pointing at `../../`
   resolves inside the monorepo and nowhere else, so publishing one breaks every standalone install;
4. the core constraint is a released one, never `@dev`.

Checks 3 and 4 duplicate `BridgePackageBoundaryTest`, deliberately. That test runs on pull requests;
this runs on the **exact tagged tree**, which is the artifact that gets published and the one that
cannot be corrected in place afterwards.

**On tag-grammar isolation.** Spec 02 r1 said item 8.3 would verify with a real tag that the core's
`v*.*.*` workflow does not match `utils-psr7-bridge-v*`. Pushing a throwaway tag to a public
repository to test a glob is a poor trade, and a verified glob is still only verified until GitHub
changes its matcher. Both workflows instead **guard their ref shape explicitly** — this tool for the
bridge, and `release_gate.py`'s `^v\\d+\\.\\d+\\.\\d+$` for the core — so a tag reaching the wrong
workflow is refused by name rather than silently processed. The behaviour no longer depends on the
glob at all.

**Absence is failure**, as in every sibling gate: a missing changelog, an unparseable manifest or a
tag that does not match exits non-zero.
"""

import argparse
import json
import os
import re
import sys

TAG = re.compile(r"^utils-psr7-bridge-v(\d+)\.(\d+)\.(\d+)$")
PACKAGE = os.path.join("packages", "utils-psr7-bridge")


class GateError(Exception):
    """A condition that must fail the gate rather than be reported as a finding."""


def read(root, *parts):
    path = os.path.join(root, *parts)
    if not os.path.isfile(path):
        raise GateError(f"{'/'.join(parts)} does not exist")
    with open(path, encoding="utf-8") as handle:
        return handle.read()


def check(root, tag):
    """The version being released, and every problem found."""
    match = TAG.match(tag)
    if match is None:
        raise GateError(
            f'"{tag}" is not a bridge release tag. The grammar is '
            "utils-psr7-bridge-vMAJOR.MINOR.PATCH (ADR-0033 §3); a core release is vMAJOR.MINOR.PATCH "
            "and belongs to release.yml. Refusing by name rather than trusting a workflow glob to "
            "have kept the two apart."
        )

    version = ".".join(match.groups())
    problems = []

    # The changelog is what anchors the tag: a Composer library carries no version of its own.
    changelog = read(root, PACKAGE, "CHANGELOG.md")
    if f"## [{version}]" not in changelog:
        problems.append(
            f"{PACKAGE}/CHANGELOG.md has no `## [{version}]` heading. That heading is the only "
            "place this package's version is written down, so without it the tag is anchored to "
            "nothing and a reader cannot tell what the release contains."
        )

    try:
        manifest = json.loads(read(root, PACKAGE, "composer.json"))
    except json.JSONDecodeError as exc:
        raise GateError(f"{PACKAGE}/composer.json is not valid JSON: {exc}") from exc

    if not isinstance(manifest, dict):
        raise GateError(f"{PACKAGE}/composer.json is not a JSON object")

    if "repositories" in manifest:
        problems.append(
            "the committed manifest carries a `repositories` entry. A path repository resolves "
            "inside this monorepo and nowhere else, so publishing it would break every standalone "
            "install while nothing here noticed. CI injects it in the workspace only (spec 02 §7)."
        )

    require = manifest.get("require")
    if not isinstance(require, dict):
        problems.append("the committed manifest has no `require` section")
    else:
        constraint = require.get("egl/utils")
        if not isinstance(constraint, str):
            problems.append("the committed manifest does not require egl/utils")
        elif "@dev" in constraint or "dev-" in constraint:
            problems.append(
                f'the core constraint is "{constraint}". Publishing a package pinned to a moving '
                "target gives consumers a version that means nothing (spec 02 §2)."
            )

    return version, problems


def main(argv=None):
    ap = argparse.ArgumentParser(
        description="Validate a bridge release tag against the package it publishes."
    )
    ap.add_argument("--tag", required=True, help="the pushed tag, e.g. utils-psr7-bridge-v0.1.0")
    ap.add_argument(
        "--root",
        default=os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
        help="repository root to inspect (defaults to this file's repository)",
    )
    ap.add_argument(
        "--print-version",
        action="store_true",
        help="on success, print only the bare X.Y.Z — the tag the split repository receives",
    )
    args = ap.parse_args(argv)

    try:
        version, problems = check(args.root, args.tag)
    except GateError as exc:
        print(f"bridge-release gate: FAIL\n\n  {exc}", file=sys.stderr)
        return 1

    if problems:
        print(f"bridge-release gate: FAIL — {len(problems)} problem(s) with {args.tag}:", file=sys.stderr)
        for problem in problems:
            print(f"\n  - {problem}", file=sys.stderr)
        print(
            "\nA published package cannot be corrected in place. Fix the tree, delete the "
            "unpublished tag, and re-tag.",
            file=sys.stderr,
        )
        return 1

    if args.print_version:
        print(version)
    else:
        print(
            f"bridge-release gate: OK — {args.tag} publishes as v{version}; the changelog names it, "
            "and the manifest is free of a path repository and of a @dev core constraint."
        )

    return 0


if __name__ == "__main__":
    sys.exit(main())
