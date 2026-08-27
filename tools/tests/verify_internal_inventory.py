#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Daniel Polo
"""Prove `consistency_lint.py`'s `internal-inventory` check can fail (issue #111).

The check exists to close a one-directional gap: removing an `@internal` symbol already trips
`bc_gate.py`, but ADDING `@internal` to an already-frozen public symbol trips nothing — it silently
moves the symbol outside the 1.x contract. The issue's own second criterion asks for exactly this
proof before the rule is trusted: "stamp a third symbol in a throwaway branch." That was done once,
by hand, against the real tree, and the repeatable half lives here — `verify_link_check.py`'s shape,
adapted for a check whose expected set is a constant inside the linter itself rather than something
derivable from the fixture tree alone.

Each case builds a throwaway git repository, copies the real linter into it, **rewrites its
`EXPECTED_INTERNAL` constant** to a small, known fixture set (so a case can be built around three
symbols instead of the real five), writes PHP source under the fixture's `src/main/...`, and runs
the linter with `--only internal_inventory`.

    python tools/tests/verify_internal_inventory.py

Exits 0 when every case behaves as specified, 1 otherwise.
"""

import os
import re
import shutil
import subprocess
import sys
import tempfile
import textwrap

LINTER = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "consistency_lint.py"))
PY = sys.executable
FAILED = []

FIXTURE_EXPECTED = re.compile(r"EXPECTED_INTERNAL = \{.*?\}\n", re.DOTALL)


def git(args, cwd):
    result = subprocess.run(["git", *args], cwd=cwd, capture_output=True, text=True)
    assert result.returncode == 0, f"git {args} failed: {result.stderr}"


def scenario(name, expected_set, files, expect, expect_in=None, expect_not_in=None):
    work = tempfile.mkdtemp(prefix="int-")
    try:
        os.makedirs(os.path.join(work, "tools"))
        source = open(LINTER, encoding="utf-8").read()
        # `{}` is an empty DICT in Python, not an empty set — set() is required, or an empty
        # fixture crashes the copied linter with a TypeError on the `-` operator instead of
        # reporting cleanly, which is itself worth knowing before it happens for real.
        if expected_set:
            body = "".join(f'    "{s}",\n' for s in expected_set)
            rewritten = "EXPECTED_INTERNAL = {\n" + body + "}\n"
        else:
            rewritten = "EXPECTED_INTERNAL = set()\n"
        # A function replacement, not a string one: re.sub() treats a string replacement as a
        # backreference template, and the fixture symbols below carry literal backslashes
        # ("Widget\Thing") that a template would misparse as an escape sequence.
        source, n = FIXTURE_EXPECTED.subn(lambda _m: rewritten, source, count=1)
        assert n == 1, "EXPECTED_INTERNAL constant not found in consistency_lint.py — did its shape change?"
        with open(os.path.join(work, "tools", "consistency_lint.py"), "w", encoding="utf-8", newline="\n") as handle:
            handle.write(source)

        for path, body in files.items():
            full = os.path.join(work, path)
            os.makedirs(os.path.dirname(full), exist_ok=True)
            with open(full, "w", encoding="utf-8", newline="\n") as handle:
                handle.write(textwrap.dedent(body).lstrip())

        git(["init", "-q", "-b", "master"], work)
        git(["config", "user.email", "t@example.com"], work)
        git(["config", "user.name", "T"], work)
        git(["add", "-A"], work)

        result = subprocess.run(
            [PY, os.path.join("tools", "consistency_lint.py"), "--only", "internal_inventory"],
            cwd=work, capture_output=True, text=True,
        )

        ok = result.returncode == expect
        detail = ""
        if expect_in is not None and expect_in not in result.stdout:
            ok, detail = False, f" (missing: {expect_in!r})"
        if expect_not_in is not None and expect_not_in in result.stdout:
            ok, detail = False, f" (unexpected: {expect_not_in!r})"

        print(f'  [{"ok " if ok else "FAIL"}] exit {result.returncode} (want {expect}){detail}  {name}')
        if not ok:
            FAILED.append(name)
            print(textwrap.indent((result.stdout + result.stderr).strip(), "        "))
    finally:
        shutil.rmtree(work, ignore_errors=True)


SRC = "src/main/php/d4np/utils"

METHOD_INTERNAL = f"""
    <?php

    declare(strict_types=1);

    namespace D4np\\Utils\\Widget;

    final class Thing
    {{
        /**
         * @internal for this group's own use only
         */
        public function secret(): string
        {{
            return 'x';
        }}

        public function ordinary(): string
        {{
            return 'y';
        }}
    }}
"""

CLASS_INTERNAL = f"""
    <?php

    declare(strict_types=1);

    namespace D4np\\Utils\\Widget;

    /**
     * A helper the group shares.
     *
     * @internal this group's own codec only
     */
    final class Codec
    {{
        public static function encode(): string
        {{
            return 'z';
        }}
    }}
"""

# The real-world shape from Base64Url.php: a class docblock that MENTIONS `@internal` inline,
# in backtick-quoted prose, and ALSO carries the real tag on its own line. The check must see
# only the genuine tag line, never the prose mention — tested separately below by dropping the
# real tag and keeping only the prose, which must NOT be detected as internal.
PROSE_MENTION_ONLY = f"""
    <?php

    declare(strict_types=1);

    namespace D4np\\Utils\\Widget;

    /**
     * `@internal` is how this project marks a symbol outside the frozen surface (ADR-0059);
     * this class is not one of them.
     */
    final class NotInternal
    {{
        public static function encode(): string
        {{
            return 'z';
        }}
    }}
"""

print("consistency_lint --only internal_inventory -- synthetic verification\n")

scenario(
    "inventory matches exactly (method-level) -> pass",
    {"Widget\\Thing::secret()"},
    {f"{SRC}/Widget/Thing.php": METHOD_INTERNAL},
    expect=0,
)

scenario(
    "inventory matches exactly (class-level) -> pass",
    {"Widget\\Codec"},
    {f"{SRC}/Widget/Codec.php": CLASS_INTERNAL},
    expect=0,
)

scenario(
    "empty inventory, no @internal anywhere -> pass",
    set(),
    {f"{SRC}/Widget/Codec.php": PROSE_MENTION_ONLY},
    expect=0,
)

# The exact attack the issue is about: a symbol already in EXPECTED_INTERNAL stays there, but a
# SECOND symbol picks up @internal in the source with nobody having widened the pinned list.
scenario(
    "a symbol silently gains @internal, not in the pinned list -> FAIL",
    {"Widget\\Thing::secret()"},
    {
        f"{SRC}/Widget/Thing.php": METHOD_INTERNAL,
        f"{SRC}/Widget/Codec.php": CLASS_INTERNAL,
    },
    expect=1,
    expect_in="Widget\\Codec carries @internal in src/main but is not in "
              "consistency_lint.py's EXPECTED_INTERNAL",
)

# The other direction: a symbol the lint expects to be @internal no longer is — a removal that
# should have gone through bc_gate.py with a written override.
scenario(
    "a pinned symbol no longer carries @internal -> FAIL",
    {"Widget\\Thing::secret()", "Widget\\Codec"},
    {f"{SRC}/Widget/Codec.php": CLASS_INTERNAL},
    expect=1,
    expect_in="Widget\\Thing::secret() is in EXPECTED_INTERNAL but no longer carries @internal",
)

# A prose mention of the literal string `@internal` (as Base64Url.php's own docblock has,
# alongside its real tag) must not, on its own, be mistaken for the tag.
scenario(
    "a backtick-quoted prose mention of @internal is not a tag -> pass, not flagged",
    set(),
    {f"{SRC}/Widget/Codec.php": PROSE_MENTION_ONLY},
    expect=0,
    expect_not_in="Widget\\NotInternal",
)

# Multiple genuine violations are all reported, not just the first.
scenario(
    "two silently-added symbols are both reported",
    set(),
    {
        f"{SRC}/Widget/Thing.php": METHOD_INTERNAL,
        f"{SRC}/Widget/Codec.php": CLASS_INTERNAL,
    },
    expect=1,
    expect_in="Widget\\Thing::secret()",
)

print()
if FAILED:
    print(f"{len(FAILED)} case(s) did not behave as specified:")
    for name in FAILED:
        print("  -", name)
    sys.exit(1)
print("all cases behaved as specified")
