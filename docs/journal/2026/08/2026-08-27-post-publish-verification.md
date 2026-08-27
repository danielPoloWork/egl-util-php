# 2026-08-27 — The gap the issue described was already open

Issue **#105**, both criteria. Route `fast / medium`; session model Sonnet 5. **ADR-0081**
annotated.

The issue reads as a straightforward tooling gap: `release_gate.py` checks a tag before it's
pushed, nothing checks what happened after a human publishes. The natural first step before
writing a checker is to know what it would say about this repository's own history — `v1.0.0`'s
hand-publication is already documented (issues #115, #122), so I expected one confirmed, known
gap. I did not expect to find a second one that nobody had noticed yet.

## Checking the repo's actual state before writing the checker

`v1.1.0` is in `git tag -l`. It is not on Packagist (`repo.packagist.org/p2/egl/utils.json` lists
only `v1.0.0`), and `gh release view v1.1.0` returns "release not found". The tag itself is
unsigned (`git tag -v v1.1.0`: "error: no signature found"). Pulling the actual workflow run
confirms the mechanism: `verify-tag` failed on 2026-08-24 for exactly the reason it exists to catch
— an unsigned tag — and `draft-release` never got to run. Three days, and `ROADMAP.md`'s own text
("First MINOR under that tree: `v1.1.0`") reads as though it shipped.

This is not a hypothetical the issue was worried about. It is the issue's own failure mode,
sitting in the repository while the issue was being worked. I stopped and asked the maintainer how
to handle it rather than fold a live release decision into a tooling PR — first whether to fix it
before or after building the tool, and once "fix it first" was chosen, I ran into a second wall:
signing a tag needs the maintainer's own key, and this session has none — no GPG, no SSH, nothing.
I cannot produce what GitHub would accept as this maintainer's signature, and should not be able
to. That is not a workaround-able gap; it is the correct shape of the boundary between what an
agent can do and what only a human can. Asked again, narrower: delete the broken tag now, leave
signing to the maintainer. Confirmed no Release and no Packagist entry existed to lose, then
`git push origin --delete v1.1.0`.

`docs/workflow/release.md` already licenses exactly this: agents "only delete-and-repush an
*unpublished* tag whose release run visibly failed." I did not invent new authority for this — the
policy was already there, evidently written with exactly this scenario in mind, and this is the
first time it had a real case to apply to.

## Building the checker, with the live case already in hand

Four checks: tag signed, Release exists and isn't a draft, body matches the canonical render, and
Packagist has the version. The one worth explaining is the body check. `release.yml` passes
`generate_release_notes: true` alongside `body_path`, and GitHub *appends* its own auto-generated
notes after whatever body was given rather than replacing it — the workflow's own comment says so.
An equality check would therefore fail on every correctly-published release, which is the wrong
kind of red. The check is a prefix match instead: the canonical rendering (produced by shelling out
to `release_body.py`, reused rather than reimplemented, because two renderers of one source
drifting apart is a defect class this repository already named once) has to be the start of what
was actually published.

Running the finished gate against real data landed exactly where the investigation predicted.
`v1.0.0`: two problems, both already tracked (unsigned tag, hand-written body). A nonexistent tag:
"could not verify" for the checks that need the tag to exist, plus real "not on Packagist" and "no
Release" findings for the ones that don't. `v1.1.0`, before I deleted it: the same shape, live.
Nothing here needed a mocked test to trust — the repository's own state was the test fixture.

## The manual step wasn't going to be enough

The issue's literal ask is a tool plus a documented step in `release.md`. I built both, and then
kept going, because the manual step is precisely the thing that already failed: someone (an agent,
in this case) tagged a release and nobody circled back to check it landed. A step that depends on
memory is not a fix for a problem caused by memory. `nightly.yml` gets a `post-publish` job that
resolves the latest non-draft Release and runs the gate against it, on the same clock as the audit
and mutation jobs already there. This is scope beyond the literal acceptance criteria, and I want
to be honest that it is a judgment call rather than something the issue asked for outright — but it
is the same call this project has made every other time a "someone has to remember" gap showed up
in this codebase, and the evidence for making it again was sitting in the tag list before I wrote a
line of the tool.

## Where this leaves the project

No production code changed. One new tool, one new nightly job, one documented release step, this
ADR — and one fewer broken tag on origin. `v1.1.0`'s actual content (M13's close-out, M14's five
seams) is untouched on `master`; only the tag that never became a release is gone. Re-tagging it
signed is the maintainer's to do, and the new gate is what confirms it worked once they do.
