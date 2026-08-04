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

- [2026-08-04 — Making spec §7's T-05 suite a mechanical fact](2026/08/2026-08-04-t05-property-suite.md)
- [2026-08-04 — The shared reflection cache: designing for consumers that do not exist yet](2026/08/2026-08-04-reflection-metadata-cache.md)
- [2026-08-03 — Env::get() and Json: two functions, three probed gotchas](2026/08/2026-08-03-env-json.md)
- [2026-08-03 — File: atomic writes, and a test that was lying](2026/08/2026-08-03-file-atomic-io.md)
- [2026-08-03 — Str::slug() / uuid() / random()](2026/08/2026-08-03-str-slug-uuid-random.md)
- [2026-08-03 — Milestone 2 opens: the exception hierarchy](2026/08/2026-08-03-m2-exception-hierarchy.md)
- [2026-08-03 — EADOS pipeline run and the Composer build system](2026/08/2026-08-03-pipeline-bootstrap-and-build-system.md)
