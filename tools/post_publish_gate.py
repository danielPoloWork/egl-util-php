#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Daniel Polo
"""Verify a published release actually reached the world (issue #105, ADR-0081).

`release_gate.py` verifies everything up to the tag push: the tag agrees with the tree, the notes
and changelog split exist and are indexed. `release.yml`'s `verify-tag` job then checks the tag is
annotated and signed before drafting a GitHub Release — but a **draft** is not a **publish**, and
publishing is the one step this project deliberately reserves for a human (AGENTS.md §11). Nothing
in the pipeline runs *after* that click. The gap is not hypothetical: this repository's own
`v1.1.0` tag was pushed, failed `verify-tag` on an unsigned signature, and sat for three days with
no GitHub Release and nothing on Packagist while `ROADMAP.md` read as though it had shipped — and
nothing here would have said otherwise, because nothing checked.

    python tools/post_publish_gate.py --tag v1.0.0

Checks, all of which must hold for the tag to be reported as actually released:

1. **The tag object is signed and verified** — the same fact `release.yml`'s `verify-tag` job
   checks before the tag exists as a draft, re-asked here because a tag can be deleted and
   re-pushed after that job ran, or the job can simply never have run for it (`v1.1.0`'s case).
2. **A GitHub Release exists for the tag, and is not a draft.** A drafted-but-never-published
   release is indistinguishable from an unpublished one to every consumer; both report here as
   "not released".
3. **The Release body matches the canonical notes**, rendered the same way `release.yml` renders
   them (`release_body.py`, reused rather than re-implemented — two renderers of the same source
   drifting apart is its own defect class). GitHub appends its own auto-generated notes after
   whatever body is given, so this checks the canonical rendering is a **prefix** of what was
   published, not an exact match — a mismatch means either a hand-edit (`release.md`'s "do not
   hand-edit a published Release body" rule, broken) or the mechanism itself drifted.
4. **The version resolves on Packagist**, queried at the public `repo.packagist.org` mirror rather
   than `packagist.org` itself — the mirror is what Composer actually reads, and it is the origin's
   own recommended read endpoint, needing no auth and no rate limit budget spent against the main
   site.

**Absence is failure, never a pass**, the same posture every gate in this tree takes (`dist_gate.py`,
`release_gate.py`): a check that could not run is reported as exactly that, not skipped into a green
result. Network calls use `GITHUB_TOKEN`/`GH_TOKEN` when set (`action_pin_lint.py`'s pattern) and an
unauthenticated request otherwise — reading a public repo's tags and releases needs no token, only a
higher rate-limit ceiling that a token buys.

Exit **0** when every check passes; **1** when a check ran and found a real problem; **2** when a
check could not be completed at all (network unreachable, the tag or release does not exist, the
canonical renderer itself failed) — because "could not verify" and "verified fine" must never look
the same on the one artifact that cannot be corrected in place once consumers have it.
"""

import argparse
import json
import os
import subprocess
import sys
import urllib.error
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
GITHUB_API = "https://api.github.com"
PACKAGIST_API = "https://repo.packagist.org/p2"


class GateError(Exception):
    """A condition that stops the gate outright — "could not verify", exit 2."""


def _github(path, token):
    req = urllib.request.Request(f"{GITHUB_API}{path}", headers={
        "Accept": "application/vnd.github+json",
        "User-Agent": "egl-util-php-post-publish-gate",
    })
    if token:
        req.add_header("Authorization", f"Bearer {token}")
    try:
        with urllib.request.urlopen(req, timeout=20) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        if exc.code == 404:
            return None
        raise GateError(f"GitHub API {path} returned {exc.code} {exc.reason}") from exc
    except urllib.error.URLError as exc:
        raise GateError(f"GitHub API unreachable ({exc.reason}): {path}") from exc


def check_tag_signed(repo, tag, token):
    """The tag object is annotated and GitHub reports its signature verified."""
    ref = _github(f"/repos/{repo}/git/ref/tags/{tag}", token)
    if ref is None:
        raise GateError(f"no tag named {tag} exists on {repo} at all")

    obj = ref.get("object", {})
    if obj.get("type") != "tag":
        return [f"{tag} is a lightweight tag (no tagger, no message, cannot carry a signature) "
                "— it was never created with 'git tag -a -s'"]

    tag_obj = _github(f"/repos/{repo}/git/tags/{obj['sha']}", token)
    if tag_obj is None:
        raise GateError(f"the tag object {obj['sha']} for {tag} could not be read back")

    verification = tag_obj.get("verification", {})
    if verification.get("verified") is not True:
        return [f"{tag}'s signature is not verified (reason: {verification.get('reason')}) "
                "— consumers have no supply-chain assertion about who cut this release"]
    return []


def check_release_published(repo, tag, token):
    """A GitHub Release exists for the tag and is not a draft. Returns (release, problems)."""
    release = _github(f"/repos/{repo}/releases/tags/{tag}", token)
    if release is None:
        return None, [f"no GitHub Release exists for {tag} — the tag was pushed but never "
                       "published (or verify-tag never got far enough to draft one)"]
    if release.get("draft"):
        return release, [f"{tag}'s GitHub Release exists but is still a DRAFT — nobody has "
                          "clicked Publish (AGENTS.md §11's deliberate human checkpoint)"]
    return release, []


def check_body_matches_notes(repo, tag, release):
    """The published body's prefix is what release_body.py would have rendered.

    Reuses release_body.py as a subprocess rather than reimplementing the link-rebasing it does,
    for the reason release_body.py itself exists: two renderers of the same source drifting apart
    is a defect class, not a feature.
    """
    proc = subprocess.run(
        [sys.executable, os.path.join(ROOT, "tools", "release_body.py"), "--tag", tag, "--repo", repo],
        cwd=ROOT, capture_output=True,
    )
    if proc.returncode != 0:
        raise GateError(
            f"release_body.py could not render {tag}'s canonical notes (exit {proc.returncode}): "
            + proc.stderr.decode("utf-8", errors="replace").strip()
        )
    expected = proc.stdout.decode("utf-8")
    actual = release.get("body") or ""

    # A prefix, not an equality: release.yml passes generate_release_notes: true alongside
    # body_path, and GitHub appends its own auto-generated list after the given body rather than
    # replacing or prepending it — "the rendered notes are the substance; GitHub's generated list
    # is appended context" (release.yml's own comment on the step that publishes this).
    if not actual.startswith(expected.rstrip("\n")):
        return [f"{tag}'s published body does not start with the canonical rendering of "
                f"docs/releases/{tag}.md — either it was hand-edited after publishing "
                "(release.md: 'do not hand-edit a published Release body') or the render drifted"]
    return []


def check_on_packagist(package, tag):
    """The tag's version resolves on the Packagist mirror Composer actually reads."""
    req = urllib.request.Request(f"{PACKAGIST_API}/{package}.json", headers={
        "User-Agent": "egl-util-php-post-publish-gate",
    })
    try:
        with urllib.request.urlopen(req, timeout=20) as resp:
            data = json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        if exc.code == 404:
            raise GateError(f"Packagist has never heard of {package} (404)") from exc
        raise GateError(f"Packagist API returned {exc.code} {exc.reason} for {package}") from exc
    except urllib.error.URLError as exc:
        raise GateError(f"Packagist unreachable ({exc.reason})") from exc

    versions = {p.get("version") for p in data.get("packages", {}).get(package, [])}
    if tag not in versions:
        return [f"{tag} is not on Packagist for {package} — composer require would not resolve "
                f"it. Versions Packagist does have: {', '.join(sorted(versions)) or '(none)'}"]
    return []


def run(repo, package, tag, token):
    """Every check, run in order; later checks that need an earlier one's result skip cleanly
    when it was absent rather than raising past a GateError that already explains why.

    Returns (problems, could_not_verify) — the two outcomes the exit code distinguishes.
    """
    problems = []
    could_not_verify = []

    for label, fn in [
        ("tag signature", lambda: check_tag_signed(repo, tag, token)),
        ("Packagist", lambda: check_on_packagist(package, tag)),
    ]:
        try:
            problems += fn()
        except GateError as exc:
            could_not_verify.append(f"{label}: {exc}")

    try:
        release, release_problems = check_release_published(repo, tag, token)
    except GateError as exc:
        could_not_verify.append(f"GitHub Release: {exc}")
        release = None
        release_problems = []
    problems += release_problems

    if release is not None and not release.get("draft"):
        try:
            problems += check_body_matches_notes(repo, tag, release)
        except GateError as exc:
            could_not_verify.append(f"Release body: {exc}")
    elif release is None:
        could_not_verify.append("Release body: no Release exists to check the body of")

    return problems, could_not_verify


def main(argv=None):
    ap = argparse.ArgumentParser(
        description="Verify a published release reached GitHub Releases and Packagist intact."
    )
    ap.add_argument("--tag", required=True, help="the published tag, e.g. v1.0.0")
    ap.add_argument("--repo", default="danielPoloWork/egl-util-php", help="owner/name on GitHub")
    ap.add_argument("--package", default="egl/utils", help="the Packagist package name")
    args = ap.parse_args(argv)

    token = os.environ.get("GITHUB_TOKEN") or os.environ.get("GH_TOKEN")
    problems, could_not_verify = run(args.repo, args.package, args.tag, token)

    if could_not_verify:
        print(f"post-publish gate: COULD NOT VERIFY {args.tag} — {len(could_not_verify)} "
              "check(s) did not complete:")
        for item in could_not_verify:
            print(f"\n  - {item}")
        print(
            "\n'could not verify' is not 'verified fine'. Re-run once the network/API issue "
            "above is resolved before treating this release as confirmed."
        )

    if problems:
        print(f"\npost-publish gate: FAIL — {args.tag} has {len(problems)} real problem(s):")
        for item in problems:
            print(f"\n  - {item}")

    if could_not_verify or problems:
        return 2 if could_not_verify and not problems else 1

    print(
        f"post-publish gate: OK — {args.tag} is signed and verified, its GitHub Release is "
        f"published (not draft) with a body matching the canonical notes, and it resolves on "
        f"Packagist as {args.package}."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
