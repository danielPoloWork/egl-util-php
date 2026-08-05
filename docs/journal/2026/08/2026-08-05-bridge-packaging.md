# 2026-08-05 — The question under the question

Roadmap item **7.4**, the last one. Route `frontier-reasoning / extra`, protected floor — and for
the first time on a frontier-routed item, **run at the routed tier**: the maintainer switched the
session to Fable 5 rather than accepting a sixth mismatch. Worth noticing what that implies about
the five acceptances before it. The precedent was never "the floor doesn't matter"; it was a
per-item cost call, and on the item whose entire deliverable is a decision, the floor got honoured.

## The reframing that dissolved the roadmap's wording

A-8 asked: subtree vs second repository. But Packagist resolves a package from a repository whose
`composer.json` sits at the **root** — a directory inside this repo cannot be a Packagist package,
which is why split tooling exists. So a second repository exists under *every* option, and the
question that was actually open is whether it is **authored** or **generated**.

Once asked that way, the options stop being symmetrical. An authored second repo buys independence
the bridge does not want — its whole purpose is to track the core closely — and pays for it with
cross-repository failure discovery: a core change that breaks the conversion contract merges here
green and fails *there*, later. A generated split repo keeps the failure in the PR that causes it.

## The correction that made the analysis better and the answer the same

I had counted "a second copy of the governance we built in 7.1–7.3" as a cost of the authored
option. The maintainer struck it: **EADOS is an external code-generation tool, not repository
governance** — not committed, not runtime infrastructure, and duplicating a generation tool is not
an architectural cost of a second repository. What remains is the honest four-axis comparison:
authored-repo count, same-PR vs cross-repo integration, one review flow vs two, generated
publication vs duplicated maintenance.

The topology survived its best argument being deleted — the maintainer chose the monorepo anyway,
on the remaining axes. But the episode is the recordable part: my analysis recommended the right
option partly for a wrong reason, and a decision that wins with a phantom cost on the ledger is a
decision that can be relitigated the day someone notices the phantom. Now it can't be.

## Independent versioning is a design act, not a default

The maintainer's instruction was precise: the bridge must not inherit the core's tags
*accidentally*. That word is doing work. In a monorepo, shared versioning is what happens when
nobody decides — every `vX.Y.Z` tag would publish a "new" bridge whose version communicates core
events, not bridge API changes.

So: `utils-psr7-bridge-vX.Y.Z` in the monorepo, translated to `vX.Y.Z` on the split repo. Signed at
the source; the pipeline verifies the signature (ADR-0032's GitHub-side check, reused — still no key
material on any runner) before splitting, so the split repo's unsigned tags are derived artifacts of
a verified assertion rather than assertions themselves.

One glob to respect: `release.yml` triggers on `v*.*.*`, and GitHub's filter patterns match the
whole ref name, so `utils-psr7-bridge-v0.1.0` — starting with `u` — should not match. *Should*:
that is documented behavior, not probed behavior, because probing it means pushing a tag. It is the
one assumption in this decision I could not discharge from a working tree, so item 8.3 verifies it
with a real tag before the first publication. Named, not waved at.

## The flip side of the same-PR guarantee

The monorepo's strongest property — contract tests run against the working tree via an injected
path repository — has a shadow: the bridge's committed `composer.json` claims compatibility with
**released** core versions, and working-tree evidence cannot support that claim. Twenty green PRs
against HEAD say nothing about `^0.7` as a consumer resolves it.

So the publication pipeline runs the suite twice: PR mode (path repo, working tree) on every PR,
and **release mode** (clean install, released core from Packagist) before any bridge tag ships.
The injected repository lives only in the CI workspace — a committed `repositories` entry pointing
outside the package would break every standalone install of the split package, which is the kind of
defect that is invisible in the monorepo and fatal outside it.

## Writing the contract found the two real traps

Turning imported ADR-002's one line — "conversion fidelity (headers, uploaded files, immutability
boundaries)" — into twenty-two testable clauses forced the corner cases out:

- **Multiple `Set-Cookie` headers cannot be comma-joined.** PSR-7's own `getHeaderLine()` reduction
  is correct for every header except this one: RFC 6265 cookie strings contain commas
  (`Expires=Wed, 21 Oct…`), so the join that is right everywhere else silently corrupts cookies.
  The core's header projection is single-valued, so a multi-`Set-Cookie` response is **refused** —
  ADR-0025's refuse-don't-coerce, crossing the bridge intact. A naive implementation passes every
  other test while mangling this; that is exactly the clause contract tests exist for.
- **Uploaded files cross a representation boundary.** `$_FILES`-shaped arrays on one side,
  `UploadedFileInterface` streams on the other. The contract pins error codes verbatim, byte
  identity through the round-trip, and — the easy one to get wrong — **no stream access when
  `error !== UPLOAD_ERR_OK`**, because PSR-7 permits `getStream()` to throw on a failed upload and
  there is nothing valid to read anyway.

Also recorded as a deviation rather than smoothed over: imported ADR-002 wrote `Request::toPsr7()`,
and PHP has no partial classes — core methods naming PSR types would put those interfaces in the
core's requirements, which NFR-08 forbids. The bridge owns the converters; the factories arrive at
construction, injected, never discovered.

## Housekeeping that is honest rather than tidy

The Spec Coverage Map's §7 row **reopens** (✅ → 🚧, gaining 8.2). The frozen spec names *"bridge
conversion-fidelity contract tests in egl/utils-psr7-bridge CI"*, and with Milestone 8 now real,
a closed row would hide named, unfinished spec work. Closing it at 6.3 was right for what existed
then; keeping it closed now would not be. §8 gains 8.3 (the publication pipeline is release
engineering) and stays open. §9 closes — 2.1, 5.3 and 7.4 were the decision-log items, and this was
the last.

## The roadmap is complete

With 7.4 merged, every planned item from the 2026-08-03 negotiation is done: 40 items, 7
milestones, 33 ADRs, two frozen specs. What remains is not on the original map: Milestone 8 (the
bridge build, 8.1–8.3), the post-M7 **1.0.0 API-freeze review** the versioning policy reserved, and
the first actual release — `VERSION` is still `0.0.0`, no tag exists, and the release machinery
from 7.2/7.3 waits unexercised for the maintainer to cut `v0.x`. The gates are built; the first
release is the human's move, as designed.

## Bar

Docs-only change. 1299 tests / 2916 assertions green, PHPStan max clean, deptrac 0/0, consistency
lint OK, 32 action pins verified upstream.

## Next

**8.1**, when the maintainer calls it — or the v0.7.0 release PR, or the 1.0.0 freeze review.
The order of those three is a maintainer decision, not a roadmap fact.
