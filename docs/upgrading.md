# Upgrading `egl-util-php`

Written before the first deprecation exists, so it is a promise about how change is handled
rather than an apology written after the fact. The rules below are decided already
([ADR-0059](adr/0059-freeze-the-api-at-1-0-0-with-internal-symbols-outside-the-frozen-surface.md),
[ADR-0031](adr/0031-run-the-bc-checker-outside-the-dependency-graph-and-gate-breaks-by-bump.md),
[ADR-0060](adr/0060-support-the-latest-release-of-the-current-major-and-measure-the-window-in-releases.md));
this page states them in consumer terms. The internal versions of this policy are
[`docs/workflow/maintenance.md`](workflow/maintenance.md) (the maintainer's decision tree) and
[`SECURITY.md`](../SECURITY.md) (how to report a vulnerability).

## The one-line version

**Within `1.x`, `composer require egl/utils:^1.0` never breaks.** No public signature is removed
or altered in a MINOR or PATCH. If you're on any `1.x` release, upgrading to the latest `1.x` is
always safe to take blind — there is nothing to read first.

## What "the public API" covers

Everything under the `D4np\Utils\` namespace that isn't documented `@internal` at its point of
definition. Two symbols carry that marker today —
`Security\SecretKey::bytes()` and `Security\Hash::selectAlgorithm()` — because PHP has no
package-private visibility; they are public only mechanically, and this project reserves the
right to change or remove them in a MINOR. If you're not calling either by name, this doesn't
apply to you.

## How a deprecation works

1. **A symbol is marked deprecated in a MINOR release.** It keeps working, unchanged — a
   deprecation never breaks anything the moment it lands. The changelog entry for that release
   says so under a `Deprecated` heading, and the replacement (if any) is named there.
2. **It ships deprecated for at least one full published MINOR before removal can happen.** This
   window is counted in *releases*, not months — a symbol deprecated and removed in the same
   release gave you no real chance to see the warning and act on it, whatever the calendar said.
   Concretely: a symbol deprecated in `1.4.0` must still be present, working, and deprecated
   through all of `1.5.0` before it becomes eligible for removal.
3. **Removal only happens in a bump that permits a break — and once this project is past 1.0, that
   is a MAJOR, never a MINOR.** Post-1.0 no MINOR removes anything, regardless of how long a
   symbol has already been deprecated; a deprecation window closing only makes removal *eligible*
   at the next MAJOR, it does not trigger removal at the next MINOR. A migration note ships in the
   MAJOR that finally removes it, and the change is significant enough to carry its own design
   record.

So the deprecation window and the MAJOR boundary are two separate gates, and both must open:
satisfying the window does not by itself license a removal, and post-1.0 no MINOR can ever be the
release that removes something, however long the window has been sitting closed.

## What to do when you see a deprecation notice

Nothing urgent. The symbol still works. Plan the migration for whenever suits you, but do it
before the MAJOR that removes it ships — after that, upgrading past it means changing the call
site first.

## Crossing a future MAJOR

When `2.0.0` (or any later MAJOR) ships, it will carry a migration note in its release for every
breaking change, same as any other breaking change under this policy — a MAJOR bump is not a
blank slate, it is the release where the deprecations that have been waiting finally take effect.

## What's still supported once a new MAJOR exists

Right now there is only one line, `1.x`, and everything below is a commitment made in advance
rather than a practice this project has exercised yet ([ADR-0060](adr/0060-support-the-latest-release-of-the-current-major-and-measure-the-window-in-releases.md)
says so explicitly).

**Supported means the latest release of the current MAJOR.** A fix — bug or security — reaches
you by upgrading to that release; older releases within the same MAJOR are not patched in place.
Because the freeze above makes that upgrade safe by construction, "just upgrade" is a real answer
here, not a cost being pushed onto you.

| Line | Bug fixes | Security fixes |
|---|---|---|
| latest release, current MAJOR | ✅ | ✅ |
| older releases, current MAJOR | ❌ | ❌ — upgrade within the MAJOR; the freeze guarantees no break |
| previous MAJOR, its final release | ❌ | ✅, until the new MAJOR has shipped one full MINOR (`X+1.1.0`) |
| older MAJOR lines | ❌ | ❌ |

That middle row is your actual bridge if you're stuck on an old MAJOR when a new one ships: the
final release of the line you're leaving keeps getting security fixes for one full MINOR of the
new line, which is meant as your window to migrate, not an indefinite safety net.

## Two honest limits

This is a solo-maintained library. The table above says which release a fix lands on, not how
fast it arrives — [`SECURITY.md`](../SECURITY.md) § *What to expect* describes the sequence a
report goes through and deliberately makes no response-time commitment. And the previous-MAJOR
row has never actually been exercised: `1.x` is the only line that has ever existed. It's written
here as a promise for when that day comes, not as a description of something already proven in
practice.
