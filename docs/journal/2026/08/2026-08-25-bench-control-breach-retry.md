# 2026-08-25 — The exit code ADR-0057 wrote, finally read by something other than a human

Issue **#99**, both criteria. Route `fast / medium`; session model Sonnet 5. **ADR-0057**
annotated.

`bench_regression_gate.py` has known the difference between "this run is invalid" and "this run
found a real regression" since item 12.6, three weeks ago. It exits `2` for the first and `1` for
the second, and its own docstring says exactly what a reader should do with a `2`: *"re-run the
job on a fresh runner rather than investigating the diff."* Nothing in CI ever read that number.
The instruction sat in a log message, and someone had to notice it, understand it, and click
re-run by hand — the exact kind of toil ADR-0057's own distinct exit code was supposed to make
obsolete.

## The one design decision worth explaining: retry *both* halves, not just the one that failed

The tempting shortcut is "the base measurement is the newer one in the loop, so re-measure that."
It is wrong, and the reason is in ADR-0030, not ADR-0057. The same-runner A/B's entire noise
argument rests on base and HEAD being measured **sequentially, back to back, on one runner** — that
adjacency in wall-clock time is what makes a 10% threshold meaningful at all (measured at item
12.3: five consecutive passes inside one job spread 0.4–1.5%, while a stored-baseline comparison
ranged 40% peak to peak on GitHub's shared runners).

Refreshing only the base half and comparing it against the *first attempt's* HEAD measurement would
not repair that adjacency — it would compare two numbers from two different windows of time, which
is a different invalid comparison, not a valid one. So the retry re-measures HEAD and the base
commit **together**, in the same order, before calling the gate again. This is not a
"nice-to-have" hedge; it is the one detail that would have made the whole feature silently wrong if
missed.

## What stayed exactly as strict as ADR-0057 intended

Two guardrails, both load-bearing:

- **Exit `1` is never retried, on either attempt.** The entire reason exit `2` exists as a separate
  number is so a real regression and an untrustworthy run are never confused with each other.
  Retrying a `1` — even "just once, to be safe" — would blur that distinction while technically
  satisfying "consume the exit code with a retry," which is the failure mode worth naming
  explicitly rather than discovering in review.
- **A second `2` in a row fails loudly and does not retry again.** ADR-0057 gives this one attempt.
  Two consecutive invalidated runs on the same control subject(s) is not the kind of thing a third
  attempt is likely to fix — it stops reading as runner noise and starts reading as something worth
  a human looking at the runner itself.

## How I checked the control flow before trusting it in CI

There is no existing precedent in this repository for unit-testing bash embedded directly in
`ci.yml` — every prior CI-only control-flow addition (the release-PR detection step, the BC
report step from issue #112) lives as inline bash with no separate harness, and I followed that
convention rather than inventing a new one for this PR alone. But five branches of exit-code logic
deserved more than "looks right": I extracted the identical branching structure into a throwaway
script with a fake `gate` function returning a scripted sequence of codes, and ran all five cases
(`0`; `1`; `2 0`; `2 1`; `2 2`) before writing a single line into the real workflow. All five
behaved exactly as specified. The scratch script is not part of this PR — it was scaffolding, not
a deliverable, and the real assertion is the workflow re-running for real on CI once this PR is
open.

## Where this leaves the project

No PHP changed; `bench_regression_gate.py` is untouched, because the exit codes it already
produces were sufficient — this was entirely a CI-consumption gap, not a gate-design gap. One
workflow step replaced two. The real test of this change is CI itself, on the PR that carries it:
whether the benchmark job passes cleanly on a normal run, and — should a control subject actually
breach on this or any later PR — whether the retry behaves the way the simulation above predicted.
Anything CI finds gets pinned in the PR rather than assumed away, the same discipline the last
three PRs in this repository have each had to apply to their own first real execution.
