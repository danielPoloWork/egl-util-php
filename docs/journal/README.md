# Session Journal

Dated end-of-session checkpoints — what got done, where the project stands, and how the
next session resumes. One file per session that changed the project's state, at
`docs/journal/<YYYY>/<MM>/<YYYY-MM-DD>-<short-slug>.md`. The journal is the dated trail;
`ROADMAP.md` is the forward plan — checkpoints never live inline in the roadmap.

At the close of a state-changing session, the agent:

1. Creates the dated file under `docs/journal/<YYYY>/<MM>/`.
2. Adds a link row to this index (newest first, grouped by year/month).
3. Updates the *Latest checkpoint* pointer in `ROADMAP.md`.

## Index

### 2026

_(newest first)_

#### August

- [2026-08-04 — The client picks the type, not the application](2026/08/2026-08-04-http-wrappers.md)
- [2026-08-04 — A range, not a ceiling: measuring the wrong thing would have proved nothing](2026/08/2026-08-04-hash-matrix-nfr05.md)
- [2026-08-04 — A probe that passed, and the claim it disproved](2026/08/2026-08-04-xss-corpus.md)
- [2026-08-04 — The constant that reads like "strongest" and means bcrypt](2026/08/2026-08-04-hash.md)
- [2026-08-04 — Two sanitizers, and a wrong answer that only shows up on one driver](2026/08/2026-08-04-sanitizer.md)
- [2026-08-04 — The benchmark was measuring the wrong thing, and I wrote it](2026/08/2026-08-04-nfr03-correction.md)
- [2026-08-04 — Four grammars, and the assumption an escaper cannot check](2026/08/2026-08-04-escaper-contexts.md)
- [2026-08-04 — The same shape as NFR-01, found in a different class](2026/08/2026-08-04-nfr03-querybuilder-bench.md)
- [2026-08-04 — Measuring what the old tests would have missed](2026/08/2026-08-04-t02-injection-suite.md)
- [2026-08-04 — Transactions: three PDO probes that each decided a design](2026/08/2026-08-04-transaction-savepoints.md)
- [2026-08-04 — The spec's own regex was the vulnerability](2026/08/2026-08-04-querybuilder-allowlist.md)
- [2026-08-04 — Milestone 4 opens: the security default that fails by returning false](2026/08/2026-08-04-pinned-pdo-defaults.md)
- [2026-08-04 — Measuring what was reachable before deciding what to build](2026/08/2026-08-04-nfr01-compiled-hydration.md)
- [2026-08-04 — Enforcing a rule two ADRs had already been obeying by hand](2026/08/2026-08-04-deptrac-layering-gate.md)
- [2026-08-04 — First real benchmarks, and a genuine number the maintainer had to weigh in on](2026/08/2026-08-04-phpbench-hydration-memory.md)
- [2026-08-04 — Checking what T-01 actually still needed, before writing anything](2026/08/2026-08-04-t01-enum-hydration.md)
- [2026-08-04 — Collection<T>, and changing a decision a previous ADR had already named](2026/08/2026-08-04-collection.md)
- [2026-08-04 — Withers: meeting a "per-version" requirement by not depending on the version](2026/08/2026-08-04-withers-trait.md)
- [2026-08-04 — Milestone 3 opens: DTO hydration, and PHP disagreeing with the requirement](2026/08/2026-08-04-dto-hydration.md)
- [2026-08-04 — Closing the coverage floor, and measuring it for the first time](2026/08/2026-08-04-coverage-floor-gate.md)
- [2026-08-04 — Making spec §7's T-05 suite a mechanical fact](2026/08/2026-08-04-t05-property-suite.md)
- [2026-08-04 — The shared reflection cache: designing for consumers that do not exist yet](2026/08/2026-08-04-reflection-metadata-cache.md)
- [2026-08-03 — Env::get() and Json: two functions, three probed gotchas](2026/08/2026-08-03-env-json.md)
- [2026-08-03 — File: atomic writes, and a test that was lying](2026/08/2026-08-03-file-atomic-io.md)
- [2026-08-03 — Str::slug() / uuid() / random()](2026/08/2026-08-03-str-slug-uuid-random.md)
- [2026-08-03 — Milestone 2 opens: the exception hierarchy](2026/08/2026-08-03-m2-exception-hierarchy.md)
- [2026-08-03 — EADOS pipeline run and the Composer build system](2026/08/2026-08-03-pipeline-bootstrap-and-build-system.md)
