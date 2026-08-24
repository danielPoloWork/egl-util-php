#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Daniel Polo
"""Refuse to push a release tag that is not annotated and signed (issue #115, ADR-0032).

**Four releases have now failed the same step.** `v0.11.0`, `v1.0.0` and `v1.1.0` each went up as
an annotated but *unsigned* tag; `release.yml`'s signing gate refused each one, the tagged-tree
matrix and the draft job were skipped, and the Release was published by hand around the gate. Every
time, the defect was discovered *after* the tag was public — which is the one moment it cannot be
corrected in place.

This moves that discovery to the second before the push.

**It does not make an unsigned release impossible, and that is deliberate.** The maintainer has
chosen an unsigned tag three times with the outcome known in advance; a guard that simply blocked
them would be reverted or bypassed with `--no-verify`, and a bypassed guard teaches nothing. Issue
#115 asks for the failure to be impossible to repeat *silently*, so the override is explicit and
must carry a reason:

    EGL_UNSIGNED_TAG_REASON="no signing key registered yet; see #115" git push origin v1.2.0

The reason is printed, and the consequences are printed with it. An unsigned release becomes a
sentence someone wrote, rather than something noticed in a CI log afterwards.

    python tools/tag_guard.py --tag v1.2.0        # check one tag
    ... | python tools/tag_guard.py --stdin       # pre-push hook mode

Exit 0 when the tag may be pushed; 1 when it must not be; 2 when the guard cannot tell — because
"I could not check" and "it is fine" are different answers and only one of them is safe.
"""

import argparse
import os
import re
import subprocess
import sys

RELEASE_TAG = re.compile(r"^v\d+\.\d+\.\d+")
SIGNATURE = ("-----BEGIN PGP SIGNATURE-----", "-----BEGIN SSH SIGNATURE-----")
OVERRIDE = "EGL_UNSIGNED_TAG_REASON"


def out(message):
    # UTF-8 bytes: a Windows console encodes both streams as cp1252 and these messages carry
    # arrows and em dashes. Learned once per stream on issue #106.
    sys.stdout.buffer.write((message + "\n").encode("utf-8"))


def git(*args):
    return subprocess.run(["git", *args], capture_output=True, text=True, encoding="utf-8",
                          errors="replace")


def inspect(tag):
    """(kind, signed, detail) for `tag`. kind is 'tag', 'commit', or None when absent."""
    probe = git("cat-file", "-t", tag)
    if probe.returncode != 0:
        return None, False, probe.stderr.strip()
    kind = probe.stdout.strip()
    if kind != "tag":
        return kind, False, ""
    body = git("cat-file", "tag", tag)
    if body.returncode != 0:
        return kind, False, body.stderr.strip()
    return kind, any(marker in body.stdout for marker in SIGNATURE), ""


def verdict(tag):
    """0 push it, 1 refuse it, 2 cannot tell."""
    kind, signed, detail = inspect(tag)

    if kind is None:
        out(f"tag guard: CANNOT CHECK — `git cat-file -t {tag}` failed: {detail}")
        out("  Refusing rather than assuming: a tag this guard could not read is not a tag it "
            "approved.")
        return 2

    if kind != "tag":
        out(f"tag guard: REFUSED — {tag} is a {kind}, not an annotated tag.")
        out("  A lightweight tag carries no tagger, no date and no message, and cannot carry a")
        out("  signature at all. Re-create it:")
        out(f"    git tag -d {tag} && git tag -a -s {tag} -m \"<headline>\"")
        return 1

    if signed:
        # Whether it *verifies* is a separate question, and a local failure is not the tag's
        # fault: ADR-0032 makes GitHub the trust root precisely so no keyring has to be kept in
        # step here. So this reports, and does not refuse.
        check = git("verify-tag", tag)
        if check.returncode == 0:
            out(f"tag guard: OK — {tag} is annotated and signed, and verifies locally.")
        else:
            out(f"tag guard: OK — {tag} is annotated and signed.")
            out("  It does not verify with the keys on this machine, which is not necessarily a")
            out("  problem: GitHub holds the public keys and release.yml asks GitHub, not this")
            out("  keyring (ADR-0032). Worth a look if you did not expect it.")
        return 0

    reason = os.environ.get(OVERRIDE, "").strip()
    if not reason:
        out(f"tag guard: REFUSED — {tag} is annotated but NOT signed.")
        out("")
        out("  This is the exact step v0.11.0, v1.0.0 and v1.1.0 each failed. Pushing it means:")
        out("    - release.yml's signing gate fails,")
        out("    - the tagged-tree 8.1/8.2/8.3 matrix never runs,")
        out("    - the draft-Release job is skipped and the Release needs hand-publishing,")
        out("    - and a published tag cannot be corrected in place.")
        out("")
        out("  Sign it with a key registered on the GitHub account:")
        out(f"    git tag -d {tag} && git tag -a -s {tag} -m \"<headline>\"")
        out("")
        out("  Or, if an unsigned release is the deliberate choice again, say so in writing:")
        out(f'    {OVERRIDE}="why" git push origin {tag}')
        out("  The reason is printed and the tag goes up. What this guard refuses is doing it by")
        out("  accident, not doing it on purpose.")
        return 1

    out(f"tag guard: ALLOWED UNSIGNED — {tag}, on an explicit override.")
    out(f"  Reason given: {reason}")
    out("  release.yml's signing gate will fail, the tagged-tree matrix will not run, and the")
    out("  Release will need hand-publishing. Record this in the release notes BEFORE publishing,")
    out("  not after — docs/releases/ carries a 'How this release was published' section for it.")
    return 0


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--tag", action="append", default=[], help="tag to check (repeatable)")
    ap.add_argument("--stdin", action="store_true",
                    help="read git's pre-push lines from stdin and check the release tags in them")
    args = ap.parse_args()

    tags = list(args.tag)
    if args.stdin:
        for line in sys.stdin:
            parts = line.split()
            if len(parts) < 3:
                continue
            ref = parts[2]
            if ref.startswith("refs/tags/"):
                name = ref[len("refs/tags/"):]
                if RELEASE_TAG.match(name):
                    tags.append(name)

    if not tags:
        # Nothing this guard is responsible for. Silence, so it does not become noise on every
        # ordinary branch push and get disabled for being chatty.
        return 0

    worst = 0
    for tag in tags:
        if not RELEASE_TAG.match(tag):
            out(f"tag guard: skipping {tag} — not a v<MAJOR>.<MINOR>.<PATCH> release tag.")
            continue
        worst = max(worst, verdict(tag))
    return worst


if __name__ == "__main__":
    sys.exit(main())
