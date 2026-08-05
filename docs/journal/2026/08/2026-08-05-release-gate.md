# 2026-08-05 — The same defect, in the second place it happened

Roadmap item **7.3**. Route `standard / high` → Opus 5, the session model. No mismatch.

## What `release.yml` actually did

It drafted a GitHub Release from whatever was tagged, having verified that `composer install`
succeeded. No signature. No tests. No check that the tag agreed with the tree it pointed at.

The asymmetry is what makes this worth an item's attention: a tag is the one artifact here that
**cannot be corrected in place** once it is published and consumed. Packagist serves it,
`composer.lock` files pin it, and the remedy for a bad one is a new version and an erratum. A few
minutes of verification against a permanent mistake.

## And a defect I have seen before in this repository

```yaml
php-version: ${{ matrix.toolchain == 'php-8.1' && '8.1' || matrix.toolchain == 'php-8.2' && '8.2' || '8.3' }}
```

In a job with **no matrix**. So `matrix.toolchain` is empty and the ternary falls through to `'8.3'`.

That is precisely the rendering artifact roadmap item **1.9** identified and fixed — in `ci.yml`.
Nobody went back for `release.yml`, and it survived because it *works*: it picks 8.3, which is a
legal answer, so nothing was ever red.

The fix is not to hardcode `'8.3'`. The expression was written for a matrix, and here a matrix is
load-bearing for a reason the original job did not have: **a tag can be pushed at a commit CI never
ran**. Hardcoding would have made the expression truthful and left the release untested on PHP 8.1 —
a runtime this library promises. So the matrix is restored, and the tagged tree is tested on
8.1/8.2/8.3.

Worth noting how it was found: not by a gate, but by reading the file I was about to change. Item
1.9's lesson was recorded as a fix, not as a class of defect to sweep for.

## The hole no lint can reach

`consistency_lint.py`'s `version-lockstep` already keeps the `VERSION` constant, the README badge and
the latest release file in agreement. I nearly concluded the tag check was redundant.

It is not, and the reason is structural: **the lint runs on a working copy and has no tag.**

```
git tag -a v0.2.0     # on a tree whose constant still says 0.1.0
```

That ships a release which installs as 0.2.0 and reports itself as 0.1.0. Nothing *inside* the tree
disagrees with itself, so every existing check passes, Packagist serves it, and `composer show`
contradicts the package's own constant. Only something that sees the tag can see it — which is what
`tools/release_gate.py` is.

Six cases, exit codes verified without a pipe this time: a complete fixture passes; a tag/constant
mismatch, missing release notes, an unindexed changelog split, a malformed tag and a missing version
file all fail. It also correctly reports four problems against this repository's current pre-release
state.

## Asking GitHub about the signature instead of holding keys

The obvious implementation is: fetch the maintainer's public keys, import them into a runner keyring,
`git verify-tag`. It verifies locally, which sounds stronger.

It is not stronger here. It puts key material in CI and creates a keyring that goes silently stale on
a rotation, in exchange for independence from a trust root the workflow *already* depends on for the
checkout, the token and the release API. So the gate asks GitHub whether the tag object verifies, and
requires `verified == true`.

Signing is a hard failure, not a warning. A release tag asserts who cut a release; an unsigned one
asserts nothing, and a warning nobody must act on is the pattern this project has twice had to go
back and close.

## Packagist pulls; CI does not push

Packagist mirrors the repository through its own GitHub integration, so a tag push updates the
package with **no Packagist token in this repository**. An explicit API call would add nothing the
integration already does and would cross AGENTS.md §11 — the agent drafts, the maintainer publishes.
So the workflow prints the package URL to confirm after publishing, and the integration setup is
documented as a maintainer task.

## The honest limitation

**None of the tag-time machinery has run for real.** No tag exists, so the signature check, the API
shape it reads, and the draft step are all unexercised. Same position as item 7.2's BC gate, and for
the same reason — the first release exercises both at once, which is the worst place to find a wiring
bug.

What *can* be tested away from a tag has been: the release gate's six cases, the YAML parse, the 32
action pins. What cannot, is named in the ADR rather than implied to be fine.

Two prerequisites are the maintainer's and **the first release fails without them**: a signing key
registered on the GitHub account, and the Packagist integration. Both now documented next to the
release steps rather than only in an ADR.

## Bar

1299 tests / 2916 assertions green. PHPStan max clean, deptrac 0/0, consistency lint OK, 32 action
pins verified upstream.

## Next

**7.4** — the `egl/utils-psr7-bridge` packaging decision: subtree or a second repository. The last
roadmap item, and a decision rather than an implementation.
