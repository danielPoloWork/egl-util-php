#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Daniel Polo
"""Prove `dist_gate.py` can fail (issue #119).

Its strongest evidence is already spent and was never synthetic: run against `master` before the
missing rule was added, it named `phpdoc.dist.xml` — a file that had shipped in `v1.1.0`'s published
archive and that nobody had noticed. Once the rule lands that demonstration is gone, so the
repeatable half lives here, the way `verify_link_check.py`, `verify_diff_coverage_gate.py`,
`verify_release_body.py`, `verify_bc_gate.py` and `verify_api_docs_gate.py` do.

Each case builds a throwaway git repository with its own `.gitattributes`, copies the real gate in
so `ROOT` resolves there, and runs it. The cases that matter are the refusals: a stray top-level
file fails, and an archive with **no source at all** fails rather than passing — because "nothing
unexpected is present" is trivially true of an empty archive, and that is the shape every one of
this project's five vacuous-green defects took.

    python tools/tests/verify_dist_gate.py

Exits 0 when every case behaves as specified, 1 otherwise.
"""

import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
GATE = os.path.join(os.path.dirname(HERE), "dist_gate.py")

failures = []


def check(name, condition, detail=""):
    if condition:
        print(f"  ok   {name}")
    else:
        print(f"  FAIL {name}{(' — ' + detail) if detail else ''}")
        failures.append(name)


def run_case(files, gitattributes):
    """Build a throwaway repo, commit it, and run the real gate inside it."""
    root = tempfile.mkdtemp(prefix="distgate-")
    try:
        os.makedirs(os.path.join(root, "tools"))
        shutil.copy(GATE, os.path.join(root, "tools", "dist_gate.py"))
        for rel in files:
            full = os.path.join(root, rel.replace("/", os.sep))
            os.makedirs(os.path.dirname(full), exist_ok=True)
            with open(full, "w", encoding="utf-8") as handle:
                handle.write("x\n")
        with open(os.path.join(root, ".gitattributes"), "w", encoding="utf-8") as handle:
            handle.write(gitattributes)

        quiet = {"capture_output": True, "text": True, "cwd": root}
        subprocess.run(["git", "init", "-q"], **quiet)
        subprocess.run(["git", "config", "user.email", "t@t.test"], **quiet)
        subprocess.run(["git", "config", "user.name", "T"], **quiet)
        subprocess.run(["git", "add", "-A"], **quiet)
        subprocess.run(["git", "commit", "-qm", "c"], **quiet)

        proc = subprocess.run(
            [sys.executable, os.path.join(root, "tools", "dist_gate.py")],
            capture_output=True, text=True, encoding="utf-8", cwd=root,
        )
        return proc.returncode, (proc.stdout or "") + (proc.stderr or "")
    finally:
        shutil.rmtree(root, ignore_errors=True)


IGNORE_TOOLING = "/tools export-ignore\n/.gitattributes export-ignore\n"

print("verify_dist_gate.py")

# 1. The permitted set exactly.
code, out = run_case(
    ["src/main/php/A.php", "LICENSE", "README.md", "composer.json"],
    IGNORE_TOOLING,
)
check("the permitted set alone passes", code == 0, f"exit {code}: {out.strip()[:120]}")
check("...and says the verdict came from the contents, not the rules",
      "CONTENTS" in out, out.strip()[:140])

# 2. ★ A stray top-level file — the phpdoc.dist.xml shape.
code, out = run_case(
    ["src/main/php/A.php", "LICENSE", "README.md", "composer.json", "phpdoc.dist.xml"],
    IGNORE_TOOLING,
)
check("a stray top-level config file FAILS", code == 1, f"exit {code}")
check("...and names it", "phpdoc.dist.xml" in out, out.strip()[:160])

# 3. A whole directory nobody excluded.
code, out = run_case(
    ["src/main/php/A.php", "LICENSE", "README.md", "composer.json", "src/test/php/ATest.php"],
    IGNORE_TOOLING,
)
check("an un-excluded source subtree FAILS", code == 1, f"exit {code}")
check("...and names the file inside it", "src/test/php/ATest.php" in out, out.strip()[:200])

# 4. ★ THE ONE THAT MATTERS MOST: no source at all must not pass.
code, out = run_case(
    ["src/main/php/A.php", "LICENSE", "README.md", "composer.json"],
    IGNORE_TOOLING + "/src export-ignore\n",
)
check("an archive with NO source exits 2 rather than passing", code == 2, f"exit {code}")
check("...and says a dist with no source is not a dist",
      "not a dist" in out, out.strip()[:200])

# 5. Everything excluded — the empty-archive degenerate case.
code, out = run_case(
    ["src/main/php/A.php", "LICENSE"],
    IGNORE_TOOLING + "/src export-ignore\n/LICENSE export-ignore\n",
)
check("an empty archive exits 2 rather than passing", code == 2, f"exit {code}")

print()
if failures:
    print(f"{len(failures)} case(s) failed: {', '.join(failures)}")
    sys.exit(1)
print("all cases behaved as specified")
