# 2026-08-27 — Closing an issue is a change to the repository, not to the tracker

Issue **#120**, closed *as not planned* on the maintainer's decision, and issue **#115**'s signing
criterion decided the same way. Route `standard / medium`; session model Opus 5 — matched.

The decision is short: publishing a bridge means pushing to a **generated split repository**, which
needs a credential in this repository's secrets, because `GITHUB_TOKEN` structurally cannot write
outside its own repository. That is the consequence of ADR-0033's split-publication design, not an
implementation detail anyone could route around. The maintainer does not want to hold such a
credential. So the bridges stay monorepo-only.

Same for the release signing key: no key, so `verify-tag` fails on every tag and releases take the
`EGL_UNSIGNED_TAG_REASON` path `release.md` step 0 already documents.

## What this entry is actually about

Marking #120 closed took one API call. Making the **repository** true took eleven files, and that
gap is the lesson.

Before this pass, six documents told a reader that publication was imminent:

- both packages' READMEs — *"not yet published"*, *"until that first publication happens"*;
- both packages' changelogs — a `## [0.1.0]` heading I had added three hours earlier;
- `docs/workflow/release.md` — a runbook whose framing was "here is how you will do this";
- `ROADMAP.md` — Milestone 8 named after `utils-psr7-bridge-v0.1.0`.

**"Not yet" and "not going to" are different claims, and only one of them was true.** A closed
issue does not propagate; every one of those sentences had to be found and changed by hand. Nothing
in the repository would have caught the drift — `consistency_lint.py` verifies congruence between
documents, not congruence with a decision.

## The changelog headings had to come back out

`## [0.1.0] — 2026-08-27` in each package, added in #183 specifically so
`bridge_release_gate.py` would anchor a tag to something. With no tag being cut, those headings
name a version that exists **nowhere** — not on Packagist, not as a tag, not in a split repository.
Under Keep a Changelog a versioned heading means *released*.

Folded back to `[Unreleased]`. The three-hour lifetime is a little embarrassing and it is the right
call regardless: a false version claim in a changelog is exactly the class of thing this repository
spends ADRs on, and "I only just wrote it" is not an argument for keeping it.

**What was kept is the evidence, because evidence and outcome are different things.** Release mode
— the gate ADR-0035 §2 calls the one that cannot be faked — really was exercised: both packages
copied out of the monorepo, installed resolving `egl/utils` from Packagist as a consumer would, and
run. PSR-7 **65 tests / 202 assertions**, PSR-18 **28 / 72**, green. That fact does not stop being
true because the push is not happening, and it is precisely what a future reader needs if the
decision is ever revisited: **the pipeline is proven up to the push.** What stays unproven is the
split-and-push step alone, and `bridge-release.yml` keeps its zero runs.

## What was deliberately not touched

- **The archives.** `docs/changelog/v1/v1.0.0.md`, `v1.1.0.md` and the ROADMAP retrospectives all
  describe publication as forthcoming. They are the record of what was true when written. Editing
  them would be falsifying history to make the present tidy.
- **The specs.** Specs 02 §6 and 03 §7 describe the publication *design*, which is unchanged and
  correct. The decision is operational, not a requirement change, so no revision was added — the
  ADR, the issue and `release.md` carry it.
- **Milestone 8's heading.** It is named `utils-psr7-bridge-v0.1.0` after a tag that will not exist.
  Renaming it would rewrite the record of what the milestone was scoped as, and `consistency_lint`'s
  `milestones` check ties README rows to those headers. A note in the preamble instead.
- **The runbook itself.** `release.md`'s bridge procedure is kept rather than deleted: it is
  correct, it was verified up to the push, and the decision is **reversible** — a deploy key scoped
  to a single split repository needs one workflow change, not a rebuild. A deleted procedure would
  have to be rediscovered.

Three items where the honest move was to leave something that reads as out of date, and say why.
**Deleting a true record to remove an inconsistency trades a small confusion for a permanent one.**

## The second decision, and why it went in the same pass

Leaving the signing decision implicit would have been the identical defect one level down.
`release.md` step 1 read as a to-do list item. It now records that no key is being registered, and
states the consequence exactly rather than leaving it to be discovered: `verify-tag` fails on every
tag, `test-tagged-tree` and `draft-release` are skipped, and the GitHub Release is hand-written —
which is what happened to `v0.11.0`, `v1.0.0` and `v1.1.0`, and why `v1.1.0` never reached Packagist
at all.

**The consequence worth naming loudly: `tools/post_publish_gate.py` is now load-bearing.** With the
signing gate permanently red, it is the only remaining check that notices a tag which never became a
release — the exact failure it was built for after `v1.1.0` sat for three days while `ROADMAP.md`
read as though it had shipped. It runs nightly, and step 11 says to run it by hand after every
publish. That was good practice before; it is the safety net now.

#115 stays open on that one criterion, and reversibly: an **SSH** signing key needs no email address
and `ssh-keygen` is already on the machine, so registering one later is a settings change rather than
a rework. Its other two criteria are done — the pre-push guard (#162) and the SBOM attestation
(#181).

## Also this session, unrelated and overdue

`master` had **no branch protection at all** — `gh api .../branches/master/protection` answered
`404 Branch not protected`. The rule that only the maintainer merges was holding purely because no
agent breaks it and nobody else has write access. Now enforced: pull request required, force pushes
and deletions refused, linear history, conversation resolution, and seven required checks.

**The benchmark job was deliberately left out of the required set.** It has a documented history of
red runs from runner noise — ADR-0045 and item 12.6 exist because of it, and it has been red on
`master` for reasons unrelated to any diff. A required check that fails on noise trains you to
merge past required checks. `enforce_admins` is off, too: a solo maintainer locked out by their own
protection has no second party to unblock them.
