# 2026-08-21 — The clock cannot wait, and my own claim was wrong

Roadmap item **14.5**, issue **#94**. Route `frontier-reasoning / extra` (adr, protected floor);
session model Opus 5 — **matched**.

`Support\RetryPolicy` and `Support\Retrier` are the item. Two things happened along the way that are
worth more than the feature: the requirement turned out to be unbuildable as written, and a planted
defect proved a claim I had already written into the ADR was false.

## ★ The item was unbuildable, and PSR-20 is why

FR-49 says the delay is "consumed through the clock seam so tests never sleep". `ClockInterface` has
`now()`. That is the whole interface — there is no `sleep()`, and there was never going to be one.

A clock can *measure* a deadline. It cannot *spend* a backoff. So the requirement's own sentence
describes something the seam it names cannot do, and the honest options were: inject a clock and call
`usleep()` anyway (which satisfies the words and defeats the purpose — every retry test then runs in
real time), or admit that waiting is a second capability.

`Support\Sleeper` is that second seam, with both halves shipped for ADR-0062's reason verbatim: a
seam whose production half is the only one published makes every project write its own double, and
they all write it slightly differently.

The load-bearing detail is that **`FrozenSleeper` advances the `FrozenClock` it holds by exactly what
it was asked to wait**. A double that only recorded the request would leave time standing still, so
no deadline could ever arrive and every deadline assertion would pass while asserting nothing.
Advancing the clock is what makes "tests never sleep" and "the deadline is exercised" the same run
instead of a trade between them.

Third item in this project whose text could not be implemented as filed — 10.4's `QueryBuilder` was
SELECT-only, 11.1's per-phase timeout bounded no request — and the same resolution each time: correct
the spec in the same PR, and say what was wrong rather than quietly building something else.

## ★ A plant proved my own claim false before the ADR shipped it

I had written into ADR-0066 §1 that planting away the clock advance "turns the deadline suite red,
not only the sleeper's own". It was a good argument. It was also wrong, and the campaign said so:

```
P15 FrozenSleeper records but never advances the clock
  caught by 3: testAdvancesAreCumulative, testSubSecondAndWholeSecondPartsBothLand,
               testTheFrozenSleeperAdvancesTheClockItWasGiven
```

Three tests, all in `SleeperTest`. The entire `Retrier` deadline suite stayed green.

The reason is a coverage gap I had built myself. Full jitter makes delays random, which would make a
deadline assertion flaky — so every deadline test used `baseDelayMs: 0` and moved time from *inside
the operation* instead. That is a legitimate way to get determinism, and it means **none of those
tests rested on the coupling I was arguing for**. Worse, the scenario it left uncovered is a real one:
**the backoff itself spending the deadline** — a fast-failing dependency behind a generous attempt
count, where no single attempt is slow and the loop still runs long.

That test now exists. The operation fails instantly, so the only thing that can move the clock is the
waiting, and the assertion is exact rather than approximate: elapsed wall clock must equal the sum of
recorded waits, to the millisecond. Re-run, P15 reddens it.

**The plant found a missing test, not a defect.** And it is the same shape as BUG-0001 one item
earlier: a guarantee that nothing was actually resting on. The generalizable habit is not "plant
defects" — I was already doing that — it is **write the claim, then plant against the claim**, not
only against the code. An argument in an ADR is a testable assertion about the suite, and mine was
false for a day.

## Two decisions the item did not name

**Jitter is structural, not a default.** There is no argument that disables it. It has to be that way
because behaviour cannot see it: an implementation returning the un-jittered exponential satisfies
every assertion of the form *"the delay is between zero and the ceiling"* — the plain value is inside
its own band. What catches its absence is a distribution test (300 draws must not collapse to one
value; a correct implementation doing so has probability `(1/1601)^299`) plus a mechanism assertion —
no parameter names jitter, `delayFor()` holds the draw verbatim, and `delayFor()` contains no
conditional at all, because a branch is where a bypass would live.

**A bound that carries a runtime refusal must not also be a narrow static range.** PHPStan reported
ten errors in my own tests, and every one was a test trying to reach a guard that a `positive-int` or
`int<0, max>` parameter had made statically unreachable. The only way to keep both was an analyser
suppression this project forbids. So the accepted bounds are plain `int` and the narrow types moved to
the return side, where they help a consumer and cost nothing.

That settles a project-wide rule, and its converse holds too: where the static type **is** the
mechanism — `SqlStatement`'s `literal-string` behind a private constructor, ADR-0041 — there is no
runtime check to strand, and the narrow type is exactly right. The question to ask is which of the two
is doing the enforcing.

## The deadline's limit went into the spec, not just the ADR

ADR-0049 found PHP's per-phase stream timeout **re-arms** and therefore bounds no request. FR-49
exists because an attempt *count* bounds no retry loop for the same reason.

The limit that has to be stated in the same breath: **a deadline cannot end an attempt that is already
running.** Control is inside the caller's operation, and `Retrier` gets it back only when that
operation returns or throws. So what the deadline guarantees is that no *new* attempt begins past it.
A loop deadline over an unbounded attempt is the same false comfort ADR-0049 removed, one level up —
and a consumer who reads only the parameter name takes the wrong assurance from it. That is why it is
in the spec text and the changelog, not only in the ADR where a consumer will not look.

Related, and decided the same way: a delay that would not fit inside the deadline **ends the loop
rather than being shortened**. Sleeping the remainder and attempting anyway leaves the attempt no time
to succeed, and clamping the backoff means retrying soonest exactly when the evidence says the
dependency is struggling. Behaviour can see that the loop stopped but not *why*, so the absence of the
clamp is a mechanism assertion too.

## Campaign: 16 planted, 16 caught

Three were caught by mechanism assertions and nothing else — the jitter removed, the delay clamped to
the remaining budget, and the ceiling left unclamped. That is ADR-0027's premise demonstrated again
rather than asserted.

## Process, and the harness lying for the third item running

1. **The harness timed out mid-campaign and left a defect on disk**, because its restore only ran
   after the last plant. A harness that can fail halfway has to be idempotent: restore *before* each
   plant, and run it in the background where a timeout cannot kill it. Item 14.4 learned that
   `git checkout --` restores from the index and so needs the tree staged first; this is the same
   lesson's other half — **the harness is code, and it gets the same scrutiny as the code**.
2. **A replacement string containing its own original edited nothing**, for the third consecutive
   item. The restart-the-deadline plant only landed once I verified it by the **property** it breaks
   (`$startedAt` is now re-read inside the loop) rather than by a substring.

## Left as it is

No NFR budget: RFC-0003's reasoning for the clocks applies unchanged, and ADR-0040 reserves spec
numbers for the maintainer. No new deptrac rule — `Support → Psr` has been granted since ADR-0062, so
the clock cost nothing architecturally. No new exception type either, so `ExceptionHierarchyTest` is
untouched: construction validation raises PHP's `InvalidArgumentException` on `Str::random()`'s
precedent, and RFC-0003's anticipated `UtilsException` descendant turned out not to be needed. Said
out loud, because an absence leaves no trace.

**M14 now has only 14.7 open** (the rate limiter ADR-0061 designed), so `consistency_lint`'s milestone
check keeps README's M14 row at planned until it lands.
