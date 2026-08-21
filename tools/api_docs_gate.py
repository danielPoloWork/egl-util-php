#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Daniel Polo
"""Enforce AGENTS.md §10's "phpDocumentor builds without warnings" (issue #107, ROADMAP 13.7).

**Why this exists rather than just reading phpDocumentor's exit code.** phpDocumentor exits **0**
with compilation errors. Measured on this repository before anything was wired: the console printed
`All done in 20 seconds!`, the exit status was `0`, and `build/api/reports/errors.html` held **five
ERROR rows** — every `@return self<U>` in `Errors/Result.php`, which its type parser rejects with
*"self is not a collection"*. A CI job that ran the tool and trusted `$?` would have reported green
having verified nothing: item 10.8's failure class (a mutation gate that ran on no config for
months), item 2.7's, and 13.2's harness, now for a third tool.

So the verdict is taken from the report phpDocumentor writes, not from how it exited.

    python tools/api_docs_gate.py build/api

Exit 0 when the errors report says there are none; 1 when it names any; **2** when the report is
absent or unreadable — because a missing report is indistinguishable from a clean one to anything
that only checks for failures, and that is exactly the hole this tool exists to close.
"""

import argparse
import html
import os
import re
import sys

# phpDocumentor renders each finding as a table row: line number, the tag, the message, severity.
ROW = re.compile(r"<tr[^>]*>(.*?)</tr>", re.S | re.I)
CELL = re.compile(r"<t[dh][^>]*>(.*?)</t[dh]>", re.S | re.I)
TAGS = re.compile(r"<[^>]+>")

# The sentence phpDocumentor emits when the report is genuinely empty. Matched so that an empty
# report and an unparseable one are distinguishable — see the exit-2 contract above.
EMPTY = "no errors have been found"

# The severities phpDocumentor puts in the first cell of a finding row.
SEVERITIES = {"ERROR", "WARNING", "NOTICE", "CRITICAL", "ALERT", "EMERGENCY"}


def out(message):
    # Explicit UTF-8 bytes: these messages quote docblock tags that carry non-ASCII, and a Windows
    # console encodes both streams as cp1252. Learned twice on issue #106 — once per stream.
    sys.stdout.buffer.write((message + "\n").encode("utf-8"))


def text_of(fragment):
    return html.unescape(TAGS.sub(" ", fragment)).strip()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("target", nargs="?", default="build/api",
                    help="the directory phpDocumentor wrote (default: build/api)")
    args = ap.parse_args()

    report = os.path.join(args.target, "reports", "errors.html")
    if not os.path.isfile(report):
        out(f"api-docs gate: FAIL — {report} does not exist.")
        out("  phpDocumentor writes this report on every run, so its absence means the build did "
            "not complete — not that it was clean. Refusing to pass on a missing report.")
        return 2

    try:
        with open(report, encoding="utf-8", errors="replace") as handle:
            body = handle.read()
    except OSError as exc:
        out(f"api-docs gate: FAIL — cannot read {report}: {exc}")
        return 2

    flat = " ".join(text_of(body).lower().split())
    if EMPTY in flat:
        out("api-docs gate: OK — phpDocumentor reports no compilation errors.")
        out("  Verdict read from reports/errors.html, NOT from the exit code: phpDocumentor exits "
            "0 even when that report names errors.")
        return 0

    # Only rows whose first cell is a severity are findings. The report also carries a per-file
    # heading row and a `Type | Line | Description` header row, and counting those inflated the
    # first run's verdict from 3 to 5 — a gate that cannot count is only marginally better than
    # one that cannot see.
    findings = []
    for row in ROW.findall(body):
        cells = [c for c in (text_of(c) for c in CELL.findall(row)) if c]
        if cells and cells[0].upper() in SEVERITIES:
            findings.append(" · ".join(cells))

    if not findings:
        # The report exists, does not say it is empty, and yielded no rows this parser recognises.
        # Failing is the only honest answer: passing would be guessing that "unparseable" means
        # "fine", which is the assumption this whole tool refuses to make.
        out(f"api-docs gate: FAIL — {report} neither reports 'no errors' nor any row this gate "
            "recognises.")
        out("  phpDocumentor's report format may have changed. Read the file, then fix this parser "
            "— do not relax the check.")
        return 2

    out(f"api-docs gate: FAIL — phpDocumentor reports {len(findings)} compilation error(s):")
    for finding in findings:
        out(f"  {finding}")
    out("")
    out("  These are documentation defects, not build noise: a tag phpDocumentor cannot parse is a "
        "tag it silently drops from the published reference.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
