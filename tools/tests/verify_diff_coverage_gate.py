#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Daniel Polo
"""Prove `tools/diff_coverage_gate.py` can fail, before anyone trusts it (issue #109, ADR-0068).

The standing method on this project — items 1.11 and 2.7 — is that a gate is not trusted until it
has been watched failing. Every prior tool here satisfied it by hand, in a scratch directory, and
wrote the outcome into an ADR; item 10.9's `bench_regression_gate.py` verification was eight
synthetic phpbench documents nobody can re-run today. **This ships the proof instead of describing
it**, which is the whole difference between an assertion and a claim: it is the first executable
check for any `tools/*.py` in this repository, and CI runs it in the `consistency` job.

Each case builds a throwaway git repository, so the diff half is exercised for real rather than
stubbed. That matters because the diff parsing *is* half the tool — a fixture feeding it a canned
hunk list would test the easier half and leave the `@@` arithmetic, the `/dev/null` cases and the
three-dot range unexercised.

Four of the fifteen cases are the ones that matter: a wholly untested addition must fail, a
partially covered one must fail, a diff that is untested while TOTAL coverage is high must still
fail (that is issue #109's exact complaint), and every way the gate cannot run must exit 2 rather
than 0.

    python tools/tests/verify_diff_coverage_gate.py

Exits 0 when every case behaves as specified, 1 otherwise.
"""
import os, subprocess, sys, tempfile, textwrap, shutil

GATE = os.path.abspath('tools/diff_coverage_gate.py')
PY = sys.executable
FAILED = []


def run(cmd, cwd):
    return subprocess.run(cmd, cwd=cwd, capture_output=True, text=True)


def git(args, cwd):
    r = run(['git', *args], cwd)
    assert r.returncode == 0, f'git {args} failed: {r.stderr}'
    return r.stdout


def clover(files):
    """files: {relative_path: {line: hits}} -> a minimal Clover document."""
    parts = ['<?xml version="1.0" encoding="UTF-8"?>', '<coverage generated="1">', '  <project timestamp="1">']
    for path, lines in files.items():
        parts.append(f'    <file name="/runner/work/repo/{path}">')
        for num, hits in sorted(lines.items()):
            parts.append(f'      <line num="{num}" type="stmt" count="{hits}"/>')
        parts.append('      <line num="1" type="method" count="0"/>')
        parts.append('    </file>')
    parts += ['  </project>', '</coverage>']
    return '\n'.join(parts) + '\n'


def scenario(name, base_files, head_files, report, extra_args=(), expect=None, expect_in=None,
             expect_not_in=None):
    work = tempfile.mkdtemp(prefix='dcg-')
    try:
        git(['init', '-q', '-b', 'master'], work)
        git(['config', 'user.email', 't@example.com'], work)
        git(['config', 'user.name', 'T'], work)

        for path, body in base_files.items():
            full = os.path.join(work, path)
            os.makedirs(os.path.dirname(full), exist_ok=True)
            open(full, 'w', newline='\n').write(body)
        git(['add', '-A'], work)
        git(['commit', '-qm', 'base'], work)
        git(['checkout', '-q', '-b', 'feature'], work)

        for path in base_files:
            if path not in head_files:
                os.remove(os.path.join(work, path))
        for path, body in head_files.items():
            full = os.path.join(work, path)
            os.makedirs(os.path.dirname(full), exist_ok=True)
            open(full, 'w', newline='\n').write(body)
        git(['add', '-A'], work)
        git(['commit', '-qm', 'change'], work)

        if report is not None:
            os.makedirs(os.path.join(work, 'build/logs'), exist_ok=True)
            open(os.path.join(work, 'build/logs/clover.xml'), 'w', newline='\n').write(report)

        r = run([PY, GATE, 'build/logs/clover.xml', '--base', 'master', *extra_args], work)
        ok = (r.returncode == expect)
        detail = ''
        if expect_in is not None and expect_in not in r.stdout:
            ok = False
            detail = f' (missing text: {expect_in!r})'
        if expect_not_in is not None and expect_not_in in r.stdout:
            ok = False
            detail = f' (unexpected text: {expect_not_in!r})'
        print(f'  [{"ok " if ok else "FAIL"}] exit {r.returncode} (want {expect}){detail}  {name}')
        if not ok:
            FAILED.append(name)
            print(textwrap.indent(r.stdout.strip() + r.stderr.strip(), '        '))
    finally:
        shutil.rmtree(work, ignore_errors=True)


SRC = 'src/main/php/d4np/utils/Thing.php'
BASE = {SRC: '<?php\nclass Thing {\n    public function a() { return 1; }\n}\n'}
# Four statements added at lines 4-7.
GREW = {SRC: '<?php\nclass Thing {\n    public function a() { return 1; }\n'
             '    public function b() { return 2; }\n'
             '    public function c() { return 3; }\n'
             '    public function d() { return 4; }\n'
             '    public function e() { return 5; }\n}\n'}

print('diff coverage gate -- synthetic verification\n')

scenario('all four added statements covered -> pass',
         BASE, GREW, clover({SRC: {3: 5, 4: 1, 5: 1, 6: 1, 7: 1}}), expect=0,
         expect_in='4/4 changed statements covered = 100.00%')

scenario('one of four uncovered (75%) -> FAIL, and names the line',
         BASE, GREW, clover({SRC: {3: 5, 4: 1, 5: 1, 6: 1, 7: 0}}), expect=1,
         expect_in=f'{SRC}:7')

scenario('an entirely untested addition -> FAIL (the issue #109 case)',
         BASE, GREW, clover({SRC: {3: 5, 4: 0, 5: 0, 6: 0, 7: 0}}), expect=1,
         expect_in='0/4 changed statements covered')

scenario('total coverage is high but the diff is untested -> still FAIL',
         BASE, GREW,
         clover({SRC: {3: 5, 4: 0, 5: 0, 6: 0, 7: 0},
                 'src/main/php/d4np/utils/Other.php': {i: 9 for i in range(1, 400)}}),
         expect=1, expect_in='0/4 changed statements covered')

scenario('9 of 10 covered (90%) -> pass, the floor is inclusive',
         {SRC: '<?php\n'},
         {SRC: '<?php\n' + ''.join(f'$x{i} = {i};\n' for i in range(1, 11))},
         clover({SRC: {**{i: 1 for i in range(2, 11)}, 11: 0}}), expect=0,
         expect_in='9/10 changed statements covered = 90.00%')

# The reason this case exists: the gate's first three real readings were 100%, 100% and 95.43%,
# and the 95.43% meant eight never-executed statements that the tool declined to name because it
# had passed. A passing run must still say which lines are dead.
scenario('a passing run still names the uncovered lines',
         {SRC: '<?php\n'},
         {SRC: '<?php\n' + ''.join(f'$x{i} = {i};\n' for i in range(1, 11))},
         clover({SRC: {**{i: 1 for i in range(2, 11)}, 11: 0}}), expect=0,
         expect_in=f'{SRC}:11')

scenario('a fully covered run says nothing about uncovered lines',
         BASE, GREW, clover({SRC: {3: 5, 4: 1, 5: 1, 6: 1, 7: 1}}), expect=0,
         expect_not_in='never executed')

scenario('a docs-only change -> pass, saying nothing was measurable',
         {'README.md': 'a\n', SRC: '<?php\n$a = 1;\n'},
         {'README.md': 'a\nb\n', SRC: '<?php\n$a = 1;\n'},
         clover({SRC: {2: 1}}), expect=0,
         expect_in='no coverable statements changed')

scenario('changed lines that are comments only -> pass, nothing coverable',
         {SRC: '<?php\n$a = 1;\n'},
         {SRC: '<?php\n// a new comment\n$a = 1;\n'},
         clover({SRC: {3: 1}}), expect=0,
         expect_in='no coverable statements changed')

scenario('a missing report -> CANNOT RUN (exit 2), never a pass',
         BASE, GREW, None, expect=2, expect_in='no coverage report at')

scenario('an unparseable report -> CANNOT RUN (exit 2)',
         BASE, GREW, '<coverage><project>truncated', expect=2, expect_in='not parseable')

scenario('a report measuring zero statements -> CANNOT RUN (exit 2)',
         BASE, GREW, clover({}), expect=2, expect_in='zero statement lines')

scenario('an unresolvable base ref -> CANNOT RUN (exit 2), not an empty diff',
         BASE, GREW, clover({SRC: {4: 1}}), extra_args=('--base', 'origin/nope'),
         expect=2, expect_in='base ref was never fetched')

scenario('--report-only prints the shortfall and exits 0',
         BASE, GREW, clover({SRC: {4: 0, 5: 0, 6: 0, 7: 0}}),
         extra_args=('--report-only',), expect=0, expect_in='BELOW FLOOR (report-only)')

scenario('a deleted file contributes nothing',
         {SRC: '<?php\n$a = 1;\n', 'src/main/php/d4np/utils/Gone.php': '<?php\n$b = 2;\n'},
         {SRC: '<?php\n$a = 1;\n'},
         clover({SRC: {2: 1}}), expect=0, expect_in='no coverable statements changed')

scenario('changes outside --path are ignored',
         {SRC: '<?php\n$a = 1;\n', 'src/test/php/T.php': '<?php\n$t = 1;\n'},
         {SRC: '<?php\n$a = 1;\n', 'src/test/php/T.php': '<?php\n$t = 1;\n$u = 2;\n'},
         clover({SRC: {2: 1}, 'src/test/php/T.php': {3: 0}}), expect=0,
         expect_in='no coverable statements changed')

scenario('a method-type Clover row is not a statement',
         {SRC: '<?php\n'}, {SRC: '<?php\n$a = 1;\n'},
         clover({SRC: {2: 1}}), expect=0, expect_in='1/1 changed statements covered')

print()
if FAILED:
    print(f'{len(FAILED)} case(s) did not behave as specified:')
    for name in FAILED:
        print('  -', name)
    sys.exit(1)
print('all cases behaved as specified')
