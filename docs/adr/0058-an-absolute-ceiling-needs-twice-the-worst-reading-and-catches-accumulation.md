# ADR-0058: An absolute ceiling needs twice the worst reading, and it catches accumulation — not steps

- **Status:** Accepted
- **Date:** 2026-08-08
- **Deciders:** maintainer (`@danielPoloWork`) — **authority explicitly delegated** for both
  decisions ("decidi tu su 11.6 e 11.7"), which is what makes this ADR legal to write at all;
  agent acting as tech-lead
- **Related:** ROADMAP items **11.6** and **11.7** (the two decisions this settles) · spec
  **NFR-06**, **NFR-10**, **NFR-11** ·
  [ADR-0030](0030-same-runner-ab-because-a-stored-baseline-cannot-carry-a-10-percent-gate.md)
  (the two-gate split this puts on a numeric footing) ·
  [ADR-0045](0045-exclude-io-bound-and-memory-hard-subjects-from-the-relative-gate.md)
  (the same reasoning applied to the *relative* gate; this is its absolute-side counterpart) ·
  [ADR-0057](0057-invalidate-the-run-when-a-control-subject-moves-past-threshold.md) (the
  control-subject mechanism whose existence lets the relative gate carry more of the load here) ·
  [ADR-0050](0050-classify-the-miss-and-keep-the-router-a-table.md) (the router's linear scan —
  a non-goal this ADR *confirms* rather than reverses) ·
  [ADR-0024](0024-assert-the-work-factor-not-the-wall-clock.md) (NFR-05's precedent: the spec's
  number and what CI can assert are allowed to be different things) ·
  [ADR-0040](0040-install-the-mutation-tester-outside-the-graph-and-keep-nfr07s-own-number.md)
  (the rule that reserved these numbers for the maintainer until now)

## Context

Two roadmap items were open on the same complaint: an **absolute** benchmark ceiling firing on code
nobody had changed. They were filed separately because they looked like different problems. Put
side by side against every other gated budget in the repository, they turn out to be one problem
and *two different diagnoses* — which is why one ADR settles both.

The full picture, worst reading observed per subject against its ceiling (each figure from a CI run
on code that did not touch the subject; sources in References):

| subject | worst observed | ceiling | headroom | |
|---|---|---|---|---|
| `HttpBench::benchDispatchLastOfFiftyRoutes` | 7.145 µs | 5 | **0.70×** | breached |
| `FileSequenceBench::benchSequenceNext` | 208.768 µs | 200 | **0.96×** | breached |
| `QueryBuilderBench::benchBuildFiveConditionSelect` | 3.763 µs | 10 | 2.66× | never fired |
| `ContainerBench::benchFirstAutowiredResolve` | 7.221 µs | 30 | 4.15× | never fired |
| `HttpBench::benchEnvelopeBuild` | 0.389 µs | 2 | 5.14× | never fired |
| `LoggingBench::benchSuppressedRecord` | 0.097 µs | 0.5 | 5.15× | never fired |
| `HydrationBench::benchHydrateWarm` | 0.958 µs | 5 | 5.22× | never fired |
| `CsvBench::benchWriteTenThousandByTen` | 20 348 µs | 150 000 | 7.37× | never fired |
| `CryptoBench::benchCryptoRoundTrip` | 6.995 µs | 60 | 8.58× | never fired |
| `ContainerBench::benchWarmSingletonResolve` | 0.060 µs | 2 | 33.33× | never fired |

**There is a gap in that column and nothing lives in it.** Two subjects sit below 1×; the next
value up is 2.66×; the band between is empty. Whatever else is true, this repository's own history
says a ceiling within ~2× of a subject's worst reading is inside the noise envelope, and a ceiling
above it is not. The two open items are the only two budgets ever set inside that envelope.

**The measurements are trustworthy, and that is worth establishing before blaming them.** Within a
single CI job, phpbench's own agreement is excellent: the router's last-of-50 subject reports
**±0.60%** spread, `benchHydrateWarm` ±1.95%, `benchCryptoRoundTrip` ±1.02%, and even
`benchSequenceNext` converges to ±2.31% after its retries. The instability is entirely
**cross-run** — 1.51× peak-to-peak for the router (4.735–7.145 µs across 11 readings), 2.77× for
the sequence counter (75.503–208.768 µs across 17). This is precisely the split ADR-0030 measured
and built the same-runner A/B around; what neither ADR-0030 nor ADR-0045 did was ask what that
split implies for the *absolute* ceilings sitting alongside it.

### The two diagnoses are not the same

- **Router (11.7): the ceiling was wrong about the code.** The subject exceeds 5 µs in **10 of 11**
  readings; its median is ~5.6 µs. Cost scales exactly as ADR-0050's design predicts — 0.674 µs
  for the first of fifty routes, 5.581 µs for the last, ≈0.10 µs per failed `preg_match()` across
  the 49 misses a worst-case dispatch pays. This is not noise sitting on a compliant number; it is
  a real cost the spec's figure never measured.
- **Sequence counter (11.6): the ceiling is right about the code and unenforceable on this
  hardware.** Typical readings are 75–190 µs, comfortably inside 200. Exactly **one** reading of
  seventeen crossed it (208.768, +4%), and the same commit re-run on the same runner passed. The
  code respects the requirement; CI cannot prove it does, because the noise band is wider than the
  margin.

## Decision

### D1 — An absolute ceiling must sit at **≥ 2× the worst observed reading**

Derived from the table above, not chosen: every ceiling this repository has never seen fire sits at
≥ 2.66×, both that have fired sit below 1×, and the observed cross-run spread reaches 2.77×. A
factor of 2 is the round number at the conservative edge of that evidence. A ceiling set tighter is
a gate that will eventually fail on unchanged code, and **a gate that fails on unchanged code
teaches people to re-run it** — item 11.6's own filing named that as the cost to price.

### D2 — The absolute ceiling's job is **accumulation**, not single-step regressions

This is what makes D1 affordable, and it is a re-reading of ADR-0030 §2 rather than a new claim.
That ADR justified having two gates because *"twenty commits at +9% each pass every relative check
and still double the runtime."* Accumulation is the failure the ceiling exists for. A single-step
regression is the **relative** gate's job, and it does that far better than any ceiling could: at
±0.60% within-run agreement on a same-runner A/B, a 10% step is sixteen times its own noise floor —
and since ADR-0057 the relative gate also knows when to disqualify itself instead of guessing.

Read that way, the two gates stop competing for the same headroom. The ceiling can be loose enough
never to lie, because catching a 2× jump was never its assignment.

### D3 — Spec targets and CI ceilings are stated as two separate numbers

A consequence of D1 + D2, and not a new pattern: NFR-05 already works this way (ADR-0024 — the
spec's 50–200 ms range is not CI-gated at all; the *work factor* is asserted instead), and
`bench_budget_gate.py` already prints *"this runner is not spec NFR-06's reference machine"* on
every run. What changes is that the spec now says so in the requirement text rather than leaving it
to a tool's footnote.

**NFR-11 — router dispatch.** Design target **≤ 10 µs** on the reference machine (was ≤ 5 µs, a
figure no measurement supported); CI ceiling **≤ 15 µs**. The code measures 4.7–7.1 µs on shared
hardware, so it clears the target with room, and 15 µs is 2.10× the worst reading.

**NFR-10 — `FileSequence::next()`.** Design target **≤ 200 µs, unchanged** — the code respects it
and nothing measured says otherwise; CI ceiling **≤ 450 µs**, which is 2.16× the worst reading.
`ApiEnvelope`'s ≤ 2 µs clause in NFR-11 is untouched: at 5.14× headroom it was never in question.

### D4 — The router keeps its linear scan; ADR-0050's non-goal is confirmed, not reversed

Item 11.7 offered *"add a cache or an index to `Router`, reversing ADR-0050's stated non-goal,
which named 'a 50-route table matches in microseconds' as the reason no cache was needed, a claim
this measurement corrects."* **The measurement does not correct that claim — it confirms it.**
5.6 µs *is* microseconds. What was wrong was the spec's 5 µs ceiling, not the sentence justifying
the design.

And the engineering case against optimizing is stronger than the bookkeeping one: a worst-case
dispatch costs ~5.6 µs against an HTTP request measured in **milliseconds** — roughly 0.1% of a
5 ms request. An index would add a build step, a cache invalidation question and a second code path
to test, to reclaim a tenth of a percent. Item 4.6 is the precedent that matters here in reverse:
there, profiling before optimizing showed the named hypothesis was not the cost. Here, measuring
before optimizing shows the cost is not worth removing.

**Said plainly, because it is the uncomfortable half:** raising a ceiling that a subject breaches
is exactly what "tuning the benchmark until it passes" looks like from the outside. The distinction
this ADR rests on is that the *code's* cost was measured first, found to be a documented and
deliberate design property, and judged acceptable on its own merits — and only then was the number
that mis-described it corrected. Had the router measured 500 µs, D4 would have gone the other way.

## Alternatives Considered

1. **(11.6) Exclude `benchSequenceNext` from the absolute gate too** — rejected. The item required
   that this option be paired with *"say plainly that NFR-10 is then unenforced,"* and that is the
   reason not to take it: `FileSequence` is the one component here whose cost *is* its
   lock-contention behaviour, and leaving it with no performance gate at all after ADR-0045 already
   removed its relative one would mean an added `fsync` or a lock-retry loop — a several-hundred-µs
   change — landing unremarked. A 450 µs ceiling catches that class; an absent ceiling catches
   nothing.
2. **(11.6) Measure the subject as a median of N runs** — rejected on cost, the same ground
   ADR-0045 rejected best-of-N pairs: the absolute gate reads one report, so a median across runs
   means N benchmark jobs per PR, paid by every contributor, to sharpen one subject. D1 + D2 get an
   honest gate for free.
3. **(11.6) Keep 200 µs and accept a job that fails a few runs in twenty** — rejected: this is
   precisely the "teaches people to re-run it" failure the item filed itself to avoid, and ADR-0045
   already rejected the identical reasoning on the relative side.
4. **(11.7) Add a cache or index to `Router`** — rejected (D4): ~0.1% of a request, against real
   added complexity, for a design property ADR-0050 chose deliberately and the measurement
   confirms.
5. **(11.7) Accept the gap and ship the benchmark job red** — rejected, and this session produced
   the evidence against it: with the job failing at its absolute-budget step, GitHub Actions
   skipped every later step, so **item 12.6's regression-gate logic never executed on CI at all**
   (recorded in ADR-0057 and on item 11.7). A permanently red gate does not merely annoy; it
   silently disables everything downstream of it in the same job.
6. **Set the router's ceiling at 10 µs instead of 15** — considered seriously, and rejected on D2.
   10 µs is 1.40× the worst reading, which keeps it inside the noise envelope D1 measured; its only
   advantage is failing a doubling of the median (11.2 µs), and under D2 that is the relative gate's
   job, done with ±0.60% precision instead of a ceiling's blunt one.
7. **A single blended rule — one ceiling formula applied mechanically to every subject** —
   rejected as premature: D1 is a floor on headroom, not a formula for choosing ceilings. The
   healthy budgets range from 2.66× to 33×, mostly because they were set from a spec's intent
   rather than from a measurement, and there is no evidence that normalizing them would catch
   anything they currently miss.

## Consequences

**Easier:** `ci.yml`'s `benchmark` job can go green, which — per Alternative 5 — is what lets every
step *after* the absolute-budget gate run at all, including ADR-0057's control invalidation. Two
open decision items close, and Milestone 11 closes with them. Future budgets have a stated,
data-derived rule (D1) to be set against rather than a plausible-sounding guess, and the criterion
for when a ceiling is unsatisfiable is written down instead of rediscovered by a red PR.

**Harder / accepted costs:** both ceilings are now blunter instruments — the router's tolerates a
2.7× regression before firing, the counter's a 2.2× one. That is the explicit trade in D2, and it
is only safe *because* the relative gate is precise and, since ADR-0057, self-aware; if the
relative gate were ever dropped, these ceilings would not substitute for it. The spec now carries
two numbers per affected axis, which a reader must not conflate: the target describes the code, the
ceiling describes what shared hardware can prove about it.

**Watch for:** a subject whose worst reading climbs to within 2× of its ceiling is a subject whose
ceiling is about to start lying — under D1 that is the moment to re-measure, not the moment to
re-run. `benchBuildFiveConditionSelect` at 2.66× is the closest to that line today.

## References

- ROADMAP items **11.6** and **11.7** (the filings, with their own recorded evidence), item 11.4
  (11.6's originating failure), item 11.5 and ADR-0053 (11.7's), item 12.4 (the runner-wide
  slowdown that produced several of the readings above), item 12.6 / ADR-0057 (the relative gate's
  self-invalidation, which D2 leans on)
- CI runs `31248786826`, `31261955273`, `31268325327`, `31273278959`, `31277202139` on `master`,
  plus the PR runs recorded in items 12.4–12.6 — the source of every figure in the Context table
- ADR-0030 §2 (the two-gate split and the *"twenty commits at +9%"* argument D2 re-reads),
  ADR-0045 (the relative-gate counterpart), ADR-0024 (target-versus-assertion precedent),
  ADR-0050 (the router's linear scan)
