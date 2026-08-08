# 2026-08-08 — Two open decisions, one table, and the number I nearly picked

Roadmap items **11.6** and **11.7**, settled together. Route `frontier-reasoning / extra` (11.6) and
`standard / medium` (11.7); session model Opus 5 — matched for 11.7, one tier below for 11.6,
recorded rather than glossed. The maintainer switched to Opus and delegated both decisions in the
same message ("decidi tu su 11.6 e 11.7"), which is the only thing that made writing these numbers
legal: ADR-0040 had reserved them, and that reservation is a maintainer's to lift.

## The table did the deciding

Both items had been filed with their own evidence, and I could have argued from either one's history
alone. Instead I tabulated **every** gated absolute budget against its worst observed reading:

```
benchDispatchLastOfFiftyRoutes    7.145 /      5  =  0.70x   BREACHED
benchSequenceNext               208.768 /    200  =  0.96x   BREACHED
benchBuildFiveConditionSelect     3.763 /     10  =  2.66x
benchFirstAutowiredResolve        7.221 /     30  =  4.15x
benchEnvelopeBuild                0.389 /      2  =  5.14x
benchSuppressedRecord             0.097 /    0.5  =  5.15x
benchHydrateWarm                  0.958 /      5  =  5.22x
benchWriteTenThousandByTen    20348.286 / 150000  =  7.37x
benchCryptoRoundTrip              6.995 /     60  =  8.58x
benchWarmSingletonResolve         0.060 /      2  = 33.33x
```

**There is a gap in that column and nothing lives in it.** Two subjects below 1×, then nothing until
2.66×. The two open items are the only two ceilings ever set inside that band. That is not an
argument I constructed; it is the repository's own history answering a question nobody had asked it.

So the rule wrote itself: **an absolute ceiling needs ≥ 2× the subject's worst reading**, or it is
inside the noise envelope and will eventually fail on unchanged code.

## Two items, two different diagnoses

What the table could not tell me was *why* each had breached, and there the two turned out to be
opposites:

- **The router's ceiling was wrong about the code.** Over budget in 10 of 11 readings, median ~5.6 µs,
  and the cost scales precisely as ADR-0050's linear scan predicts: 0.674 µs for the first of fifty
  routes, 5.581 for the last, ≈0.10 µs per failed `preg_match()`. Noise sits *on top of* a true value
  that is already above 5.
- **The sequence counter's ceiling is right about the code.** Typical 75–190 µs, well inside 200;
  exactly one reading of seventeen crossed it, by 4%, and passed on re-run.

One ADR settles both because the *framework* is shared and the *diagnoses* differ — which is worth
more than two separate ADRs would have been, since the distinction between "the number is wrong" and
"the number is unverifiable here" is the reusable part.

## Checking the measurements before blaming them

Before concluding "noise," I checked whether phpbench agrees with itself. Within one CI job: the
router **±0.60%**, `benchHydrateWarm` ±1.95%, `benchCryptoRoundTrip` ±1.02%, and even the sequence
counter converging to ±2.31% after retries. The instrument is fine. The instability is entirely
cross-run — 1.51× for the router, 2.77× for the counter — which is exactly the split ADR-0030
measured and built the same-runner A/B around. What neither ADR-0030 nor ADR-0045 had done was ask
what that split implies for the *absolute* ceilings sitting beside it.

I also nearly misread the retry output as evidence: intermediate retry lines showed ±184%, ±57%,
±72%, and for a moment I had a much more dramatic finding ("the subject cannot agree with itself").
Those were phpbench's *intermediate* retries; the final reported figure had converged to ±2.31%. The
retry mechanism was doing its job. Checking the last line rather than the loudest one is what
stopped a wrong claim reaching an ADR.

## The number I nearly picked

For the router I first wrote **10 µs**, and it is the number the item itself suggested. It is
defensible: 1.40× the worst reading, and it still fails a doubling of the median (11.2 > 10).

Then the second half of the decision made it wrong. If the ceiling's job is *accumulated drift* —
ADR-0030 §2's own "twenty commits at +9% each pass every relative check and still double the
runtime" — then catching a single 2× jump was never its assignment. That is the relative gate's job,
and at ±0.60% within-run precision it does it sixteen times more finely than its own 10% threshold.
Once the two gates stop competing for the same headroom, 10 µs's only advantage evaporates and its
disadvantage remains: at 1.40× it is still inside the envelope the table just measured.

So **15 µs** (2.10×), and **450 µs** for the counter (2.16×) by the same rule. Consistency with a
rule I had just derived mattered more than the rounder number.

## What I refused

Item 11.7 offered adding a cache or index to `Router`, with the framing that ADR-0050's non-goal
"named 'a 50-route table matches in microseconds' as the reason no cache was needed, **a claim this
measurement corrects.**"

It does not correct it. It confirms it. 5.6 µs *is* microseconds — roughly 0.1% of a millisecond-scale
HTTP request. An index would buy a tenth of a percent and cost a build step, a cache-invalidation
question and a second code path to test.

And the uncomfortable half, said out loud in the ADR because leaving it unsaid would be the actual
failure: **raising a ceiling a subject breaches is exactly what tuning-the-benchmark-until-it-passes
looks like from outside.** The only thing separating the two is the order of operations — the code's
cost was measured first, found to be a documented and deliberate design property, and judged
acceptable on its merits; only then was the number that mis-described it corrected. Had the router
measured 500 µs, this would have gone the other way, and item 4.6 is the precedent for that
direction.

## The argument that closed (c)

Item 11.7's third option was to accept the gap and ship the job red. That option had looked
defensible when it was filed — items 3.5 and 3.7 set exactly that precedent. What killed it was
evidence produced two items later: with the job failing at its absolute-budget step, GitHub Actions
skipped every step after it, so **item 12.6's regression-gate logic never ran on CI at all.**

A permanently red gate does not merely annoy people into re-running. It silently disables everything
downstream of it in the same job — and I only learned that by walking into it.

## Lesson

When two decisions have been open long enough to be filed separately, the useful move is not to
argue each on its own evidence but to look for the table that contains both. The gap between 0.96×
and 2.66× answered a question I had been about to answer with judgement, and the answer it gave was
better than the one I had drafted.

And a smaller one, from the retry output: the loudest number in a log is rarely the one that means
something. Read the last line.
