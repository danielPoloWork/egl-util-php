#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Daniel Polo
"""Prove `tag_guard.py` refuses what it must and stays quiet otherwise (issue #115, ADR-0032).

Its strongest evidence is not synthetic and is already in hand: run against this repository's real
`v1.1.0` — annotated, unsigned, published — it refuses. That is the exact tag whose defect was
found in a CI log *after* publication, three releases running.

The repeatable half lives here, the way the other five `verify_*.py` scripts do. Each case builds a
throwaway repository and runs the real guard in it.

Two cases carry the weight:

* **a branch push produces no output at all.** A guard that comments on every ordinary push gets
  turned off for being noisy, and then it is not a guard.
* **an unreadable tag exits 2 rather than 0.** "I could not check" and "it is fine" are different
  answers, and only one of them is safe — the distinction all five of this project's vacuous-green
  defects failed to make.

Signing is not exercised: no key exists on any machine this runs on, which is issue #115's whole
subject. The signed path is therefore the one branch here that is reasoned rather than executed,
and it is called out instead of quietly implied.

    python tools/tests/verify_tag_guard.py

Exits 0 when every case behaves as specified, 1 otherwise.
"""

import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
GUARD = os.path.join(os.path.dirname(HERE), "tag_guard.py")

failures = []


def check(name, condition, detail=""):
    if condition:
        print(f"  ok   {name}")
    else:
        print(f"  FAIL {name}{(' — ' + detail) if detail else ''}")
        failures.append(name)


def repo():
    root = tempfile.mkdtemp(prefix="tagguard-")
    quiet = {"capture_output": True, "text": True, "cwd": root}
    subprocess.run(["git", "init", "-q"], **quiet)
    subprocess.run(["git", "config", "user.email", "t@t.test"], **quiet)
    subprocess.run(["git", "config", "user.name", "T"], **quiet)
    with open(os.path.join(root, "f.txt"), "w", encoding="utf-8") as handle:
        handle.write("x\n")
    subprocess.run(["git", "add", "-A"], **quiet)
    subprocess.run(["git", "commit", "-qm", "c"], **quiet)
    return root


def run(root, args, stdin=None, env=None):
    environ = dict(os.environ)
    environ.pop("EGL_UNSIGNED_TAG_REASON", None)
    if env:
        environ.update(env)
    proc = subprocess.run(
        [sys.executable, GUARD, *args],
        input=stdin, capture_output=True, text=True, encoding="utf-8", cwd=root, env=environ,
    )
    return proc.returncode, (proc.stdout or "") + (proc.stderr or "")


print("verify_tag_guard.py")

# 1. Annotated but unsigned — the defect three releases in a row shared.
root = repo()
try:
    subprocess.run(["git", "tag", "-a", "v1.2.0", "-m", "m"], capture_output=True, cwd=root)
    code, out = run(root, ["--tag", "v1.2.0"])
    check("an annotated UNSIGNED release tag is REFUSED", code == 1, f"exit {code}")
    check("...and the message names the consequences, not just the rule",
          "hand-publishing" in out and "cannot be corrected in place" in out, out.strip()[:200])
    check("...and offers the documented override",
          "EGL_UNSIGNED_TAG_REASON" in out, out.strip()[:200])

    # 2. The override: allowed, loud, and the reason echoed.
    code, out = run(root, ["--tag", "v1.2.0"],
                    env={"EGL_UNSIGNED_TAG_REASON": "deliberate, see #115"})
    check("the override allows the push", code == 0, f"exit {code}")
    check("...and echoes the reason back", "deliberate, see #115" in out, out.strip()[:200])
    check("...and still states what will fail in CI",
          "signing gate will fail" in out, out.strip()[:200])

    # 3. An empty override is not an override.
    code, out = run(root, ["--tag", "v1.2.0"], env={"EGL_UNSIGNED_TAG_REASON": "   "})
    check("a blank override does NOT allow the push", code == 1, f"exit {code}")

    # 4. A lightweight tag cannot carry a signature at all.
    subprocess.run(["git", "tag", "v1.3.0"], capture_output=True, cwd=root)
    code, out = run(root, ["--tag", "v1.3.0"])
    check("a lightweight release tag is REFUSED", code == 1, f"exit {code}")
    check("...and says it is a commit, not an annotated tag",
          "not an annotated tag" in out, out.strip()[:200])

    # 5. Cannot tell != fine.
    code, out = run(root, ["--tag", "v9.9.9"])
    check("a tag that does not exist exits 2, not 0", code == 2, f"exit {code}")
    check("...and says it is refusing rather than assuming",
          "not a tag it approved" in out, out.strip()[:200])

    # 6. Not our business: a non-release tag shape.
    subprocess.run(["git", "tag", "-a", "bridge-v0.1.0", "-m", "m"], capture_output=True, cwd=root)
    code, out = run(root, ["--tag", "bridge-v0.1.0"])
    check("a non-release tag shape is skipped, not refused", code == 0, f"exit {code}")

    # 7. ★ Silence on an ordinary push. stdin is git's pre-push format.
    code, out = run(root, ["--stdin"],
                    stdin="refs/heads/main abc123 refs/heads/main def456\n")
    check("a BRANCH push produces no output at all", code == 0 and out.strip() == "",
          f"exit {code}, out={out.strip()[:120]!r}")

    # 8. stdin mode does pick the release tag out of the lines.
    code, out = run(root, ["--stdin"],
                    stdin="refs/heads/main a b c\nrefs/tags/v1.2.0 x refs/tags/v1.2.0 y\n")
    check("stdin mode finds the release tag among other refs", code == 1, f"exit {code}")
    check("...and names it", "v1.2.0" in out, out.strip()[:160])
finally:
    shutil.rmtree(root, ignore_errors=True)

print()
if failures:
    print(f"{len(failures)} case(s) failed: {', '.join(failures)}")
    sys.exit(1)
print("all cases behaved as specified")
print("NOT exercised: the signed-tag path — no signing key exists on any machine this runs on,")
print("which is issue #115's first acceptance criterion and the maintainer's to complete.")
