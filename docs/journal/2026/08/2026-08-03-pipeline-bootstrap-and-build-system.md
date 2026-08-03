# 2026-08-03 — EADOS pipeline run and the Composer build system

## What happened

The repository went from an empty shell (README stub + LICENSE) to a governed project with a
working build system, in one session, through the full EADOS delivery pipeline.

| Phase | Outcome | Landed as |
|---|---|---|
| `init` | Framed the project: library, domain `software`, posture **enterprise**; manifest skeleton | — |
| `design` | Imported the pre-existing reviewed spec (`.specs/d4np-php.md` v2.0, 25 items + 2 ADRs) as **RFC-0001**, reviewed by the `reviewer` + `enterprise-architect` roles (18 findings, 0 blockers, all resolved), approved | PR #1 |
| `plan` | Negotiated the **M1–M7** roadmap with sizes + advisory routes; GitHub milestones `v0.1.0`–`v0.7.0` | PR #2 |
| `scaffold` | Rendered the governed repository (49 files: contracts, docs system, CI, source tree, consistency lint) | PR #3 |
| — | Dependabot bumped the GitHub Actions group | PR #4 |
| item 1.1 | Composer build system + component-group skeleton | this PR |

## Decisions taken (maintainer, precedence layer 1)

- **Namespace `D4np\Utils\` inside package `egl/utils`** — a deliberate mixed-vendor choice.
  The tech-lead's dissent (consumer-visible, MAJOR-break to reverse, discoverability cost) is
  recorded in RFC-0001 *Alternatives #5*; the decision stands.
- **Release mapping** — one minor per milestone (M1 → `v0.1.0` … M7 → `v0.7.0`); the 1.0.0
  decision is a dedicated post-M7 API-freeze review, not an automatic bump.
- **Item 1.1 ships alone, red CI accepted.** Landing `composer.json` flips the bootstrap guard
  and starts the toolchain jobs for real; without items 1.2 (PHPUnit) and 1.3 (cs-fixer /
  PHPStan configs) they fail. The agent proposed bundling M1 into one green PR and dissented
  on the same grounds as lesson L-0010 (a repo's first CI signal should not teach that red is
  normal); the maintainer chose the minimal scope. Recorded, not relitigated.

## Where the project stands

- **Green:** `consistency / lint`, `bootstrap / is the build system in place?`
- **Red from this PR until 1.2 + 1.3 land:** `build` (matrix ×3), `quality`, `hygiene`,
  `lowest-deps` — expected, by the decision above.
- Verified locally: `composer validate --strict` passes; dependency resolution works
  (`psr/container` 2.0.2, `psr/log` 3.0.2); the PSR-4 prefix `D4np\Utils\` resolves to
  `src/main/php/d4np/utils/` and a probe class in `Support/` autoloads through it.

## How the next session resumes

1. **Item 1.2** — PHPUnit + `phpunit.xml.dist` + one smoke test, and **1.3** — `.php-cs-fixer.dist.php`
   + `phpstan.neon`. Together they turn the whole matrix green; take them first.
2. **Item 1.5 + 1.8 together** — the version constant and the doubled `version_file` path in
   `tools/consistency_lint.py` `CONFIG`. Fixing 1.5 without 1.8 leaves `version-lockstep`
   silently disarmed (it would keep falling back to the README badge). Prove the gate can fail.
3. **Bookkeeping** — items 1.4 and 1.7 were delivered by PR #3 (the CI matrix, the explicit
   toolchain→version map, the `--prefer-lowest` job) but their checkboxes are unflipped; the
   maintainer's call whether "stood up but never executed" counts as done.
4. **One-time admin** — [`docs/workflow/github-setup.md`](../../workflow/github-setup.md):
   branch protection on `master`, squash-only, label import from `.github/labels.yml`.
