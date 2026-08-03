#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Daniel Polo
"""Action-pin lint for egl-util-php — the mechanical gate for ADR-0003.

ADR-0003 decides that every GitHub Actions `uses:` reference in this repository is pinned to
an immutable 40-character commit SHA, followed by a comment naming the version that SHA
corresponds to. Until this tool existed the policy was enforced by review only, which the ADR
said plainly rather than implying coverage it did not have. This is that coverage.

Two checks, with deliberately different observability:

  1. pin-shape      — OFFLINE, always runs. Every `uses:` reference names a 40-hex SHA and
                      carries a version comment. Pure text; no network, no dependencies.
  2. pin-label-truth — NETWORK, runs only with --verify-upstream. Resolves each version
                      comment against the UPSTREAM repository and compares it to the SHA
                      actually written. This is the half that matters most and the half that
                      cannot be done from local files: a cross-file consistency check cannot
                      see an error applied uniformly, and a comment nobody resolves lies for
                      exactly as long as nobody resolves it.

**The two are not interchangeable, and this tool never lets one stand in for the other.**
A run without --verify-upstream reports, in its own output, that the truth of every version
comment went unverified. Partial verification presented as complete is a dishonest gate.

Usage:

    python tools/action_pin_lint.py                    # shape only (offline)
    python tools/action_pin_lint.py --verify-upstream  # shape + comment truth (network)

Exits non-zero with an actionable report on any violation.
"""

import argparse
import json
import os
import re
import sys
import urllib.error
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
WORKFLOW_DIR = os.path.join(ROOT, ".github", "workflows")

# `uses:` values that legitimately carry no SHA, because they are not a pinnable third-party
# ref: a path-relative composite action lives in this repository (already reviewed as source),
# and a docker:// image is pinned by its own digest syntax. Recognised EXPLICITLY and reported,
# never skipped in silence — a silent exemption is how a real hole hides in a green gate.
_EXEMPT_PREFIXES = ("./", "../", "docker://")

# `- uses: owner/repo@ref  # comment` — the comment is optional at the parse level so that a
# pin missing its comment is reported as a violation rather than not matching at all.
_USES = re.compile(r"^\s*-?\s*uses:\s*(?P<ref>\S+)\s*(?:#\s*(?P<comment>.+?))?\s*$")
_SHA = re.compile(r"^[0-9a-f]{40}$")

_API = "https://api.github.com"


class Finding:
    def __init__(self, check, path, line, message):
        self.check = check
        self.path = path
        self.line = line
        self.message = message

    def __str__(self):
        return f"  [{self.check}] {self.path}:{self.line} — {self.message}"


def workflow_files():
    if not os.path.isdir(WORKFLOW_DIR):
        return []
    out = []
    for name in sorted(os.listdir(WORKFLOW_DIR)):
        if name.endswith((".yml", ".yaml")):
            out.append(os.path.join(WORKFLOW_DIR, name))
    return out


def collect_uses(path):
    """Every `uses:` reference in one workflow, as (line_no, ref, comment)."""
    rel = os.path.relpath(path, ROOT).replace(os.sep, "/")
    found = []
    with open(path, encoding="utf-8") as fh:
        for n, line in enumerate(fh, start=1):
            m = _USES.match(line.rstrip("\n"))
            if m:
                found.append((rel, n, m.group("ref"), m.group("comment")))
    return found


def check_shape(entries):
    """ADR-0003's pin shape: <action>@<40-hex> plus a version comment. Offline."""
    findings, pins, exempt = [], [], []
    for rel, line, ref, comment in entries:
        if ref.startswith(_EXEMPT_PREFIXES):
            exempt.append((rel, line, ref))
            continue
        if "@" not in ref:
            findings.append(Finding("pin-shape", rel, line,
                                    f"`{ref}` names no ref at all — ADR-0003 requires "
                                    "`owner/repo@<40-hex sha>`"))
            continue
        action, _, pinned = ref.rpartition("@")
        if not _SHA.match(pinned):
            findings.append(Finding("pin-shape", rel, line,
                                    f"`{action}` is pinned to `{pinned}`, which is a mutable "
                                    "git ref — ADR-0003 requires a 40-character commit SHA "
                                    "(a version tag is force-pushable and is NOT immutable)"))
            continue
        if not comment:
            findings.append(Finding("pin-shape", rel, line,
                                    f"`{action}` is SHA-pinned but carries no version comment "
                                    "— ADR-0003 requires `# <version>` so the pin is reviewable"))
            continue
        pins.append((rel, line, action, pinned, comment.strip()))
    return findings, pins, exempt


def _api(url, token):
    req = urllib.request.Request(url, headers={
        "Accept": "application/vnd.github+json",
        "User-Agent": "egl-util-php-action-pin-lint",
    })
    if token:
        req.add_header("Authorization", f"Bearer {token}")
    with urllib.request.urlopen(req, timeout=20) as resp:
        return json.loads(resp.read().decode("utf-8"))


def resolve_tag(action, tag, token):
    """The commit SHA a tag names upstream, dereferencing annotated tags.

    Returns (sha, None) or (None, reason). The reason is surfaced as a finding rather than
    swallowed: an unresolvable comment is exactly as unreviewable as a wrong one.
    """
    try:
        ref = _api(f"{_API}/repos/{action}/git/ref/tags/{tag}", token)
    except urllib.error.HTTPError as exc:
        if exc.code == 404:
            return None, f"upstream has no tag `{tag}`"
        return None, f"upstream lookup failed ({exc.code} {exc.reason})"
    except urllib.error.URLError as exc:
        return None, f"upstream unreachable ({exc.reason})"

    obj = ref.get("object", {})
    sha, kind = obj.get("sha"), obj.get("type")
    if kind == "tag":  # annotated tag -> dereference to the commit
        try:
            sha = _api(f"{_API}/repos/{action}/git/tags/{sha}", token)["object"]["sha"]
        except (urllib.error.HTTPError, urllib.error.URLError, KeyError) as exc:
            return None, f"annotated tag `{tag}` could not be dereferenced ({exc})"
    return sha, None


def check_label_truth(pins, token):
    """Does each version comment actually name the pinned SHA, upstream? Network."""
    findings = []
    resolved = {}
    for rel, line, action, sha, comment in pins:
        tag = comment.split()[0] if comment else ""
        key = (action, tag)
        if key not in resolved:
            resolved[key] = resolve_tag(action, tag, token)
        upstream, reason = resolved[key]
        if reason:
            findings.append(Finding("pin-label-truth", rel, line,
                                    f"`{action}` comment `{tag}`: {reason}"))
        elif upstream != sha:
            findings.append(Finding("pin-label-truth", rel, line,
                                    f"`{action}` is pinned to {sha} but its comment claims "
                                    f"`{tag}`, which upstream resolves to {upstream} — the "
                                    "comment is untrue; resolve toward upstream, never toward "
                                    "another local file"))
    return findings, len(resolved)


def main(argv=None):
    ap = argparse.ArgumentParser(description="Enforce ADR-0003's CI action-pinning policy.")
    ap.add_argument("--verify-upstream", action="store_true",
                    help="also resolve each version comment against the upstream repository "
                         "(requires network; uses GITHUB_TOKEN when set)")
    args = ap.parse_args(argv)

    files = workflow_files()
    if not files:
        print("action-pin lint: no workflow files under .github/workflows/ — nothing to check.")
        return 0

    entries = [e for f in files for e in collect_uses(f)]
    findings, pins, exempt = check_shape(entries)

    upstream_checked = 0
    if args.verify_upstream and pins:
        token = os.environ.get("GITHUB_TOKEN") or os.environ.get("GH_TOKEN")
        truth_findings, upstream_checked = check_label_truth(pins, token)
        findings += truth_findings

    if findings:
        print("action-pin lint: FAIL\n")
        for f in findings:
            print(f)
        print(f"\n{len(findings)} ADR-0003 violation(s) found.")
    else:
        print(f"action-pin lint: OK — {len(pins)} pinned reference(s) across "
              f"{len(files)} workflow(s) satisfy ADR-0003's pin shape.")

    # Honesty about reach (upstream truth is the half that cannot be observed offline).
    # A gate that reports only what it managed to check, without naming what it did not,
    # reads as complete coverage and is not.
    if args.verify_upstream:
        print(f"  verified upstream: {upstream_checked} distinct action/version pair(s) — "
              "each comment resolved against its own repository.")
    else:
        print("  NOT verified: whether each version comment truly names its pinned SHA. That "
              "check resolves against the upstream repositories and needs network — re-run "
              "with --verify-upstream (CI does).")
    if exempt:
        print(f"  exempt (local or docker refs, not pinnable third-party actions): {len(exempt)}")
        for rel, line, ref in exempt:
            print(f"    - {rel}:{line} {ref}")

    return 1 if findings else 0


if __name__ == "__main__":
    sys.exit(main())
