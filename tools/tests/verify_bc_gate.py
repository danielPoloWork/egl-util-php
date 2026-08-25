#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Daniel Polo
"""Prove `bc_gate.py`'s version-constant discount is narrow (ADR-0031, amended).

The gate failed **every release PR by construction**, and nobody knew, because the job that runs
it self-skips when no tag exists to compare against — so before `v1.0.0` it reported green having
compared nothing, exactly the shape items 2.7, 10.8, 13.2 and 13.7 took. The first time it ever
really ran, on the `v1.1.0` release PR, its sole finding was:

    - [BC] Value of constant D4np\\Utils\\Version::VERSION changed from '1.0.0' to '1.1.0'

That is the version bump. Discounting it is correct; discounting anything *else* would gut the
only gate standing between a MINOR and a silent break, so the two cases that matter here are the
third and fourth: the version line **alongside** a real break still fails, and a real break alone
still fails.

    python tools/tests/verify_bc_gate.py

Exits 0 when every case behaves as specified, 1 otherwise.
"""

import os
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
GATE = os.path.join(os.path.dirname(HERE), "bc_gate.py")

VERSION_ONLY = (
    "# Backward compatibility check\n\n"
    "- [BC] Value of constant D4np\\Utils\\Version::VERSION changed from '1.0.0' to '1.1.0'\n"
)
REAL_BREAK = (
    "- [BC] Method D4np\\Utils\\Errors\\Result#orElseThrow() was removed\n"
)
UNRECOGNISED = "the checker said something this gate has never seen\n"

failures = []


def check(name, condition, detail=""):
    if condition:
        print(f"  ok   {name}")
    else:
        print(f"  FAIL {name}{(' — ' + detail) if detail else ''}")
        failures.append(name)


def run(previous, current, bc_exit, report=None, report_only=False):
    args = [sys.executable, GATE, "--previous", previous, "--bc-exit", str(bc_exit)]
    if current is not None:
        args += ["--current", current]
    if report_only:
        args.append("--report-only")
    path = None
    if report is not None:
        fd, path = tempfile.mkstemp(suffix=".md")
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            handle.write(report)
        args += ["--report", path]
    try:
        # PYTHONIOENCODING, because the gate's output is read back and asserted against. Without
        # it the child writes stdout in the console codepage — cp1252 on Windows — while this
        # decodes as UTF-8, and the first em dash raises UnicodeDecodeError inside subprocess's
        # reader thread. The result is worse than a crash: the exit codes still arrive, so the
        # `code == N` cases pass while every "…and says so" case fails for a reason that has
        # nothing to do with the gate. Three cases were failing that way on Windows and green on
        # CI's UTF-8 Linux, which is how it went unnoticed.
        env = dict(os.environ, PYTHONIOENCODING="utf-8")
        proc = subprocess.run(args, capture_output=True, text=True, encoding="utf-8", env=env)
        return proc.returncode, (proc.stdout or "") + (proc.stderr or "")
    finally:
        if path:
            os.unlink(path)


print("verify_bc_gate.py")

# 1. The case that was failing every release: a post-1.0 MINOR whose only finding is the bump.
code, out = run("1.0.0", "1.1.0", 3, VERSION_ONLY)
check("a post-1.0 MINOR whose only break is Version::VERSION passes", code == 0, f"exit {code}")
check("...and says so, rather than discounting silently", "DISCOUNTED 1 finding" in out,
      out.strip()[:160])

# 2. Unchanged behaviour without --report: the old contract still holds.
code, out = run("1.0.0", "1.1.0", 3)
check("without --report a post-1.0 MINOR with breaks still FAILS", code == 1, f"exit {code}")

# 3. ★ THE ONE THAT MATTERS: the version line alongside a real break must still fail.
code, out = run("1.0.0", "1.1.0", 3, VERSION_ONLY + REAL_BREAK)
check("the version line ALONGSIDE a real break still FAILS", code == 1, f"exit {code}")
check("...and still reports the discount, so the real break is not hidden by it",
      "DISCOUNTED 1 finding" in out, out.strip()[:160])

# 4. A real break on its own, post-1.0 MINOR.
code, out = run("1.0.0", "1.1.0", 3, REAL_BREAK)
check("a real break alone still FAILS a post-1.0 MINOR", code == 1, f"exit {code}")
check("...with no discount claimed", "DISCOUNTED" not in out, out.strip()[:160])

# 5. A PATCH may never break, version constant or not — a PATCH bump changes it too.
code, out = run("1.0.0", "1.0.1", 3, VERSION_ONLY)
check("a PATCH whose only break is the version constant passes", code == 0, f"exit {code}")
code, out = run("1.0.0", "1.0.1", 3, VERSION_ONLY + REAL_BREAK)
check("a PATCH with a real break still FAILS", code == 1, f"exit {code}")

# 6. Pre-1.0 is unaffected: SemVer §4 already permitted these.
code, out = run("0.7.0", "0.8.0", 3, REAL_BREAK)
check("a pre-1.0 MINOR with a real break still passes (SemVer §4)", code == 0, f"exit {code}")

# 7. Absence is failure. An unreadable or unrecognisable report is not a clean one.
code, out = run("1.0.0", "1.1.0", 3, UNRECOGNISED)
check("a report with no recognisable [BC] line FAILS", code == 1, f"exit {code}")
check("...and tells the reader to fix the parser, not relax the check",
      "do not relax" in out, out.strip()[:200])

code, out = run("1.0.0", "1.1.0", 3)
missing = [sys.executable, GATE, "--previous", "1.0.0", "--current", "1.1.0",
           "--bc-exit", "3", "--report", os.path.join(HERE, "no-such-report.md")]
proc = subprocess.run(missing, capture_output=True, text=True, encoding="utf-8")
check("a missing report FAILS rather than passing", proc.returncode == 1,
      f"exit {proc.returncode}")

# 8. No breaks at all: --report is irrelevant and must not be consulted.
code, out = run("1.0.0", "1.1.0", 0, REAL_BREAK)
check("bc-exit 0 passes regardless of what the report file contains", code == 0, f"exit {code}")

# ---- --report-only, the per-PR mode added for issue #112 --------------------------------------
#
# Its contract is exactly two claims, and they pull against each other: it must NEVER fail for a
# finding, and it must ALWAYS fail when it could not read one. A mode that got the second half
# wrong would be a permanently green tick over an unanswered question — the failure this
# repository has now had six times.

# 9. A real break reports and does not fail...
code, out = run("v1.0.0", None, 3, REAL_BREAK, report_only=True)
check("report-only exits 0 on a real break", code == 0, f"exit {code}")
check("...and names the break rather than only counting it", "orElseThrow" in out,
      out.strip()[:200])
check("...and says it is report-only, so nobody reads it as a gate", "REPORT ONLY" in out,
      out.strip()[:200])
check("...and ends with a machine-readable count for the workflow", "findings=1" in out,
      out.strip()[-80:])

# 10. The version-constant discount applies here too — and it is the ONLY finding a normal PR
#     produces against the frozen baseline, because master's VERSION is ahead of it by design.
code, out = run("v1.0.0", None, 3, VERSION_ONLY, report_only=True)
check("report-only discounts the version constant", "DISCOUNTED 1 finding" in out,
      out.strip()[:200])
check("...leaving nothing to report", "findings=0" in out, out.strip()[-80:])
check("...and no REPORT ONLY warning when there is nothing to warn about",
      "REPORT ONLY" not in out, out.strip()[:200])

# 11. ★ The half that must still fail: absence.
code, out = run("v1.0.0", None, 3, UNRECOGNISED, report_only=True)
check("report-only FAILS on a report it cannot parse", code == 1, f"exit {code}")

proc = subprocess.run(
    [sys.executable, GATE, "--previous", "v1.0.0", "--bc-exit", "3", "--report-only",
     "--report", os.path.join(HERE, "no-such-report.md")],
    capture_output=True, text=True, encoding="utf-8",
    env=dict(os.environ, PYTHONIOENCODING="utf-8"),
)
check("report-only FAILS on a missing report", proc.returncode == 1, f"exit {proc.returncode}")

code, out = run("v1.0.0", None, 3, None, report_only=True)
check("report-only FAILS when breaks were found and no --report was given", code == 1,
      f"exit {code}")

code, out = run("v1.0.0", None, "not-a-number", None, report_only=True)
check("report-only FAILS on a non-integer --bc-exit", code == 1, f"exit {code}")

# 12. The clean case, which is what every PR should print.
code, out = run("v1.0.0", None, 0, None, report_only=True)
check("report-only passes and says nothing broke when nothing broke", code == 0, f"exit {code}")
check("...naming the baseline it compared against", "v1.0.0" in out, out.strip()[:200])

# 13. The gate is untouched by the new flag: no --current is a usage error outside report-only.
code, out = run("1.0.0", None, 3, REAL_BREAK)
check("without --report-only, a missing --current is refused", code != 0, f"exit {code}")

print()
if failures:
    print(f"{len(failures)} case(s) failed: {', '.join(failures)}")
    sys.exit(1)
print("all cases behaved as specified")
