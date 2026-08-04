# 2026-08-04 — Measuring what was reachable before deciding what to build

Roadmap item **3.7**, run under `/eados optimize` — the measure-first procedure, which refuses to
touch code without a numeric target and refuses to accept a change without a before/after.

## The target, and the question worth asking

NFR-01: hydration of 10 scalar props ≤ 5 µs and ≤ 3× manual construction. Baseline **15.40×**
(14.155 µs), recorded at item 3.5 and left open on purpose by ADR-0011.

The useful question wasn't "is the hydrator slow" — item 3.5 already answered that. It was
**"what is actually reachable?"** So before writing anything I profiled the existing path and then
prototyped every candidate, including the ones I expected to lose.

Stage profile of the 13.86 µs: `parameterNames()` **rebuilt on every single call** (1.13 µs), the
`in_array` unknown-key scan (2.16 µs), the per-parameter loop with a `coerce()` method call each
(3.22 µs), named-argument spread at construction (1.49 µs), and the rest in call-chain overhead.

Two profile results contradicted plausible intuitions, and acting on either would have made things
worse:

- Iterating `ParameterMetadata` **objects** is *faster* than iterating flattened plain arrays
  (0.63 vs 1.59 µs). The obvious "flatten the metadata for speed" is a pessimisation.
- Named-argument spread costs about **twice** a positional call (1.49 vs 0.80 µs).

## The measurement that decided the design

| approach | µs | ratio |
|---|---|---|
| interpreted (as-is) | 13.86 | 16.6× |
| interpreted, every avoidable waste removed | 3.97 | 4.80× |
| one closure per parameter (no `eval`) | 3.31 | 4.00× |
| generated closure | 1.93 | 2.28× |

Row two is the one that mattered: it is the *ceiling* of "just tighten the loop" — memoised names,
hash-based key check, positional args, lazy error paths, all of it — and it lands at 4.80×, still
over budget. And that prototype was optimistic: no path building, no exception construction, none
of the nested-DTO branches production needs.

So: **NFR-01's budget is not reachable by tuning an interpreted reflection-driven loop in PHP.**
That's a measured conclusion, and it's the only thing that justifies a mechanism as heavy as code
generation. Without it I'd have been arguing from taste.

## Stopping to ask

Three defensible paths existed — compile and meet the budget, tighten and miss it honestly, or
treat the measurement as evidence the budget itself is wrong — and picking among them isn't mine to
do. Put to the maintainer with the numbers; the compiled path was chosen.

I also measured the thing that could have invalidated it in production: `eval`'d code isn't cached
by OPcache, so under PHP-FPM the closure is rebuilt every request. Compile 66.9 µs, saving 12.3 µs
per hydration → **break-even ≈5.5 hydrations of one class per process**. Bulk mapping clears that
instantly; a request hydrating one or two objects is marginally slower. Worth knowing before
shipping, not after.

## Keeping two implementations honest

The real risk isn't speed, it's **drift** — two implementations of one behavior that slowly
disagree. Mitigations, in order of how much they actually buy:

1. **Eligibility is tiny.** Only all-scalar constructors with no defaults. Everything else — nested
   DTOs, collections, enums, unions, variadics, defaults — stays on the interpreter, untouched.
2. **`HydrationParityTest` compares the two paths against *each other***, not against expectations
   I wrote. Constructed state, exception class, message, path. If they diverge anywhere, it fails.
3. **A documented off switch.** `new HydrationCompiler(false)` declines everything. That's what the
   parity test uses, and it's the honest answer for anyone who doesn't want `eval` in their process.

Proved non-vacuous with three planted divergences, each caught, each reverted:

- Dropped `float`'s int-widening → only the `int widening into float` case failed.
- Moved the unknown-key scan after the parameter checks → only `unknown key AND missing key`
  failed, which is the case that distinguishes reporting order.
- Made *nothing* eligible → `testTheFixtureIsActuallyOnTheCompiledPath` failed while every parity
  case still passed. That third one is the important one: without that guard the whole suite could
  be comparing the interpreter with itself, green and proving nothing.

## A correctness trap I set for myself and then caught

My first prototype hit 2.28× partly by skipping the unknown-key scan when `count($data)` matched
the parameter count. That's **wrong**: a payload carrying one undeclared key *and* missing one
declared key has the expected count, so the shortcut skips the scan and reports the missing key —
where today's interpreter reports the unknown one. I'd already written that exact case into the
parity suite, so when I moved to production code without the shortcut and came in at 3.47×, the
fix had to be something sound rather than something fast. `array_diff_key` — one C call, correct
order preserved — got it to 2.74%.

Worth noting the sequence: production first measured **3.47×**, *over budget*, because the closure
was only part of it and the naive scan was the rest. Profiling again rather than assuming the
design had failed is what found the real cost.

## Result

**15.40× → 2.74×** (14.155 → 2.511 µs), stable across two independent runs (2.74× / 2.75×). Both
halves of NFR-01 met; `bench_ratio_gate.py` exits 0 against `--max-ratio 3` for the first time.
NFR-04 improved for free: 10 000 hydrations 149.7 ms → 37.8 ms, its 16 MiB assertion still passing.

Suite 258 tests / 491 assertions green (up from 243 — the parity cases). PHPStan max clean,
deptrac 0 violations / 0 uncovered, consistency lint OK.

**The honest limit, stated plainly:** only the eligible shape is fast. A DTO with a nested DTO or a
`Collection` is still ~14 µs. This meets the spec's budget for the spec's stated shape; it does not
make every DTO fast, and the ADR, the class docblock and the benchmark record all say so rather
than letting a headline number imply otherwise.

## State of Milestone 3

3.1–3.7 all done. Milestone 3 is complete.
