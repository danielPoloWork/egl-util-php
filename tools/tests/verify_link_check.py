#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Daniel Polo
"""Prove `consistency_lint.py`'s `links` check can fail (issue #116, ROADMAP 13.4, ADR-0069).

The standing method — items 1.11 and 2.7 — is that a gate is not trusted until it has been watched
failing. The `links` check has already been watched failing on real data: it found **nineteen**
broken links on `master` the first time it ran, against the five the 2026-08-09 review board had
counted. That is the strongest possible evidence it works, and it is also single-use: once those
nineteen are repaired the demonstration is gone.

So the repeatable half lives here, the way `verify_diff_coverage_gate.py` does. Each case builds a
throwaway git repository, copies the real linter into it (so `ROOT` resolves there), and runs it with
`--only links` — a flag added with this check precisely so a proof can assert what *that* check
reports without the other eight weighing in.

Four cases are the ones that matter: a target that does not exist fails, an `#anchor` with no
matching heading fails, a `§ "Section"` reference naming a section that is absent fails — that last
being the exact shape of item 7.5's originating defect, `SECURITY.md` deferring to a section of
`maintenance.md` that had never been written — and a link inside a fenced code block is **not**
followed, because those are examples rather than references.

    python tools/tests/verify_link_check.py

Exits 0 when every case behaves as specified, 1 otherwise.
"""

import os
import shutil
import subprocess
import sys
import tempfile
import textwrap

LINTER = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "consistency_lint.py"))
PY = sys.executable
FAILED = []


def git(args, cwd):
    result = subprocess.run(["git", *args], cwd=cwd, capture_output=True, text=True)
    assert result.returncode == 0, f"git {args} failed: {result.stderr}"


def scenario(name, files, expect, expect_in=None, expect_not_in=None):
    work = tempfile.mkdtemp(prefix="lnk-")
    try:
        os.makedirs(os.path.join(work, "tools"))
        shutil.copyfile(LINTER, os.path.join(work, "tools", "consistency_lint.py"))

        for path, body in files.items():
            full = os.path.join(work, path)
            os.makedirs(os.path.dirname(full), exist_ok=True)
            with open(full, "w", encoding="utf-8", newline="\n") as handle:
                handle.write(textwrap.dedent(body).lstrip())

        git(["init", "-q", "-b", "master"], work)
        git(["config", "user.email", "t@example.com"], work)
        git(["config", "user.name", "T"], work)
        git(["add", "-A"], work)

        result = subprocess.run(
            [PY, os.path.join("tools", "consistency_lint.py"), "--only", "links"],
            cwd=work, capture_output=True, text=True,
        )

        ok = result.returncode == expect
        detail = ""
        if expect_in is not None and expect_in not in result.stdout:
            ok, detail = False, f" (missing: {expect_in!r})"
        if expect_not_in is not None and expect_not_in in result.stdout:
            ok, detail = False, f" (unexpected: {expect_not_in!r})"

        print(f'  [{"ok " if ok else "FAIL"}] exit {result.returncode} (want {expect}){detail}  {name}')
        if not ok:
            FAILED.append(name)
            print(textwrap.indent((result.stdout + result.stderr).strip(), "        "))
    finally:
        shutil.rmtree(work, ignore_errors=True)


GOOD = {
    "README.md": """
        # Readme
        See [the guide](docs/guide.md) and [its rule](docs/guide.md#the-rule).
    """,
    "docs/guide.md": """
        # Guide
        ## The rule
        Text.
    """,
}

print("consistency_lint --only links -- synthetic verification\n")

scenario("a resolvable link and anchor -> pass", GOOD, expect=0,
         expect_in="relative reference(s) resolved")

scenario("a target that does not exist -> FAIL, naming file and line",
         {"README.md": "# R\nSee [gone](docs/gone.md).\n"},
         expect=1, expect_in="README.md:2: link target does not exist")

scenario("an anchor with no matching heading -> FAIL",
         {"README.md": "# R\nSee [rule](docs/guide.md#no-such-rule).\n",
          "docs/guide.md": "# Guide\n## The rule\n"},
         expect=1, expect_in="no heading matches anchor #no-such-rule")

scenario("a same-file anchor is resolved too",
         {"README.md": "# R\n## Some Part\nSee [above](#missing-part).\n"},
         expect=1, expect_in="no heading matches anchor #missing-part")

# Item 7.5's defect, reproduced: a pointer to a section that was never written.
scenario('a quoted section reference naming an absent section -> FAIL',
         {"SECURITY.md": '# Security\nDefined in [maintenance.md](docs/maintenance.md) § "Supported versions".\n',
          "docs/maintenance.md": "# Maintenance\n## Release cadence\n"},
         expect=1, expect_in='has no section named "Supported versions"')

scenario("a quoted section reference that resolves -> pass",
         {"SECURITY.md": '# Security\nDefined in [maintenance.md](docs/maintenance.md) § "Supported versions".\n',
          "docs/maintenance.md": "# Maintenance\n## Supported versions\n"},
         expect=0)

scenario("an italicised section reference resolves the same way",
         {"A.md": "# A\nSee [b](b.md) § *Scope*.\n", "b.md": "# B\n## Scope\n"},
         expect=0)

scenario("a heading with inline markup still matches its slug",
         {"README.md": "# R\nSee [x](docs/g.md#the-hard-part).\n",
          "docs/g.md": "# G\n## The *hard* part\n"},
         expect=0)

scenario("a link inside a fenced code block is an example, not a reference",
         {"README.md": "# R\n\n```md\n[nowhere](docs/nope.md)\n```\n"},
         expect=0, expect_not_in="does not exist")

scenario("an external URL is not fetched",
         {"README.md": "# R\n[site](https://example.invalid/nope) and [m](mailto:a@b.c).\n"},
         expect=0)

scenario("an image is not treated as a link",
         {"README.md": "# R\n![badge](https://img.example/x.svg)\n"},
         expect=0)

scenario("a numeric section reference is deliberately NOT checked",
         {"A.md": "# A\nSee [b](b.md) §7 for the rule.\n", "b.md": "# B\n## Only heading\n"},
         expect=0, expect_not_in="has no section named")

scenario("a directory target resolves (docs/ style links)",
         {"README.md": "# R\nSee [the adrs](docs/adr/).\n", "docs/adr/0001-x.md": "# One\n"},
         expect=0)

scenario("several broken links are all reported, not just the first",
         {"README.md": "# R\n[a](x.md)\n[b](y.md)\n[c](z.md)\n"},
         expect=1, expect_in="README.md:4")

print()
if FAILED:
    print(f"{len(FAILED)} case(s) did not behave as specified:")
    for name in FAILED:
        print("  -", name)
    sys.exit(1)
print("all cases behaved as specified")
