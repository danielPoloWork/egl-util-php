# 2026-08-27 — Running the gate that had never run, before asking for the keys

Issue **#120**, everything in it that is not the owner's. Route `standard / medium`; session model
Opus 5 — matched.

The issue has two acceptance criteria. The second — `bridge-release.yml`'s stale `egl/utils: ^0.7`
header — landed in #170. The first is the split repository, its token, and Packagist registration:
owner one-time steps, and no agent can do them. So the question this session actually had to answer
was **what is left that is not blocked**, and the honest answer turned out to be more than
"nothing".

## The blocker was not the one the issue names

Before touching documentation, the state was measured rather than assumed:

```
gh variable list        ->  (empty)
gh secret list          ->  (empty)
git tag -l "utils-*"    ->  (empty)
repo.packagist.org/p2/egl/utils-psr7-bridge.json  ->  404
repo.packagist.org/p2/egl/utils.json              ->  200
```

All expected. Then the gate itself was run against the tag the release would use:

```
python tools/bridge_release_gate.py --tag utils-psr7-bridge-v0.1.0
  -> FAIL: packages/utils-psr7-bridge/CHANGELOG.md has no `## [0.1.0]` heading
```

**Neither bridge changelog had ever carried a released-version heading.** Both sat at
`## [Unreleased]`. That heading is the only place a bridge's version is written down — a Composer
library has no version constant — so the tag would have been refused at the *first* gate, long
before the missing variable and secret the issue is about. The owner could have created the split
repository, minted the token and registered on Packagist, and the run would still have failed on a
missing line of Markdown.

**Worth generalising: when an issue names its blocker, check whether the blocker is reachable.**
Everything upstream of it has to pass first, and here nothing had ever tried.

## Release mode, exercised without a tag

ADR-0035 §2 calls release mode the gate that cannot be faked: the package installed resolving
`egl/utils` from Packagist exactly as a consumer would, rather than from the working tree. It had
never executed — that unexercised state is precisely why RFC-0003 deferred the PSR-18 bridge, and
why #120 has looked risky.

It does not need a tag. Reproducing what the workflow step does — copy the package out of the
monorepo, `rm -rf vendor composer.lock`, `composer update --prefer-dist`, run its suite:

| Package | Result |
|---|---|
| `utils-psr7-bridge` | **65 tests, 202 assertions** — green |
| `utils-psr18-bridge` | **28 tests, 72 assertions** — green |

Both resolved `egl/utils v1.0.0` from Packagist. **The unfakeable gate passes**, which moves the
first publication's remaining risk off the packages and onto the split-and-push step — the only
part that genuinely needs the owner's credentials.

A `workflow_dispatch` run would have been the sanctioned way to check this, but it requires an
existing tag, so it cannot pre-validate an untagged release. The gate script plus a hand-run
release mode covers that gap, and `release.md` now says so.

## An incidental finding about the core

The install resolved **`v1.0.0`**, and Packagist's index confirms that is the only version it
serves. The core's `v1.1.0` tag exists in this repository but its publication never completed —
`verify-tag` failed on the unsigned tag, so no Release was drafted and Packagist was never
notified (issues #115, #105). So **every consumer of `egl/utils` today is on `v1.0.0`**, whatever
`ROADMAP.md` reads like. It satisfies both bridges' `^1.0`, so it changes nothing about this work;
it is recorded because a reader comparing the bridge changelog's evidence against the core's
version history would otherwise find a discrepancy and have to re-derive this.

## The runbook would have sent the owner to the wrong switch

`docs/workflow/release.md`'s prerequisites said:

> set the repository variable `BRIDGE_SPLIT_REPO` to its `owner/name`

Since #93 / ADR-0075 the workflow derives that name **per package**:

```bash
echo "repo_var=BRIDGE_SPLIT_REPO_$(echo "$PACKAGE" | tr "a-z-" "A-Z_")"
```

— so the variable it reads for the PSR-7 bridge is `BRIDGE_SPLIT_REPO_UTILS_PSR7_BRIDGE`, and
`BRIDGE_SPLIT_REPO` is read by nothing. Spec 03 §7 and ADR-0075 both have it right; only the
runbook — the one document the owner would actually follow — was stale. **The generalisation in
#170 updated the machinery and the design records, and missed the operating instructions.**

The failure mode is nastier than a plain wrong name. The prerequisite check sits *after* the
signing, changelog and release-mode gates, deliberately, so its message can distinguish "not
configured" from "the release is bad". Following the stale runbook, the owner would have done all
three one-time steps correctly, watched every real gate pass, and then been told the split
repository was not configured — with the variable sitting right there in settings, spelled the way
the documentation asked for.

## Version: 0.1.0, and the minor is the claim

`0.1.0` rather than `1.0.0`, for both bridges, maintainer's decision. The surface is specified and
contract-tested against two PSR-17 vendors, which is a `1.0.0`-shaped argument, and the core froze
its own API at `1.0.0` under ADR-0059. But the pipeline shipping it still has **zero runs**, and a
`1.0.0` would promise stability for machinery with no live evidence behind it. A `0.x` lets the
first publication be corrected without spending a major. `release.md` had used
`utils-psr7-bridge-v0.1.0` as its worked example since the pipeline was written, so this also
stops being a discrepancy.

Both bridges are cut in one round so the one-time steps are done once for two packages. That is a
convenience of sequencing, not a dependency — the PSR-18 bridge does not require the PSR-7 one
(ADR-0075), and each versions independently from here.

## What #120 still needs, and it is not writing

The issue stays open. Its remaining criterion is entirely the owner's:

1. create `danielPoloWork/egl-utils-psr7-bridge` and `danielPoloWork/egl-utils-psr18-bridge`, empty
   and read-only;
2. set `BRIDGE_SPLIT_REPO_UTILS_PSR7_BRIDGE` and `BRIDGE_SPLIT_REPO_UTILS_PSR18_BRIDGE`;
3. set `BRIDGE_SPLIT_TOKEN` once, with write access to both;
4. register both packages on Packagist against their split repositories;
5. cut and push the signed tags — which needs the signing key issue #115 is still waiting on, the
   same key that left `v1.1.0` unpublished.

Step 5 is worth noticing: **#120's first bridge tag runs through the same signing gate that has
already failed three times** (`v0.11.0`, `v1.0.0`, `v1.1.0`). `.githooks/pre-push` now refuses an
unsigned tag before it reaches the remote, so the bridge will not repeat that history quietly — but
it will not succeed without the key either. #115 is not merely adjacent to #120; it is upstream of
its last step.
