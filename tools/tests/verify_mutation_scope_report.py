#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Daniel Polo
"""Prove `mutation_scope_report.py` splits Infection's log correctly — and refuses when it cannot.

The script re-implements Infection's MSI formula in order to apply it per namespace. A
re-implementation that quietly disagrees with the tool it is quoting is the whole risk here: it
would print a table of plausible, wrong numbers, and issue #108 exists so a maintainer can set a
spec floor from exactly those numbers.

So the case that matters most is **case 3**: a log whose `stats.msi` disagrees with its own mutant
arrays must be refused, not averaged over. If that check ever stops working, every other assertion
in this file still passes.

    python tools/tests/verify_mutation_scope_report.py

Exits 0 when every case behaves as specified, 1 otherwise.
"""

import json
import os
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
SCRIPT = os.path.join(os.path.dirname(HERE), "mutation_scope_report.py")
ROOT = "src/main/php/d4np/utils"

failures = []


def check(name, condition, detail=""):
    if condition:
        print(f"  ok   {name}")
    else:
        print(f"  FAIL {name}{(' — ' + detail) if detail else ''}")
        failures.append(name)


def mutant(namespace, cls="Thing"):
    return {"mutator": {"originalFilePath": f"/repo/{ROOT}/{namespace}/{cls}.php"}}


def log(stats_msi, **buckets):
    """An Infection-shaped JSON log. `buckets` maps a status key to a list of namespaces."""
    data = {"stats": {"msi": stats_msi}}
    for key, namespaces in buckets.items():
        data[key] = [mutant(n) for n in namespaces]
    return data


def run(payload, *extra, raw=None):
    fd, path = tempfile.mkstemp(suffix=".json")
    with os.fdopen(fd, "w", encoding="utf-8") as handle:
        handle.write(raw if raw is not None else json.dumps(payload))
    try:
        proc = subprocess.run(
            [sys.executable, SCRIPT, path, "--root", ROOT, *extra],
            capture_output=True, text=True, encoding="utf-8",
            env=dict(os.environ, PYTHONIOENCODING="utf-8"),
        )
        return proc.returncode, (proc.stdout or "") + (proc.stderr or "")
    finally:
        os.unlink(path)


print("verify_mutation_scope_report.py")

# 1. The happy path. 3 killed + 1 escaped = 4 tested, MSI 75%.
#    Persistence: 2 killed, 1 escaped -> 66.67%.  Database: 1 killed -> 100%.
code, out = run(log(75.0, killed=["Persistence", "Persistence", "Database"], escaped=["Persistence"]))
check("a well-formed log is reported", code == 0, f"exit {code}\n{out[:400]}")
check("...with the full-tree MSI", "75.00%" in out, out[:400])
check("...and Persistence split out on its own", "66.67%" in out, out[:400])
check("...and Database split out on its own", "100.00%" in out, out[:400])
check("...saying plainly that it asserts no floor", "advisory" in out.lower(), out[-400:])

# 2. Infection's own extra kill/error buckets count toward `detected`, as its Calculator does.
code, out = run(log(100.0, killed=["Dto"], killedByStaticAnalysis=["Dto"],
                    errored=["Dto"], syntaxErrors=["Dto"], timeouted=["Dto"]))
check("killedByStaticAnalysis, errors, syntaxErrors and timeouts all count as detected",
      code == 0 and "100.00%" in out, f"exit {code}\n{out[:400]}")

# 3. ★ THE ONE THAT MATTERS: the script's arithmetic disagreeing with Infection's is refused.
code, out = run(log(99.0, killed=["Persistence"], escaped=["Persistence"]))  # really 50%
check("a log whose stats.msi contradicts its mutant arrays FAILS", code == 1, f"exit {code}")
check("...and says the parser is what to fix, not the tolerance",
      "do not widen the tolerance" in out, out[:600])
# `covMSI` appears only in the table header, so its absence is an unambiguous "no table was
# printed" — unlike the word "namespace", which the refusal message itself uses.
check("...and prints no per-namespace table it cannot vouch for", "covMSI" not in out, out[:400])

# 4. `ignored` is excluded from the denominator, exactly as Infection excludes it.
#    2 killed + 1 escaped tested = 66.67%; the ignored mutant must not make it 50%.
code, out = run(log(66.67, killed=["Http", "Http"], escaped=["Http"], ignored=["Http"]))
check("ignored mutants are excluded from the denominator", code == 0, f"exit {code}\n{out[:400]}")
check("...leaving the MSI at 66.67%", "66.67%" in out, out[:400])

# 5. Uncovered mutants lower MSI but not covered-MSI.
#    1 killed, 1 uncovered -> MSI 50%, covered MSI 100%.
code, out = run(log(50.0, killed=["Mail"], uncovered=["Mail"]))
check("an uncovered mutant lowers MSI", code == 0 and "50.00%" in out, f"exit {code}\n{out[:400]}")
check("...while covered MSI stays 100%", "100.00%" in out, out[:400])

# 6. Absence is failure, in each of its shapes.
proc = subprocess.run(
    [sys.executable, SCRIPT, os.path.join(HERE, "no-such-log.json")],
    capture_output=True, text=True, encoding="utf-8",
    env=dict(os.environ, PYTHONIOENCODING="utf-8"),
)
check("a missing log FAILS rather than passing", proc.returncode == 1, f"exit {proc.returncode}")

code, out = run(None, raw="{ not json at all")
check("an unparseable log FAILS", code == 1, f"exit {code}")

code, out = run({"escaped": []})
check("a log with no stats object FAILS", code == 1, f"exit {code}")

code, out = run(log(0.0))
check("a log recording no mutants at all FAILS", code == 1, f"exit {code}")

code, out = run({"stats": {"msi": 50.0}, "killed": [{"mutator": {}}]})
check("a mutant with no originalFilePath FAILS rather than being dropped", code == 1,
      f"exit {code}")

code, out = run({"stats": {}, "killed": [mutant("Dto")]})
check("a log whose stats.msi is absent FAILS — the arithmetic could not be checked",
      code == 1, f"exit {code}")

# 7. A mutant outside the source root is surfaced, never silently dropped.
payload = log(100.0, killed=["Dto"])
payload["killed"].append({"mutator": {"originalFilePath": "/repo/src/bench/php/Whatever.php"}})
payload["stats"]["msi"] = 100.0
code, out = run(payload)
check("a mutant outside the source root is labelled rather than dropped",
      code == 0 and "outside the source root" in out, f"exit {code}\n{out[:500]}")

print()
if failures:
    print(f"{len(failures)} case(s) failed: {', '.join(failures)}")
    sys.exit(1)
print("all cases behaved as specified")
