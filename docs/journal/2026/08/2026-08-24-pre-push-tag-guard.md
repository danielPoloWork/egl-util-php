# 2026-08-24 — Three for three, and the guard that had to allow the fourth

Issue **#115**, criterion 2. Route `standard / medium`; session model Opus 5 — matched.
**ADR-0032** annotated.

`v0.11.0`, `v1.0.0` and `v1.1.0` each went up as an annotated but **unsigned** tag. Each time
`release.yml`'s signing gate refused it, the tagged-tree matrix and the draft job were skipped, and
the Release was published by hand. Each time the defect was found in a CI log **after** the tag was
public — the one moment a tag cannot be corrected in place.

## The design question was not "how do I block this"

The obvious guard refuses an unsigned release tag, full stop. I did not write that one, and the
reason matters more than the code.

The maintainer has chosen an unsigned tag **three times, with the outcome known in advance each
time**. A guard that simply blocked that would be met with `git push --no-verify` on the first
release after it landed — and a bypassed guard is worse than none, because now there is a rule
everybody has learned to step over.

Issue #115's own wording is the way out: *"making the failure impossible to repeat **silently**."*
Not impossible. **Not silently.**

So the guard refuses by default and takes an override that must carry a reason:

```
EGL_UNSIGNED_TAG_REASON="no signing key registered yet; see #115" git push origin v1.2.0
```

It echoes the reason back and prints what will follow — the gate will fail, the matrix will not run,
the Release needs hand-publishing, record it in the notes *before* publishing. An unsigned release
becomes a sentence someone wrote on purpose, instead of something noticed afterwards.

A blank override is not an override.

## The three properties that took thought

**It refuses lightweight tags too.** A lightweight tag is a commit ref: no tagger, no date, no
message, and it *cannot carry a signature at all*. The failure mode is different from unsigned and
the message says so, because "re-create it annotated" and "sign it" are different fixes.

**"I cannot check" exits 2, not 0.** A tag the guard cannot read is not a tag it approved. This is
the distinction all five of this project's vacuous-green defects failed to make — items 2.7, 10.8,
13.2, 13.7, and the BC gate in #157 — and by now writing the refusal branch first is cheaper than
discovering its absence.

**An ordinary branch push produces no output at all.** Not "OK", not a summary line — nothing. A
guard that comments on every push is a guard someone disables for being noisy, and then it is not a
guard. That is one of the fifteen proof cases, and it is there because the failure it prevents is
social rather than technical.

## Verified against the real thing first

The guard was run against this repository's actual `v1.1.0` — annotated, unsigned, published two
days ago — before any synthetic case existed. It refuses, and names the consequences. That is the
tag whose defect three consecutive releases discovered too late.

`tools/tests/verify_tag_guard.py` is the repeatable half: 15 cases. **The signed path is the one
branch not exercised**, because no signing key exists on any machine that runs it — which is #115's
first criterion, the owner's, and stating it beats implying the branch was tested.

## Criterion 3: attestations, and why not

#115 asks for GitHub artifact attestations, *"closing the last unsigned link in an otherwise
SHA-pinned supply chain."* Following that through:

- the chain is repo → tag → **Packagist** → a consumer's `vendor/`;
- the artifact a consumer actually installs is the **zip Packagist builds from the tag**;
- an attestation covers a file *our* CI produced.

So attesting a `git archive` of our own would not cover what anybody receives — different producer,
different bytes, different digest. To make one verifiable we would have to attach our archive as a
Release asset, which reverses `release.md:65`'s recorded decision that **no build artifacts are
attached: the release IS the tagged source**.

And the premise does not hold either way: **the unsigned link is the tag.** Attestations sit beside
it, not on it. A signed tag is what closes it, which is criterion 1.

Left open with that written down rather than satisfied with ceremony — the same call as item 12.1's
deleted dead guard and 12.4's unreachable boundary check. Reversing a recorded decision about what a
release contains is the maintainer's, not mine.

## Where #115 stands

| Criterion | |
|---|---|
| 1. Signing key registered | **open** — owner action, and the reason the other two exist |
| 2. Pre-push guard | **done** |
| 3. Attestations | **open**, with the analysis above arguing it is the wrong instrument |

The issue stays open. Two of three criteria are not something an agent can close.
