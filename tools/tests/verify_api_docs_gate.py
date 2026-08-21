#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Daniel Polo
"""Prove `api_docs_gate.py` can fail (issue #107, ROADMAP 13.7, ADR-0070).

The strongest evidence is already spent: the first time this gate ran against a real
phpDocumentor build it reported **three** compilation errors in `Dto/Collection.php` that the tool
itself had exited `0` on, after five more in `Errors/Result.php` found the same way. Once those are
fixed that demonstration is gone, so the repeatable half lives here — the pattern
`verify_link_check.py`, `verify_diff_coverage_gate.py` and `verify_release_body.py` established.

Each case writes a synthetic `reports/errors.html` and runs the real gate against it. The cases
that matter are the two that must NOT be silent: a report naming errors fails (1), and a
**missing** report fails (2) rather than passing — a missing report and a clean one look identical
to any check that only greps for the word ERROR, and that hole is the entire reason this gate reads
a report instead of an exit code.

    python tools/tests/verify_api_docs_gate.py

Exits 0 when every case behaves as specified, 1 otherwise.
"""

import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
GATE = os.path.join(os.path.dirname(HERE), "api_docs_gate.py")

CLEAN = """<html><body>
<h1>Errors</h1>
<p>No errors have been found in this project.</p>
</body></html>"""

WITH_ERRORS = """<html><body>
<h2>src/main/php/d4np/utils/Dto/Collection.php</h2>
<table>
<tr><th>Type</th><th>Line</th><th>Description</th></tr>
<tr><td>ERROR</td><td>121</td><td>Tag &quot;method&quot; with body &quot;@method self&lt;T&gt;&quot;
    has error self is not a collection</td></tr>
<tr><td>ERROR</td><td>107</td><td>Tag &quot;method&quot; with body &quot;@method self&lt;TOut&gt;&quot;
    has error self is not a collection</td></tr>
</table>
</body></html>"""

# Neither "no errors" nor a row this gate recognises: the shape phpDocumentor would produce if it
# changed its report format. Passing on this would be guessing.
UNRECOGNISED = """<html><body>
<h1>Errors</h1>
<div class="findings"><span data-severity="error">something went wrong</span></div>
</body></html>"""

failures = []


def check(name, condition, detail=""):
    if condition:
        print(f"  ok   {name}")
    else:
        print(f"  FAIL {name}{(' — ' + detail) if detail else ''}")
        failures.append(name)


def run(report_html):
    """Build a throwaway build/api tree and run the real gate against it."""
    root = tempfile.mkdtemp(prefix="apidocs-")
    try:
        target = os.path.join(root, "build", "api")
        if report_html is not None:
            os.makedirs(os.path.join(target, "reports"))
            with open(os.path.join(target, "reports", "errors.html"),
                      "w", encoding="utf-8") as handle:
                handle.write(report_html)
        else:
            os.makedirs(target)
        proc = subprocess.run(
            [sys.executable, GATE, target],
            capture_output=True, text=True, encoding="utf-8",
        )
        return proc.returncode, (proc.stdout or "") + (proc.stderr or "")
    finally:
        shutil.rmtree(root, ignore_errors=True)


print("verify_api_docs_gate.py")

code, out = run(CLEAN)
check("a clean report passes", code == 0, f"exit {code}: {out.strip()[:120]}")
check("...and says the verdict came from the report, not the exit code",
      "NOT from the exit code" in out, out.strip()[:120])

code, out = run(WITH_ERRORS)
check("a report naming errors FAILS", code == 1, f"exit {code}")
check("...and counts them correctly (2, not the header rows)",
      "2 compilation error(s)" in out, out.strip()[:200])
check("...and names each one", out.count("ERROR ·") == 2, out.strip()[:200])
check("...and does not count the Type/Line/Description header as a finding",
      "Type · Line" not in out, out.strip()[:200])

code, out = run(None)
check("a MISSING report fails with exit 2 rather than passing", code == 2, f"exit {code}")
check("...and says why a missing report is not a clean one",
      "did not complete" in out, out.strip()[:200])

code, out = run(UNRECOGNISED)
check("an unparseable report fails with exit 2 rather than passing", code == 2, f"exit {code}")
check("...and tells the reader to fix the parser, not relax the check",
      "do not relax" in out, out.strip()[:200])

print()
if failures:
    print(f"{len(failures)} case(s) failed: {', '.join(failures)}")
    sys.exit(1)
print("all cases behaved as specified")
