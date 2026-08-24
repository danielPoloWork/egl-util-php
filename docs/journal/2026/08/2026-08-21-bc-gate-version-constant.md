# 2026-08-21 — The gate that would have failed every release, forever

A defect fix, annotating **ADR-0031**. Route `standard / medium`; session model Opus 5 — matched.
Found by the `v1.1.0` release PR (#156), which is still open and blocked on this.

## What happened

The release PR went red on `quality / backward compatibility` in 22 seconds. The checker's sole
finding:

```
- [BC] Value of constant D4np\Utils\Version::VERSION changed from '1.0.0' to '1.1.0'
The checker exited 3 (0 means no backward-incompatible change).
bc gate: FAIL — backward-incompatible changes in a MINOR bump after 1.0.
```

That is the version bump. `Version::VERSION` is a public constant, Roave reports a public
constant's value changing as a break, and **a release PR changes exactly that constant by
definition**. The gate could never have passed a post-1.0 MINOR or PATCH release. Not "was likely
to fail" — *could not pass*.

## Why nobody knew

The job self-skips when there is no tag to compare against. Before `v1.0.0` existed it emitted:

```
##[notice]This repository has no v*.*.* tag, and roave/backward-compatibility-check needs one
to compare against. The gate self-enables at the first tag.
```

…and reported green. It also only runs on release PRs, and there had been exactly one — `v1.0.0`,
whose comparison target had just been deleted. So this was the **first time the job ever actually
executed**, and its first real act was to refuse the release it exists to protect.

I had written this exact warning into memory after item 10.8: *never cite that check as evidence
until a tag exists*. The warning was right and still insufficient — knowing a check has not run
tells you nothing about what it will say when it does.

**Fifth instance of the vacuous-green class on this project**: item 2.7 (a gate wired to nothing),
10.8 (a mutation gate passing in ~7s on an absent config), 13.2 (a harness printing a PHP block as
text), 13.7 (phpDocumentor exiting 0 with five ERROR rows), and now this one — a gate that reported
green because it had nothing to compare, hiding that it would refuse everything once it did.

## The fix, and the line I did not cross

`bc_gate.py` gains `--report` and discounts **one** finding: that exact symbol, anchored to its
class. Not "constant value changes are fine" — a consumer reading a version string does not break
when the string changes, but a consumer reading any *other* constant might.

Two properties matter more than the discount itself:

- **It is printed on every run that uses it.** A discount nobody sees is a discount nobody can
  audit.
- **It does not swallow company.** The version line *alongside* a real break still fails, and that
  is the case `tools/tests/verify_bc_gate.py` exists to pin. Fourteen cases; that one is the reason
  for all the others.

Without `--report` the tool behaves exactly as before, so the change is additive to its contract as
well as to its behaviour.

## My own test found a robustness hole

Reproducing the CI failure locally, the gate **refused** — `contains no [BC] line this gate
recognises` — and it was right to. PowerShell 5.1's `Out-File -Encoding utf8` writes a **BOM**,
which sits in front of `- [BC]` and defeats the line anchor. CI's `tee` writes no BOM, so this
would never have appeared there.

Reading with `utf-8-sig` instead of `utf-8` costs one word and removes a failure mode that reads as
*"the report format changed"* when nothing changed. Worth noting that the gate's wrong answer here
was still the **safe** answer: it refused rather than passed. That is the direction to be wrong in,
and it is why the refusal branch was written before it was ever needed.

## The other red, which was not this

`quality / infection mutation score` also failed, in 23 seconds. Different diagnosis entirely:

```
[ERROR] Project tests must be in a passing state before running Infection.
Failed asserting that 'Request to "http://127.0.0.1:38221/?mode=drip&run=…" produced no response
```

That is T-07's wall-clock-deadline test against a dripping local origin — a network-timing test,
and a **flake**. The evidence is not the duration: all three `build` cells (8.1, 8.2, 8.3) ran the
same suite on the same commit and passed. Two reds on one PR, one real and one noise, and the only
way to tell was to read both jobs rather than count the failures.

## Sequenced, not folded in

This is a separate PR rather than a commit on the release branch, following the maintainer's own
earlier call in this session when a broken gate blocked other work. The release PR stays a release:
bump, roll, notes. This stays a gate fix, with its own proof and ADR annotation. Merge order is
this one, then rebase #156.
