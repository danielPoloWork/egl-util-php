# ADR-0003: Pin CI actions by commit SHA

- **Status:** Accepted
- **Date:** 2026-08-03
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as enterprise-architect
- **Related:** ROADMAP item 1.10 · [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml) ·
  [`.github/workflows/release.yml`](../../.github/workflows/release.yml) ·
  [`.github/dependabot.yml`](../../.github/dependabot.yml) · `AGENTS.md` §7, §10

## Context

Every GitHub Actions step named with `uses:` executes third-party code inside this
repository's CI, with access to the workflow token and to whatever secrets the job is granted.
The reference a step is pinned to therefore decides *what code runs*, and that reference can
be either immutable or not:

- **A git tag is mutable.** `@v2`, and equally `@v7.0.1`, are ordinary git refs that the
  publishing repository can move at any time — including after a compromise of a maintainer
  account. A moved tag changes what this project's CI executes, with no diff here and no
  signal to anyone reviewing this repository. Verified while writing this ADR:
  `shivammathur/setup-php@v2` currently resolves to `f3e473d1…`, which is also release
  `2.37.2` — the *value is fine today*, and that is precisely the property nobody can rely on
  tomorrow.
- **A commit SHA is immutable.** It names one tree, permanently.

This repository arrived at a **mixed and undecided** state, which is what forced the decision.
Steps generated from the EADOS templates were SHA-pinned with a version comment
(`actions/checkout@3d3c42e5… # v7.0.1`), while the quality jobs authored later through the
project manifest used floating tags (`actions/checkout@v7`) — the same action pinned two
different ways in one file, with no recorded rationale for either.

Two constraints shape the choice:

1. **Governance posture.** `governance.posture: enterprise` (manifest, ADR-0015 upstream):
   a security-relevant decision — and a supply-chain execution boundary is one — carries an
   ADR rather than being an undocumented judgment call (`AGENTS.md` §7, §10).
2. **The obvious objection is already answered.** SHA pins are said to freeze projects on
   stale, unpatched actions. Dependabot is already configured here for the `github-actions`
   ecosystem (weekly, grouped, `ci` prefix) and it updates SHA pins *and* rewrites their
   version comments — it opened PR #4 against this repository on day one. The maintenance
   cost the objection assumes is already automated away.

Prior art was checked before treating this as an open question (per the upstream lessons
ledger, `L-0004`: a re-discovered trade-off is an addendum, not a new decision). EADOS's own
ADR-0009 decides pinning **for the factory**, and its §3 addendum deliberately leaves
profile-supplied actions tag-pinned there. That decision does not reach this repository:
ADR-0003 upstream makes a generated project self-governing, and no ADR here had decided the
question. It is genuinely open, so it is decided here.

## Decision

**Every `uses:` reference in this repository's workflows is pinned to a full 40-character
commit SHA, followed by a comment naming the human-readable version that SHA corresponds to.**
The policy admits no exceptions by publisher: GitHub-owned actions (`actions/*`) and
community actions (`shivammathur/*`, `softprops/*`) are pinned identically, because the
execution privilege they receive is identical.

```yaml
- uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
```

Two rules govern the comment, which exists to make the pin reviewable:

1. **It must be true.** The comment is a claim about the SHA, and a claim nobody resolves lies
   for exactly as long as nobody resolves it. When a pin is introduced or changed by hand, the
   SHA is resolved from the **upstream repository** (`gh api repos/<owner>/<repo>/git/ref/tags/<tag>`,
   dereferencing annotated tags), never copied from another file in this repository that
   happens to look canonical.
2. **Dependabot owns the routine updates.** The `github-actions` ecosystem entry in
   `.github/dependabot.yml` keeps both halves current; a Dependabot PR is the normal way a pin
   moves, and it is reviewed like any other change.

## Alternatives Considered

- **Floating major tags (`@v2`, `@v7`)** — what the quality jobs used. Rejected: the reference
  is mutable, so what CI executes can change without any diff in this repository. It is the
  convenience option, and the convenience it buys — automatic patch uptake — is exactly what
  Dependabot already provides against SHA pins, without handing the publisher a live channel
  into this project's CI.
- **Exact version tags (`@v7.0.1`)** — the intuitive middle ground, and the one worth naming
  explicitly because it *looks* immutable and is not. A version tag is still an ordinary git
  ref that the publishing repository can force-push. It narrows the window compared to `@v2`
  but does not close it, while giving up the property that made SHA pinning worth adopting.
- **Keep the mixed state, decide per action** (e.g. SHA-pin `actions/*`, trust community
  actions on tags) — rejected on two grounds. It inverts the risk: a community action is the
  *more* exposed dependency, not the less. And an unstated per-action rule is not reviewable —
  the next contributor cannot tell a deliberate exception from an oversight, which is the
  state this ADR exists to end.
- **Vendor the actions into this repository** — maximal control, rejected as
  disproportionate: it transfers the full maintenance and security-patching burden of every
  action onto this project to close a gap that an immutable reference plus Dependabot already
  closes.

## Consequences

**What this makes easier**

- What CI executes is now determined entirely by content committed here. A compromised or
  re-pointed upstream tag cannot change it.
- Every change to executed CI code appears as a reviewable diff, in a PR, like any other
  dependency change.
- The policy is uniform, so a reviewer needs no per-action knowledge: any `uses:` without a
  40-character SHA is a policy violation on sight.

**What this costs**

- Adding an action by hand now requires one API call to resolve its SHA, rather than typing a
  tag. The commands are in this ADR and in `docs/workflow/github-setup.md`.
- Dependabot PRs become slightly noisier to read (a SHA diff rather than `v7` → `v8`) — the
  version comment is what keeps them legible, which is why its truthfulness is a rule above
  and not a convention.
- The policy is enforced **mechanically**, by
  [`tools/action_pin_lint.py`](../../tools/action_pin_lint.py) (roadmap item 1.11, landed
  immediately after this ADR). It runs in the `consistency / lint` CI job and splits into two
  checks with deliberately different reach: `pin-shape` is offline and always runs;
  `pin-label-truth` resolves every version comment against its upstream repository and runs
  only with `--verify-upstream`, which CI passes. A run without it says, in its own output,
  that comment truthfulness went unverified — partial verification presented as complete would
  be a dishonest gate.

  *(When this ADR was accepted the policy was enforced by review only, and this section said
  so rather than implying coverage that did not exist. Item 1.11 closed that gap in the
  following PR; the paragraph is updated rather than rewritten to hide the interval.)*

**Risks and limits**

- A SHA pin freezes *behavior*, not *trust*: it guarantees the same code runs, not that the
  code was good. It defends against post-publication tampering, not against a malicious
  release that was already malicious when pinned.
- Dependabot updating a pin is still a supply-chain event. Its PRs are reviewed, not
  auto-merged.

## References

- ROADMAP item 1.10 (this decision) and item 1.11 (the mechanical check that now enforces it,
  [`tools/action_pin_lint.py`](../../tools/action_pin_lint.py))
- [GitHub — Security hardening for GitHub Actions: *use commit SHA*](https://docs.github.com/en/actions/security-for-github-actions/security-guides/security-hardening-for-github-actions#using-third-party-actions)
- [OpenSSF Scorecard — *Pinned-Dependencies* check](https://github.com/ossf/scorecard/blob/main/docs/checks.md#pinned-dependencies)
- EADOS ADR-0009 (upstream, governs the factory) and the upstream lessons `L-0004`
  (check the governing ADR before filing) and `L-0011` (gate a label's truth, resolve toward
  the external source)
