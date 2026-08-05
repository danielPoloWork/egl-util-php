# 2026-08-05 — When the standing pattern is the wrong one

Roadmap item **8.3**, closing Milestone 8. Route `standard / high` → Opus 5, the session model. No
mismatch. Isolated worktree.

Two details of my own spec could not be built as written, for opposite reasons: one was
unverifiable, one was verifiable and wrong.

## The plan that would have cost a side effect for an expiring fact

Spec 02 r1 said 8.3 would *"verify with a real tag"* that the core's `tags: ["v*.*.*"]` filter does
not match `utils-psr7-bridge-v*`. I wrote that at 7.4 and it sounded like diligence.

It has two faults. Testing a glob means pushing a throwaway tag to a public repository and deleting
it — a side effect on the one artifact this project treats as uncorrectable, to establish a fact. And
the fact expires: a verified glob is verified only until GitHub changes its matcher, and nobody is
watching for that.

So both workflows guard their own ref shape instead, first, before anything costs an API call:

```
release.yml            ^v[0-9]+\.[0-9]+\.[0-9]+$
bridge_release_gate.py ^utils-psr7-bridge-v\d+\.\d+\.\d+$
```

Whatever the glob does, a tag reaching the wrong workflow is refused by name. The matcher stops
being a dependency. Verified locally in both directions — each gate refuses the other's tag, and
`v0.7` and `v0.7.0-rc1` are refused too.

That is a case where the *stronger* answer was also the cheaper one, and I only found it by asking
what pushing the tag would actually buy.

## The standing pattern, and why it does not apply here

This project has a reflex, from lesson L-0010 and applied twice recently — at ADR-0031's BC gate and
item 8.1's bridge job: **when a gate cannot run yet, skip it on a declared condition and self-enable
later.** It is a good pattern and I reached for it again.

It is wrong here, and the reasoning is worth writing down because the precedent points the other way.

Release mode installs the package resolving `egl/utils` from Packagist, exactly as a consumer would.
It is not a check that happens to be unavailable — it is the **only** evidence for the package's
central published claim, that its core constraint resolves and works. Skipping a check defers a
discovery. Skipping *this* publishes a package whose claim nobody has tested, to a repository that
cannot be corrected in place.

So it is a hard requirement, and the consequence is blunt: **no bridge release is possible until the
core has one.** `egl/utils: ^0.7` resolves to nothing today. The pipeline cannot succeed, by design
rather than by oversight, and its failure message says *cut the core release first*.

The general shape: a skip is right for a gate that *checks* something, and wrong for a gate that
*establishes* something. I do not think I would have separated those two without having applied the
pattern wrongly first and noticing what it would have published.

## What anchors a bridge tag

`release_gate.py` anchors a core tag to the `VERSION` constant. The bridge has none — a Composer
library does not carry its own version, by design — so there was nothing analogous to check against.

The changelog is the answer: `## [X.Y.Z]` in the package's own `CHANGELOG.md` is the one place its
version is written down, which makes it the one thing a tag can be anchored to. The gate also
re-checks, on the exact tagged tree, the two manifest invariants `BridgePackageBoundaryTest` checks
on pull requests. Duplication on purpose: that tree is the artifact that gets published, and after
8.2 I have direct evidence those invariants catch real mutations — they caught mine.

## The honest state of it

**Most of this has never run and cannot until a core release exists.** The signature check, the
release-mode install, the subtree split, the cross-repository push: all unexercised. That is the
third item in a row — 7.2's BC gate, 7.3's tag verification, now this — shipping machinery whose
first real run will be its first real use.

What can be tested away from a tag has been: the gate's five cases with verified exit codes, both
ref-shape guards, the YAML, 36 action pins across four workflows. The rest is named in ADR-0035
rather than implied to be fine. A `workflow_dispatch` dry run exists so the gates can be exercised
against a tag without cutting a version — small mitigation, worth its lines given how much waits.

## Bar

1312 tests / 2973 assertions green; the bridge package's 65 / 202 across both PSR-17
implementations. PHPStan max clean, deptrac 0/0, PHP-CS-Fixer clean, consistency lint OK, 36 action
pins verified upstream.

## Next

**Milestone 8 is complete, and so is every planned item.** What remains is not on any roadmap: the
first core release (`VERSION` is still `0.0.0` and no tag exists), the post-M7 **1.0.0 API-freeze
review**, and then the bridge's own first publication — which the core release unblocks. All three
are the maintainer's to sequence.
