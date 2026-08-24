#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Daniel Polo
"""Assert the Packagist dist contains only what a consumer needs (issue #119).

`.gitattributes`' `export-ignore` rules cut the archive from 524 files to 121 at `v1.1.0`. Then
`phpdoc.dist.xml` shipped in it anyway — added in #154, *after* #152 wrote those rules, with no
rule of its own. Nobody noticed until the tag was already published.

**The reasoning that failed is worth naming, because it was written down as if it were sound.**
`.gitattributes` argued for a deny-list on the grounds that "allowlisting `src/main` only breaks
the moment a new top-level file is added and nobody remembers this rule". That has the failure
direction backwards: a deny-list **includes** a new top-level file by default, so it rots the same
way and *silently*, while an allowlist would have failed loudly the first time something legitimate
was missing. Neither list is self-maintaining. A check is.

So this asserts the *contents*, not the rules:

    python tools/dist_gate.py [--ref HEAD]

Exit 0 when the archive holds only the permitted set; 1 when anything else is in it, naming each
file; **2** when the archive cannot be produced or read — because an archive nobody could inspect
is not an archive that passed.
"""

import argparse
import os
import subprocess
import sys
import tarfile
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# What a consumer needs: the autoloaded source, the manifest, the licence, and the page Packagist
# renders. `composer.json`'s own `autoload.psr-4` is the authority for the first one, so a future
# move of the source root fails here loudly rather than silently shipping nothing.
ALLOWED_FILES = {"LICENSE", "README.md", "composer.json"}
ALLOWED_PREFIX = "src/main/"


def out(message):
    sys.stdout.buffer.write((message + "\n").encode("utf-8"))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--ref", default="HEAD", help="the ref to archive (default: HEAD)")
    args = ap.parse_args()

    handle, archive = tempfile.mkstemp(suffix=".tar")
    os.close(handle)
    try:
        proc = subprocess.run(
            ["git", "-C", ROOT, "archive", args.ref, "-o", archive],
            capture_output=True, text=True,
        )
        if proc.returncode != 0:
            out(f"dist gate: FAIL — `git archive {args.ref}` failed: {proc.stderr.strip()}")
            return 2

        try:
            with tarfile.open(archive) as tar:
                members = [m.name.replace(os.sep, "/") for m in tar.getmembers() if m.isfile()]
        except (tarfile.TarError, OSError) as exc:
            out(f"dist gate: FAIL — cannot read the archive: {exc}")
            return 2
    finally:
        os.unlink(archive)

    if not members:
        # An empty archive would pass every "nothing unexpected is present" test ever written.
        out(f"dist gate: FAIL — `git archive {args.ref}` produced no files at all.")
        return 2

    source = [m for m in members if m.startswith(ALLOWED_PREFIX)]
    if not source:
        out(f"dist gate: FAIL — the archive contains nothing under {ALLOWED_PREFIX}. "
            "A dist with no source is not a dist.")
        return 2

    unexpected = sorted(
        m for m in members
        if not m.startswith(ALLOWED_PREFIX) and m not in ALLOWED_FILES
    )

    if unexpected:
        out(f"dist gate: FAIL — {len(unexpected)} file(s) in the dist that a consumer does not "
            "need:")
        for name in unexpected:
            out(f"  {name}")
        out("")
        out("  Permitted: everything under src/main/, plus " + ", ".join(sorted(ALLOWED_FILES)) + ".")
        out("  Add an `export-ignore` line in .gitattributes, or extend this gate if the file is "
            "genuinely part of the package.")
        return 1

    out(f"dist gate: OK — {len(members)} file(s), {len(source)} under {ALLOWED_PREFIX} plus "
        + ", ".join(sorted(ALLOWED_FILES)) + ".")
    out("  Asserted on the archive's CONTENTS, not on .gitattributes' rules: a rule list cannot "
        "tell you about a file nobody wrote a rule for.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
