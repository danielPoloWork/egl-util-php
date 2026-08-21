# Documentation

The durable, versioned documentation for `egl-util-php`. Everything in `docs/` is a
first-class repository artifact and follows the same review process as source code —
conversational context and scratch notes do not live here.

## Layout

| Path | Purpose |
|---|---|
| `docs/specs/` | Functional and technical specifications. Frozen contracts — diverging requires an ADR. |
| `docs/rfc/` | Accepted design proposals — the scope decisions the specs and roadmap implement. |
| `docs/adr/` | Architecture Decision Records — one numbered file per decision. |
| `docs/patterns/` | Living catalogue of design patterns + the canonical taxonomy. |
| `docs/workflow/` | Git, documentation, release, and maintenance conventions, plus `packaging.md` (registry/publish). |
| `docs/development/` | Procedural how-to guides for working on the code locally. |
| `docs/journal/` | Dated session checkpoints. |
| `docs/bugs/` | In-repo bug ledger — one record per known defect, with the triage trail. |
| `docs/security/` | Threat model (STRIDE, per trust boundary) — the analysis beside the root `SECURITY.md` policy. |
| `docs/compliance/` | Control register — controls → evidence, under the enterprise posture (ADR-0015). |
| `docs/releases/` | **Per-version release notes** — the consumer-facing narrative, published verbatim as the GitHub Release body. |
| `docs/changelog/` | **Per-version changelog archive** — the exhaustive Keep-a-Changelog record, rolled out of root `CHANGELOG.md` at release time. Not a copy of the above; see either README. |
| `docs/benchmarks/` | Reproducible performance methodology + results, backing every performance claim. |


## Reading order for newcomers

1. [`/README.md`](../README.md) — what this project is.
2. [`/AGENTS.md`](../AGENTS.md) — how agents (and humans) work in this repo.
3. [`specs/01_spec_utils.md`](specs/01_spec_utils.md) — what we are building.
4. [`development/local-build.md`](development/local-build.md) — how to build and test it.
5. [`adr/`](adr/) — why we built it that way.
6. [`patterns/`](patterns/) — which design patterns we exercise and why.
7. [`/ROADMAP.md`](../ROADMAP.md) — what is done and what is next.

## Conventions

- **English only.** All documentation and identifiers are in English.
- **Same-PR updates.** When code changes its public surface or a non-trivial design choice,
  the docs change in the *same* pull request — never as a follow-up.
- **No silent drift.** If the implementation diverges from the spec, update the spec or open
  an ADR that supersedes the relevant section.
