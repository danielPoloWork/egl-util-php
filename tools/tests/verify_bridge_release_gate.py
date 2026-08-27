#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Daniel Polo
"""Prove `bridge_release_gate.py` publishes the package its tag names — and refuses everything else.

Issue #93 added a second bridge, and with it the tag grammar stopped being a literal:
`utils-psr7-bridge-vX.Y.Z` and `utils-psr18-bridge-vX.Y.Z` differ only in the captured part, and
the gate now derives the package directory from the tag rather than from a constant. That is the
one piece of this pipeline where getting it wrong publishes **the wrong package** — a mistake no
`composer` command can take back, since a published version cannot be corrected in place.

So the cases that matter most are 1 and 2: the right package is selected for each tag, proven
against a synthetic tree where the two packages hold *different* versions, so selecting the wrong
one cannot accidentally pass.

The happy path cannot be exercised against the real repository — neither package has cut a version,
so both correctly fail on a missing changelog heading. A fixture tree is what makes "it would
publish correctly" assertable at all.

    python tools/tests/verify_bridge_release_gate.py

Exits 0 when every case behaves as specified, 1 otherwise.
"""

import json
import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
GATE = os.path.join(os.path.dirname(HERE), "bridge_release_gate.py")

failures = []


def check(name, condition, detail=""):
    if condition:
        print(f"  ok   {name}")
    else:
        print(f"  FAIL {name}{(' — ' + detail) if detail else ''}")
        failures.append(name)


def tree(packages):
    """A synthetic repository root. `packages` maps a directory name to (version, manifest)."""
    root = tempfile.mkdtemp(prefix="bridge-gate-")

    for directory, (version, manifest) in packages.items():
        path = os.path.join(root, "packages", directory)
        os.makedirs(path)
        with open(os.path.join(path, "CHANGELOG.md"), "w", encoding="utf-8") as handle:
            handle.write(f"# Changelog\n\n## [{version}]\n\n- released\n")
        with open(os.path.join(path, "composer.json"), "w", encoding="utf-8") as handle:
            json.dump(manifest, handle)

    return root


def run(root, tag, *extra):
    proc = subprocess.run(
        [sys.executable, GATE, "--tag", tag, "--root", root, *extra],
        capture_output=True, text=True, encoding="utf-8",
        env=dict(os.environ, PYTHONIOENCODING="utf-8"),
    )
    return proc.returncode, (proc.stdout or "") + (proc.stderr or "")


GOOD = {"php": ">=8.1", "egl/utils": "^1.0"}

print("verify_bridge_release_gate.py")

# The two packages hold DIFFERENT versions on purpose: a gate that picked the wrong directory
# would fail on the changelog heading rather than passing, so this cannot pass by accident.
root = tree({
    "utils-psr7-bridge": ("1.2.3", {"name": "egl/utils-psr7-bridge", "require": dict(GOOD)}),
    "utils-psr18-bridge": ("0.4.0", {"name": "egl/utils-psr18-bridge", "require": dict(GOOD)}),
})

try:
    # 1. ★ Each tag selects its own package.
    code, out = run(root, "utils-psr7-bridge-v1.2.3")
    check("a psr7 tag validates against the psr7 package", code == 0, f"exit {code}\n{out[:300]}")

    code, out = run(root, "utils-psr18-bridge-v0.4.0")
    check("a psr18 tag validates against the psr18 package", code == 0, f"exit {code}\n{out[:300]}")

    # 2. ★ And cannot be satisfied by the other one's version.
    code, out = run(root, "utils-psr18-bridge-v1.2.3")
    check("a psr18 tag is NOT satisfied by the psr7 package's version", code == 1, f"exit {code}")
    check("...and says which package it looked in",
          "utils-psr18-bridge" in out and "1.2.3" in out, out[:300])

    # 2b. ★ A heading must be a HEADING, not a mention of one in prose.
    #
    # Regression guard for a real miss: issue #120's closing pass folded two version headings back
    # to `[Unreleased]` and wrote a sentence saying which heading had been removed — quoting it.
    # Under the original substring test that sentence satisfied the gate, which reported OK for a
    # tag whose changelog entry did not exist any more. Prose about a mechanism is not the
    # mechanism.
    prose = tree({"utils-psr7-bridge": ("9.9.9", {"name": "egl/utils-psr7-bridge", "require": dict(GOOD)})})
    with open(os.path.join(prose, "packages", "utils-psr7-bridge", "CHANGELOG.md"), "w", encoding="utf-8") as handle:
        handle.write(
            "# Changelog\n\n## [Unreleased]\n\n"
            "A `## [0.1.0]` heading was briefly added here and has been folded back.\n"
            "Indented, it is still not a heading:\n\n    ## [0.1.0]\n"
        )

    code, out = run(prose, "utils-psr7-bridge-v0.1.0")
    check("a version named only in prose does not count as its heading", code == 1, f"exit {code}\n{out[:300]}")
    check("...and the failure names the heading it wanted",
          "0.1.0" in out and "CHANGELOG.md" in out, out[:300])

    # 3. The machine-readable outputs the workflow consumes.
    code, out = run(root, "utils-psr18-bridge-v0.4.0", "--print-package")
    check("--print-package prints the directory to split", code == 0 and out.strip() == "utils-psr18-bridge",
          f"exit {code} out={out.strip()!r}")

    code, out = run(root, "utils-psr18-bridge-v0.4.0", "--print-version")
    check("--print-version prints the tag the split repo receives", code == 0 and out.strip() == "0.4.0",
          f"exit {code} out={out.strip()!r}")

    # 4. A core release tag must never match — the separation this gate refuses by name.
    for tag in ("v1.2.3", "utils-psr18-bridge-v1.2", "utils-psr18-v1.2.3", "utils-PSR18-bridge-v1.2.3"):
        code, out = run(root, tag)
        check(f'"{tag}" is refused by the grammar', code == 1, f"exit {code}")

    # 5. A tag naming a package that is not here must not publish an empty split.
    code, out = run(root, "utils-nope-bridge-v1.0.0")
    check("a tag naming an absent package is refused", code == 1, f"exit {code}")
    check("...by name, rather than as a confusing missing-file error",
          "does not exist in this repository" in out, out[:300])
finally:
    shutil.rmtree(root, ignore_errors=True)

# 6. The two manifest invariants, each on the package its tag names.
bad_repo = tree({"utils-psr18-bridge": ("0.4.0", {
    "name": "egl/utils-psr18-bridge", "require": dict(GOOD),
    "repositories": {"monorepo": {"type": "path", "url": "../../"}},
})})
try:
    code, out = run(bad_repo, "utils-psr18-bridge-v0.4.0")
    check("a committed `repositories` entry is refused", code == 1, f"exit {code}")
    check("...naming the standalone-install consequence", "standalone" in out, out[:400])
finally:
    shutil.rmtree(bad_repo, ignore_errors=True)

bad_dev = tree({"utils-psr18-bridge": ("0.4.0", {
    "name": "egl/utils-psr18-bridge", "require": {"php": ">=8.1", "egl/utils": "@dev"},
})})
try:
    code, out = run(bad_dev, "utils-psr18-bridge-v0.4.0")
    check("a @dev core constraint is refused", code == 1, f"exit {code}")
finally:
    shutil.rmtree(bad_dev, ignore_errors=True)

print()
if failures:
    print(f"{len(failures)} case(s) failed: {', '.join(failures)}")
    sys.exit(1)
print("all cases behaved as specified")
