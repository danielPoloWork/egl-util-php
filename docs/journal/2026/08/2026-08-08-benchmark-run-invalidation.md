# 2026-08-08 — Closing the milestone by fixing the tool that kept flagging it as open

Roadmap item **12.6**, the last item in Milestone 12. Route `standard / medium`; session model
Sonnet 5 (`fast` tier, kept from the switch before item 12.5) — mismatch recorded, not glossed.
Proceeded on the maintainer's explicit go-ahead; the choice among the four filed options is an
engineering call about a gate's own mechanism, the same kind ADR-0045 made unilaterally, not a
spec or budget number reserved for the maintainer under ADR-0040.

## What was actually broken

Item 12.4's CI ran one commit twice and failed two different gates on two different runs, neither
attributable to the diff. Run 1 failed the *relative* gate on five subjects, one of them
`RowNormalizerBench::benchInlineTrimHundredRows` — a hand-written inline `trim()` loop with no
dependency on this project's code, added specifically as a floor to measure overhead against. It
cannot regress. It moved +19.44% anyway. Run 2 passed the relative gate and failed the *absolute*
ceiling instead, with every subject 27–103% slower than run 1 on identical code.

Both were the same fact seen from two angles: the runner got slower (or, in principle, faster)
*between* the sequential base and head measurements ADR-0030's same-runner A/B relies on, and that
shift lands on whichever half ran second, indistinguishable from a real regression.

`--exclude` (ADR-0045) was built for a different shape of problem — a subject that is
individually noisy on its own mechanism (filesystem locking, memory-hard hashing) — and does not
generalize to this one. The control subject is not noisy; it is one of the quietest subjects in
the suite, and that is precisely what makes its movement meaningful.

## The fix, and the one I didn't take

Four options were on the table. (a): name control subjects and invalidate the run when one moves.
(b): net every subject against the control's own delta, so a uniform shift cancels arithmetically.
(c): interleave base and head instead of measuring them sequentially. (d): keep re-running by
hand.

Chose (a). Not because (b) is wrong in principle, but because run 2's own numbers argue against
trusting it yet: subjects moved 27–103%, not by one consistent factor, so subtracting a single
control's delta from everything else could as easily manufacture a false pass on real drift as
fix a false fail on none. (a) needs the same infrastructure (a named, trusted-zero subject) and
makes no arithmetic claim beyond "this shouldn't have moved, so nothing here is trustworthy" —
which is exactly what the evidence supports. (b) is filed as a follow-up if (a) proves too coarse.

(c) touches `ci.yml`'s worktree choreography, not the gate script, and is a larger change for the
same finding. (d) is the status quo the finding is evidence against: item 12.4 needed three CI
attempts before a human could tell "re-run" from "investigate" apart, and two of those three
weren't even gate verdicts (a concurrency cancellation from my own ill-timed re-run, then a
30-minute phpbench timeout) — a separate lesson about reading a job's `conclusion` and duration
before diagnosing anything, already carried from that session.

## The decision I almost got wrong: which direction to check

The observed incident was a slowdown. The temptation is to check only for regressions past the
threshold. But the mechanism — a runner's speed changing mid-job — has no reason to only go one
way, and a runner *speeding up* between the two halves would show every subject as an improvement,
which is exactly as untrustworthy a comparison and, worse, could hide a genuine regression
underneath an apparent win. `abs(delta) > threshold` is what the reasoning demands; checking only
the regression direction would have been consistent with the one incident on record and wrong
about the mechanism that produced it.

## What "invalidate" means concretely

The gate did not gain a third healthy state GitHub Actions understands. Exit code `2` is still
just a non-zero exit — the job still shows red. What changes is the log: `INVALID` and a named
control breach read differently from `FAIL — N subject(s) exceeded the budget`, and the message
says "re-run the job" rather than implying "read the diff." Exiting `0` on invalidation was
considered and rejected outright: an invalidated run tells us nothing about whether the code
regressed, and reporting that as a pass would let a real regression through under the exact cover
this finding describes.

One more decision fell out of testing rather than being planned: when a control breaches
*alongside* a subject that looks like a real regression, the gate reports only `INVALID`, not
`INVALID` plus a separate `FAIL` for the other subject. Once the comparison is compromised, that
other subject's number carries no more information than the control's does — printing both would
let a reader pick whichever framing suited them, and the verification suite's case 4 exists to
prove this is what the code actually does, not only what the ADR argues.

## Verification, the usual way

No permanent pytest suite exists for any `tools/*.py` in this repository — the standing method,
carried from items 10.5 and 10.9, is a synthetic `phpbench --dump-file` XML fixture. Eight cases,
including an exact reproduction of item 12.4's run-1 numbers now producing `INVALID`/exit 2 with
no old-style `FAIL` block, and the same numbers with no `--control` given reproducing the
pre-existing output byte-for-byte — the proof that this is additive, not a behavior change for
anyone who has not opted a subject into `--control`.

## Two controls, not one

`RowNormalizerBench::benchInlineTrimHundredRows` (item 10.11's) and `LoggingBench::benchSinkDirectly`
(item 12.3's) both went in. One control's blind spot is a slowdown that lands entirely inside its
own measurement window and outside another's — two independent, already-existing sentinels in the
same job cost nothing extra and roughly halve that risk. Neither is new code; both already existed
as controls in their own bench files' design. This item only told the gate to look at them.

## The milestone closes

M12's six items are all checked. README's row flips to done — the last of the four RFC-0002
milestones this session has walked start to finish (M9 Support & values, M10 Persistence, M11
Http application layer with two open decisions, M12 Security & channels with none). Three open
maintainer decisions remain across the whole plan: 11.6 (a noisy budget with no clear cause),
11.7 (the router's NFR-11 ceiling, now six measurements deep), and — filed by this item's own
predecessor — none from 12.6, since this item is the decision, not another deferral.

## Postscript — the mechanism has never run on real CI

The PR carrying this change failed `ci.yml`'s `benchmark` job — at the absolute-budget step, on
item 11.7's already-known router regression (`benchDispatchLastOfFiftyRoutes` 7.069 µs, a seventh
data point for that open decision). GitHub Actions stops a job at its first failed step by
default, so every step after it — steps 9 through 13, including the regression gate this item's
own `--control` logic lives in — was reported `skipped`, not run.

I had already lived this exact shape once, from the other side: item 10.10's journal recorded that
its own new regression-gate step "never actually executes in CI, only locally," blocked by NFR-09
staying red. That entry did not stop the same structure from repeating — this time 11.7 sits in
NFR-09's old seat, and item 12.6 sits where 10.9's `--exclude` sat, untested in production for the
same reason. Recognizing a pattern once is not the same as having fixed it; the fix was never in
scope for either item, since a step-ordering change is a job-wide decision neither item's own
subject warranted making unilaterally.

So this item's correctness rests entirely on the eight synthetic-fixture cases above, not on a
live confirmation — worth saying plainly rather than letting a green PR checklist imply more than
the evidence supports. Noted on item 11.7 rather than patched here, for the same reason ADR-0057
does not reorder `ci.yml`'s steps: that fix belongs to whoever resolves 11.7, since it is a
property of the job, not of this one gate's logic.

## Lesson

A gate that cannot tell its own noise from a real signal will eventually be trusted less than no
gate at all — the same fate item 10.5 nearly gave `--exclude`'s two subjects before ADR-0045 named
the criterion. The fix here is not a bigger threshold or a longer retry list; it is a second,
independent measurement — the control — whose only job is answering the question the primary
measurement cannot ask of itself: *is this run even trustworthy?*

And a second lesson, sharper for having now happened twice: naming a recurring structural problem
in a journal does not fix it for the next item that walks into the same trap. If a pattern is
worth writing down twice, it is worth turning into a decision someone can act on — which is what
the note on item 11.7 now is, rather than a third journal entry waiting to happen.
