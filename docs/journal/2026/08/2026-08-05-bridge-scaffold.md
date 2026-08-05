# 2026-08-05 — A rule that looked right and did nothing

Roadmap item **8.1**, opening Milestone 8. Route `standard / medium` → Opus 5, the session model.
No mismatch.

First item worked in an **isolated `git worktree`**, per the operational note another session
recorded: this checkout is shared with parallel sessions, and item 7.4's leak began with untracked
material appearing in a tree I then staged blind. A worktree cut from `origin/master` cannot
inherit that.

## The deptrac rule fired on nothing

The item's third deliverable is "a core → `D4np\Utils\Bridge\` import is a build failure". I added
a `Bridge` layer collected by class name, granted it to nobody, and declared `Bridge: ~`. It read
correctly against the `Support: ~` precedent it was modelled on.

Then I planted the import:

```php
use D4np\Utils\Bridge\Psr7\Psr7Bridge;
```

**0 violations.** The rule did nothing.

deptrac resolves *type dependencies*, not `use` statements — an import nothing references is not a
dependency, which is defensible and is not what I had assumed. Planting a real reference instead:

```php
public function probePlant(\D4np\Utils\Bridge\Psr7\Psr7Bridge $bridge): void
```

```
DependsOnDisallowedLayer   D4np\Utils\Http\Response must not depend on D4np\Utils\Bridge\Psr7\Psr7Bridge
Violations  1
```

The rule is correct. My first probe was not, and had I stopped at it I would have concluded the
opposite — either "the rule is broken, rewrite it" or, worse, "unused imports are fine, ship it".
That is the third time this session a verification has needed verifying, after item 7.1's `| tail`
exit codes and 7.3's `git diff master HEAD` on a checkout where `HEAD` *was* master.

## `^0.7` against a release that does not exist

Spec 02 §2 says the bridge requires "the released core line (e.g. `^0.7`)", never `@dev`. The core
has **no release**: `VERSION` is `0.0.0` and there is no tag. So a standalone install of this
package cannot resolve, today, by construction — I checked rather than assuming:

```
composer update --dry-run
  → Root composer.json requires egl/utils ^0.7, it could not be found in any version
```

I kept `^0.7` anyway, because it is *true*: the bridge does require a released core, and the package
genuinely is not installable until the core ships `v0.7.0` — which is also when item 8.3 first
publishes it. A weaker constraint would have made the manifest resolvable and wrong. The README says
so plainly rather than leaving someone to discover it at `composer require`.

PR mode is unaffected and was verified end to end: inject a path repository, relax the constraint in
the workspace, resolve. 36 packages installed, `egl/utils` resolved with source type **`path`** at
`dev-worktree-bridge-scaffold-8.1` — the working tree, which is the entire property ADR-0033 chose
the monorepo for. The CI job asserts that source type explicitly, because a quiet fallback to a
published core would leave the same-PR guarantee a fiction with every test still green.

## The invariant that breaks something nobody here would see

Running PR mode locally **mutated the committed manifest** — composer wrote `egl/utils: "@dev"` and
a `repositories` block into it. Exactly what spec §6 forbids committing, arrived at by simply
following the spec's own CI recipe.

That is the shape of the risk: the injection is correct in the workspace and catastrophic in the
repository. A committed path repository pointing at `../../` resolves perfectly inside the monorepo
and nowhere else, so every standalone `composer require egl/utils-psr7-bridge` would fail while
nothing in this repository noticed.

So the boundary assertions live in the **core's** suite, not the package's: the package's tests run
only when its job runs, and this invariant needs checking on every PR from today. Planting the two
mutations fails two of them by name.

## What 8.1 deliberately does not ship

No converters, and no contract suite — those are 8.2, and a stub `Psr7Bridge` throwing "not
implemented" would be a worse artifact than an empty PSR-4 root. The CI job self-enables in two
stages accordingly: absent package → notice; scaffold present but no `*Test.php` → notice; a test
file appears → it runs. All three branches exercised locally.

`composer.lock` is not committed for the package. It is a library, and PR mode rewrites the manifest
in the workspace anyway; the root `.gitignore`'s `/vendor/` is root-anchored and would not have
covered `packages/*/vendor/`, so those rules are added explicitly.

## Bar

1306 tests / 2955 assertions green (up from 1299). PHPStan max clean — it caught every
`json_decode()` result being `mixed` in the new test, which is fair and now narrowed once in a
helper rather than cast at seven call sites. deptrac 0/0, PHP-CS-Fixer clean, consistency lint OK,
34 action pins verified upstream.

## Next

**8.2** — the converters and the T-B contract suite: BFR-01…BFR-22 against both `nyholm/psr7` and
`guzzlehttp/psr7`, each refusal probe-verified by planting the defect it claims to catch. The two
clauses worth the most care are already written down: the multi-`Set-Cookie` refusal, and never
touching a failed upload's stream.
