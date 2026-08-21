#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Daniel Polo
"""Prove `release_body.py` rebases what it should and refuses what it must (issue #106, ROADMAP 13.5).

The strongest evidence this tool is right is single-use and already spent: run against
`docs/releases/v1.0.0.md`, it reproduced **all four** of the GitHub URLs in the body a human had
published by hand — the conversion nothing in the repository performed. Once those notes are edited
again that comparison drifts, so the repeatable half lives here, the way
`verify_link_check.py` and `verify_diff_coverage_gate.py` do.

Each case builds a throwaway tree, copies the real tool into it so `ROOT` resolves there, and runs
it. The cases that matter: a relative file link becomes a `blob/<tag>` URL; a relative *directory*
link becomes `tree/<tag>` and keeps its trailing slash; an absolute URL and a bare `#anchor` are
left alone; the H1 is dropped (the Release page supplies its own title); a **missing notes file**
exits 2; and a relative link whose target does not exist exits 2 rather than publishing a 404 to
every consumer.

    python tools/tests/verify_release_body.py

Exits 0 when every case behaves as specified, 1 otherwise.
"""

import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
TOOL = os.path.join(os.path.dirname(HERE), "release_body.py")

failures = []


def check(name, condition, detail=""):
    if condition:
        print(f"  ok   {name}")
    else:
        print(f"  FAIL {name}{(' — ' + detail) if detail else ''}")
        failures.append(name)


def run_case(name, notes_body, extra_files=(), extra_dirs=(), tag="v9.9.9", notes_present=True):
    """Build a throwaway tree and run the tool in it. Returns (returncode, stdout, stderr)."""
    root = tempfile.mkdtemp(prefix="relbody-")
    try:
        os.makedirs(os.path.join(root, "tools"))
        shutil.copy(TOOL, os.path.join(root, "tools", "release_body.py"))
        os.makedirs(os.path.join(root, "docs", "releases"))
        for rel in extra_files:
            full = os.path.join(root, rel.replace("/", os.sep))
            os.makedirs(os.path.dirname(full), exist_ok=True)
            with open(full, "w", encoding="utf-8") as handle:
                handle.write("placeholder\n")
        for rel in extra_dirs:
            os.makedirs(os.path.join(root, rel.replace("/", os.sep)), exist_ok=True)
        if notes_present:
            with open(os.path.join(root, "docs", "releases", f"{tag}.md"),
                      "w", encoding="utf-8") as handle:
                handle.write(notes_body)
        proc = subprocess.run(
            [sys.executable, os.path.join(root, "tools", "release_body.py"),
             "--tag", tag, "--repo", "acme/widget"],
            capture_output=True, text=True, encoding="utf-8",
        )
        return proc.returncode, proc.stdout or "", proc.stderr or ""
    finally:
        shutil.rmtree(root, ignore_errors=True)


print("verify_release_body.py")

# 1. A relative file link becomes blob/<tag>.
code, out, _ = run_case(
    "file link",
    "# Title\n\nSee [the ADR](../adr/0001-a.md).\n",
    extra_files=["docs/adr/0001-a.md"],
)
check("a relative file link becomes a blob/<tag> URL",
      code == 0 and "https://github.com/acme/widget/blob/v9.9.9/docs/adr/0001-a.md" in out,
      out.strip())

# 2. A relative directory link becomes tree/<tag> and keeps its trailing slash.
code, out, _ = run_case(
    "dir link",
    "# Title\n\nSee [the benchmarks](../benchmarks/).\n",
    extra_dirs=["docs/benchmarks"],
)
check("a relative directory link becomes tree/<tag> and keeps its trailing slash",
      code == 0 and "https://github.com/acme/widget/tree/v9.9.9/docs/benchmarks/" in out,
      out.strip())

# 3. Absolute URLs and bare anchors are left alone.
code, out, _ = run_case(
    "untouched",
    "# Title\n\n[ext](https://example.test/x) and [self](#a-heading).\n",
)
check("an absolute URL is left alone",
      code == 0 and "https://example.test/x" in out, out.strip())
check("a bare #anchor is left alone",
      code == 0 and "(#a-heading)" in out, out.strip())

# 4. The H1 is dropped — the Release page already shows its own title.
code, out, _ = run_case("h1", "# v9.9.9 — the title\n\nBody text.\n")
check("the H1 is dropped", code == 0 and not out.lstrip().startswith("#"), out.strip()[:60])
check("the body survives the H1 being dropped", "Body text." in out, out.strip()[:60])

# 5. An image is not rewritten — it is not a reference a reader follows.
code, out, _ = run_case(
    "image",
    "# Title\n\n![badge](../img/badge.svg)\n",
    extra_files=["docs/img/badge.svg"],
)
check("an image is left alone", code == 0 and "![badge](../img/badge.svg)" in out, out.strip())

# 6. THE TWO REFUSALS. Missing notes file.
code, out, err = run_case("missing", "", notes_present=False)
check("a missing notes file exits 2", code == 2, f"exit {code}")
check("...and says which file", "v9.9.9.md" in err, err.strip())

# 7. A relative link whose target does not exist.
code, out, err = run_case("dangling", "# Title\n\n[gone](../adr/0404-nope.md)\n")
check("a dangling relative link exits 2 rather than publishing a 404", code == 2, f"exit {code}")
check("...and names the offending target", "0404-nope.md" in err, err.strip())

print()
if failures:
    print(f"{len(failures)} case(s) failed: {', '.join(failures)}")
    sys.exit(1)
print("all cases behaved as specified")
