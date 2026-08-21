#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Daniel Polo
"""Render a release-notes file as a GitHub Release body (issue #106, ROADMAP 13.5).

`release.yml`'s `draft-release` job already builds the body mechanically — `body_path` points at
`docs/releases/<tag>.md` — so the hand-written GitHub Release was never the design. It happened
because `verify-tag` failed on an unsigned tag, which skipped `draft-release` entirely, and the
body was then published by hand.

But the automated path had never run, so its output had never been looked at. It has one defect:
the notes carry **relative** links, written against `docs/releases/`. A Release body is not served
from that directory, so `../adr/0059-….md` does not resolve there — while the body a human
published carries absolute `blob/<tag>/docs/…` URLs, a conversion nothing in this repository
performed. This tool performs it, so the automated body and the reviewed body are the same text.

    python tools/release_body.py --tag v1.0.0 [--notes docs/releases/v1.0.0.md] [--repo owner/name]

Writes the rebased Markdown to stdout. Exits 2 on anything it cannot do honestly: a missing notes
file, or a relative link whose target does not exist in the tree (which would publish a 404 to
every consumer).
"""

import argparse
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Markdown inline links, minus images (`![alt](src)`): an image is not a reference a reader
# follows, and rewriting one would change what renders rather than where it points.
LINK = re.compile(r"(?<!!)\[([^\]]*)\]\(([^)]+)\)")

ABSOLUTE = ("http://", "https://", "mailto:", "tel:", "#")


def fail(message):
    # UTF-8 bytes on stderr for the same reason as stdout below: these messages carry em dashes,
    # and a Windows console encodes stderr as cp1252 too. Fixing only stdout left this stream
    # raising in the proof script -- one defect, two streams.
    sys.stderr.buffer.write((f"release_body: {message}" + "\n").encode("utf-8"))
    sys.exit(2)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--tag", required=True, help="the tag being released, e.g. v1.0.0")
    ap.add_argument("--notes", help="defaults to docs/releases/<tag>.md")
    ap.add_argument("--repo", default="danielPoloWork/egl-util-php")
    args = ap.parse_args()

    notes = args.notes or os.path.join("docs", "releases", f"{args.tag}.md")
    path = os.path.join(ROOT, notes)
    if not os.path.isfile(path):
        fail(f"{notes} does not exist — a release must carry its notes (release.md step 2)")

    with open(path, encoding="utf-8") as handle:
        body = handle.read()

    base = os.path.dirname(notes).replace(os.sep, "/")
    broken = []

    def rebase(match):
        text, target = match.group(1), match.group(2)
        if target.startswith(ABSOLUTE):
            return match.group(0)

        route, _, fragment = target.partition("#")
        if not route:
            return match.group(0)  # a same-document anchor; the Release body keeps it

        repo_rel = os.path.normpath(os.path.join(base, route)).replace(os.sep, "/")
        on_disk = os.path.join(ROOT, repo_rel)
        if not os.path.exists(on_disk):
            broken.append(target)
            return match.group(0)

        # A directory has no blob; GitHub serves it under /tree/. Its trailing slash is
        # preserved because normpath() eats it and the author wrote it deliberately — dropping
        # it is a gratuitous difference from the body a human already reviewed.
        kind = "tree" if os.path.isdir(on_disk) else "blob"
        if os.path.isdir(on_disk) and route.endswith("/"):
            repo_rel += "/"
        url = f"https://github.com/{args.repo}/{kind}/{args.tag}/{repo_rel}"
        if fragment:
            url = f"{url}#{fragment}"
        return f"[{text}]({url})"

    rendered = LINK.sub(rebase, body)

    if broken:
        fail(
            "these relative links do not resolve in the tree, so rebasing them would publish a "
            "404 to every consumer: " + ", ".join(sorted(set(broken)))
        )

    # The H1 duplicates the Release's own title on the page, so it is dropped — the same edit a
    # human makes by hand, made once here instead.
    lines = rendered.split("\n")
    if lines and lines[0].startswith("# "):
        rendered = "\n".join(lines[1:]).lstrip("\n")

    # Written as explicit UTF-8 bytes rather than through the text layer: the notes carry
    # ≥, µ and em dashes, and a Windows console defaults to cp1252, which raises on all
    # three. CI runs on Linux and would never have shown it.
    sys.stdout.buffer.write(rendered.encode("utf-8"))


if __name__ == "__main__":
    main()
