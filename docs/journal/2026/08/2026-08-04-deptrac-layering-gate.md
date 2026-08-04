# 2026-08-04 — Enforcing a rule two ADRs had already been obeying by hand

Roadmap item **3.6**. Route: the item declares `standard / medium`; session model is Opus 5, the
standard tier — match. (`route_advice.py` on a bare `enhancement` label suggests `fast/low`, but
the roadmap's own per-item route is the specific one and it is what I went by.)

## What was actually missing

RFC-0001 states the rule and names its enforcement in the same breath: *"groups depend downward on
Support only; no cross-group imports — enforced by `deptrac` in CI"*. The CI `layering` job has
existed since item 1.9 and has been self-skipping on the absence of `deptrac.yaml` ever since. So
the rule was prose.

What made this worth doing carefully rather than mechanically: **two prior ADRs had already had to
design around this rule with nothing checking them.** ADR-0006 kept
`ParameterMetadata::$attributes` as uninterpreted `list<object>` precisely so `Support` need not
name a `Dto` type; ADR-0010 re-rejected moving `CollectionOf` into `Support` because *"it inverts
the dependency RFC-0001 fixes"*. Both correct, both held in place by review attention alone. That
is the thing this item converts into a machine check.

## Choices that weren't automatic

**Directory collectors, not `classLike` name regexes.** RFC-0001 §A-9 already binds the grouping to
the tree — *"the deptrac layer globs are defined against that exact tree… case-exact below it"*. A
name-pattern collector would restate the same grouping in a second vocabulary that can drift from
the first: a class could match a layer it doesn't live in, or live in a directory no layer claims.

**`src/main` only.** Tests and benchmarks cross groups legitimately — a `Dto` test asserts on
`Support` exceptions, `HydrationBench` hydrates a DTO. Reporting those as violations trains people
to ignore the gate, which is the one failure mode a gate can't survive.

**`Version` gets its own layer.** It's a lone constant at the namespace root, in no group. Left
uncollected, deptrac reports it uncovered and the honest options become "declare it" or "suppress
the report". Declaring it makes `Uncovered: 0` mean something.

## Proving it can fail — and can pass

The roadmap item asks for a planted cross-group import. I ran three probes, not one, because
"fails on a bad import" alone doesn't distinguish a working gate from one that rejects everything:

1. **`Support → Dto`** — the exact inversion ADR-0006/ADR-0010 designed around, planted in
   `ParameterMetadata`. **1 violation, exit 1**, naming file and line.
2. **`Http → Dto`** — a peer cross-group import, the rule's other half. **1 violation.**
3. **`Http → Support`** — the *allowed* downward direction. **0 violations**, and allowed edges
   went 33 → 34, so the gate is measurably distinguishing rather than blanket-rejecting.

Each probe reverted; `git status` confirms `src/main` byte-identical. Final state: 0 violations,
0 uncovered, 33 allowed.

## The platform pin earning its keep

`composer require --dev deptrac/deptrac` resolved to **4.4.0**, not 4.7.1, with composer saying so
out loud: *"Cannot use deptrac/deptrac's latest version 4.7.1 as it requires php ^8.2 which is not
satisfied by your platform."* That's `config.platform.php: 8.1.34` from item 1.3 doing exactly what
it was added for — the library supports 8.1, so its tooling has to run there. Worth noting because
this is the first time that pin has visibly changed an outcome rather than just sitting in config.

Also of note: `qossmic/deptrac` is abandoned and redirects to `deptrac/deptrac`. Took the
maintained one.

## One local-only discrepancy, understood not hand-waved

`php-cs-fixer` flags `src/test/php/d4np/utils/BootstrapTest.php` locally while CI passes on it.
`git ls-files --eol` says `i/lf w/crlf` — LF in the index, CRLF in my Windows working tree. CI
checks out LF and sees nothing to fix. A working-tree artifact, not a finding, and not something
this item should "fix" by rewriting an untouched file.

Full bar green otherwise: PHPUnit 243/464 (7 skipped, unchanged), PHPStan max clean,
`consistency_lint.py` OK, `composer normalize`/`validate --strict` clean, `composer audit` exit 0.

## State of Milestone 3

3.1–3.6 done. Remaining: **3.7** — the NFR-01 hydration ratio gap filed by item 3.5 (~15.4× against
a ≤3× budget), still unrouted and untouched by design.
