# 2026-08-04 — The benchmark was measuring the wrong thing, and I wrote it

Roadmap item **4.6**, run under `/eados optimize`. Routed `fast / medium`; ran at frontier tier
because the change touches `QueryBuilder`, whose allowlist is the sole identifier defence — a
performance edit there has a security failure mode. Mismatch recorded rather than papered over by
picking a flag that would have manufactured a match.

## The premise was wrong on both counts

Item 4.6 was filed by item 4.5 saying: *a 5-condition SELECT measures ~23µs against ≤10µs, most
plausibly because `quote()` re-fetches the driver per identifier.*

Profiling before changing anything killed both halves.

**The hypothesis was wrong about magnitude.** `driver()` costs 0.125–0.213µs. Twelve of them is
~1.5–2.5µs of ~23µs. Real, but not the gap. The cost is spread across validation, quoting,
`sprintf`, cloning and method dispatch — concentrated nowhere.

**The workload was wrong, and that's the finding that matters.** NFR-03 budgets a *"5-condition
SELECT"*. My item-4.5 subject built five conditions **plus** a five-column `select()` list, an
`orderBy`, a `limit` and an `offset` — twelve quoted identifiers where the spec names five
conditions.

| workload | µs |
|---|---|
| literal NFR-03 (`SELECT *` + 5 conditions) | **14.43** |
| what I actually benchmarked at 4.5 | 24.69 |

Roughly two thirds of the "gap" I reported was my own benchmark's scope.

The part that stings: **the benchmark's own docblock said it was counting "exactly five calls that
each contribute one condition."** The doc and the code disagreed, and I wrote both. Nobody caught
it because the number looked plausible and confirmed a story that was already coherent — hydration
had missed its budget too, so a builder missing its budget fit the pattern.

## Correcting a published wrong number

The ~23µs figure is in a benchmark record, an ADR, a changelog entry, and a merged PR body. Fixing
the code quietly would leave four artefacts disagreeing with reality. So:

- item 4.5's benchmark report keeps its text and gains a banner pointing here
- ADR-0018 is marked partially corrected — its *reasoning* about handling a measured gap stands,
  its central number doesn't
- the benchmarks index shows both rows, the old one struck through

## Guarding against the obvious objection

Narrowing a benchmark until it improves is exactly how a metric gets gamed, and that is a fair
thing to suspect here. Two things are the defence:

1. **Nothing was deleted.** The heavier shape survives as `benchBuildRealisticPagedQuery`,
   asserting nothing, still measured, still worse. It's what an application actually builds.
2. **The narrowing follows the spec's wording, not the direction of a nicer result.** A column list
   is not a condition.

If I'd only kept the narrow subject, this change would be indistinguishable from gaming it.

## Two changes, one of them provable without timing

**Driver resolved once per builder.** The saving is ~0.13µs per identifier — genuinely inside
end-to-end noise. So I pinned it by **counting** instead: 7→1 lookups for the five-condition query,
13→1 for the realistic one. Exact, deterministic, machine-independent. The test fails with
`13 is not identical to 1` if the lookup creeps back into `quote()`, where a timing assertion for a
sub-microsecond saving would be flaky and would train people to ignore it.

That feels like a reusable pattern: when a real improvement is smaller than the noise floor, assert
the *mechanism* rather than the *duration*.

**Concatenation over `sprintf`** — a third the cost, once per condition.

Result: **14.430 → 12.979µs (−10.1%)** on the corrected subject, 24.690 → 22.168µs on the heavy one.

## Why I stopped, rather than pushing to 10µs

**NFR-03 is still not met** — ~30% over. I could have kept going. I didn't, because the two
instruments disagree by more than the remaining overage:

| instrument | five-condition SELECT |
|---|---|
| phpbench (declared instrument) | 12.979µs |
| plain in-process loop | 9.246µs |

My first guess was phpbench harness overhead. Checked it — an empty subject costs **0.079µs**, so
no. The ~3.8µs disagreement is real and exceeds the ~3.0µs overage, which means **whether NFR-03
passes today depends on which instrument you use.**

That makes the next question methodological, not algorithmic. NFR-06 fixes the methodology and the
reference machine (a Ryzen 7 5800X, not this i7); item **7.1** implements it. Tuning another 3µs
against an instrument that may not be authoritative is optimising the measurement.

The tempting move was to report the 9.246µs figure and declare NFR-03 met. That's the same species
of error as lowering a threshold, and every prior ADR here has refused it.

## An environment note worth recording

Mid-item the machine became heavily loaded — phpbench rstdev hit 42% and it retried endlessly.
Numbers taken then (15.3µs) were meaningless. I discarded them and re-measured once it was quiet
(all rstdev < 3%). Worth noting because the noisy readings looked precise enough to quote.

## Bar

662 tests / 1434 assertions green. PHPStan max clean, deptrac 0/0.

## Milestone 4

4.1–4.6 done. NFR-03's residual sits with **7.1**, alongside NFR-01's absolute half and the nightly
regression harness — that item is accumulating everything that needs a trustworthy reference
measurement.
