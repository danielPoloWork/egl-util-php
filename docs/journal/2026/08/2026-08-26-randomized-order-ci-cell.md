# 2026-08-26 — A fourth matrix cell, and a rule for what its red means

Issue **#100**, both criteria. Route `fast / low`; session model Sonnet 5. **ADR-0077**
annotated, spec **r25**.

Small on paper — one more `ci.yml` matrix cell — but the issue asked for two distinct things,
and only one of them was code.

## The code half was free once PHPUnit's own behavior was checked rather than assumed

The first acceptance criterion sounds like it needs an explicit `echo` of the seed into the job
log. It doesn't: PHPUnit 10.5 already prints `Random Seed: <N>` in its own output header the
moment `--order-by=random` is passed, with no companion flag required. Checked locally before
writing a line of workflow YAML (`vendor/bin/phpunit --order-by=random --filter=nonexistentXYZ`
prints the header and exits with "No tests executed!"), which turned "add reproducibility" into
"add the flag and let the tool do what it already does."

The one real decision was *where* the new cell goes: a fourth entry in `build`'s existing matrix,
pinned to `php-8.3` rather than a new axis multiplying across all three toolchains, and branching
only the `Test` step rather than duplicating checkout/setup/install into a separate job. The
matrix already expresses "same steps, varied input" — this is exactly that.

## The half that wasn't code was the point of the issue

"A failure in that cell alone is documented as 'coupling, not flake' in the CI docs so nobody
re-runs it into silence" is not a technical requirement at all — it's a policy aimed at a specific
future failure mode: a red `random-order` cell gets re-run, comes back green because the random
seed differs, and the coupling it found stays in the codebase because nobody wrote down that
"green on retry" is exactly what you'd expect from order-dependent state, not evidence there was
never a bug. `docs/development/local-build.md` gets the rule in plain language, next to the
commands it's about — the closest existing home rather than a new file for one paragraph.

## What running it locally actually found

Before opening this up, I ran the full suite with `--order-by=random` locally (the environment's
own broken PHP extensions notwithstanding — none of the ones missing here, fileinfo/oci8/
pdo_firebird/zip, gate randomized-order behavior). 3,199 tests, seed 1787749415: all green, 9
skipped, 0 failed. **That is the expected first result, not evidence the cell is unnecessary** —
it means this suite, today, has no coupling this particular seed happened to expose. The
mechanism now exists to notice the next one, whenever a future test introduces shared state a
later test depends on without saying so.

## Where this leaves the project

No production code changed. One matrix cell, one documentation section, one ADR, one spec
revision. The real test — same as every CI-behavior PR this repository has shipped recently — is
what CI itself says once the PR is open, not what a broken local PHP install could confirm.
