# 2026-08-07 — The simple design won, and the namespace cost more than the method call

Roadmap item **10.11**, `step:optimize`, route `fast / medium`. Run under `/eados optimize`'s
measure-first discipline: a number before, one change, a number after, both recorded.

## What the number actually was

Item 10.10 attributed **+55.8 µs per 100 rows** to `RowNormalizer` — 27% of NFR-09's gateway
overhead and the only part that is not hydration. Re-measured here in isolated processes it
reproduces at **+52.3 µs**, and the useful reframing is per *value* rather than per row:
**281 ns for each of the 186 string values** in a 100-row batch, spent re-deriving four decisions
that belong to an immutable policy. Dispatch, not work.

## Four designs, measured before choosing

| design | µs / 100 rows |
|---|---|
| inline trim loop (floor) | 42.8 |
| the class as it stood | 95.2 |
| precomputed `Closure` per policy | 70.4 |
| general pipeline inlined behind locals | 74.5 |
| one loop, per-value ternary | 59.0 |
| **hoisted flag + one fast path** | **51.5** |

**Both rewrite-shaped candidates were slower than the simple one.** A closure recovers half the
dispatch because a closure call is still a call; inlining the pipeline pays four branches per
value. The design that won is the one the roadmap item named first, and it is also the smallest:
one constructor-computed boolean, one guarded loop, the general pipeline untouched.

The more readable formulation — a single loop with a ternary — lost by 7.4 µs. Small enough to
write down rather than bury: someone may reasonably want the single loop back, and now they know
the price.

## The finding I did not ship

The chosen design measured **51.5 µs** in my scratch profiler and **65.2 µs** as the real class.
Same loop, same call. Fourteen microseconds unaccounted for is not something to average away, so I
built a two-class probe: identical bodies in one namespace, one calling `is_string()`/`trim()`, the
other `\is_string()`/`\trim()`. **65.0 vs 51.4 µs.**

It is PHP's namespace fallback: inside a namespace, `trim()` is resolved by trying
`D4np\Utils\Persistence\trim` first and the global `trim` second — and NFR-06 pins the benchmark
interpreter with **OPcache off**, which is exactly where that second lookup is not optimized away.
Checked rather than assumed: `opcache.enable_cli=0` on this box, `opcache_get_status()` inactive.
**13.6 µs per 100 rows, ~36 ns per call, 372 calls per batch.**

Two characters in this one file would have taken the number. I filed **item 10.12** instead. The
reason is not caution, it is that a lone `\trim()` in a repository that calls internal functions
unqualified everywhere reads as a style slip — the next tidy-up deletes it, the 13.6 µs comes back,
and every test stays green. Holding it needs the repo-wide `native_function_invocation` rule (a
risky-rule decision touching every file) or a mechanism test pinning two characters, which is
theatre. So: measurement recorded, decision filed, one change shipped.

## Why this item has two tests instead of one

The fast path is output-identical to the general path — that is the whole design. Which means a
behavioural suite **cannot see it stop working**. I proved that rather than asserting it: planting
`trimOnly = false`, the optimization silently ceasing to exist, leaves the entire 16-combination
differential matrix **green** and fails exactly two rows of the reflection truth-table assertion.

So the matrix guards correctness (a condition that fires one combination too widely) and the
mechanism assertion guards the *existence of the optimization* (ADR-0027's rule, and the same
failure class as item 10.8's mutation gate that ran on nothing and item 2.7's coverage gate with no
driver). Five defects planted, five caught.

## Then CI refuted the premise

All fifteen checks passed, and the benchmark job's numbers said something I had not expected:
`benchNormalizeHundredRows` **22.423 µs** against the inline floor's **19.663** — a remaining
overhead of **+2.760 µs**, not the +22.3 this box measures — and **NFR-09's ratio unchanged at
1.73×**, exactly what `master` reports.

My PR body had predicted 1.73× → ~1.6×. That was wrong, and it was wrong for a reason worth more
than the item: **item 10.10's "+55.8 µs / 27% of NFR-09's overhead" was measured on this Windows
development machine**, and on CI the same component accounts for **4.6%** of the gateway overhead.
The proportion that made this item worth prioritizing does not reproduce in the environment the
budget is defined in. ADR-0046 had already recorded NFR-09 five times at 1.71–1.85× on CI, so a
2.8 µs change in a 141.75 µs subject was never resolvable by that gate — the noise band is wider
than the whole component.

I kept the change: it does strictly less work per value, it costs one boolean and one guarded loop,
and it is on the path of every gateway read. But the justification in the record is now the small
honest number, not the large local one. And the saving on CI hardware is **unmeasured** — the
subject is new, so the same-runner harness had nothing to compare against; getting that number
would need a throwaway PR carrying the benchmark against the old implementation, which I did not
open. Named, not filled with an estimate.

## Lesson

**Measure the candidate in its real home** — twice over, and the second one is the expensive
lesson. A variant in a scratch file and the same code inside its namespace differed by 26%. And a
component measured on the dev box looked six times more important than it is on the runner where
the budget lives. Item 10.10's lesson was that a decomposition is itself a benchmark; the turn
after that is that **an attribution inherits the machine it was taken on**, so a proportion used to
prioritize work has to be re-taken in the reference environment before it is trusted.

The original lesson still stands as written: A variant benchmarked in a scratch file and the same
code inside its namespace differed by 26%, and the gap was not noise — it was a language feature
the scratch file did not exercise. Item 10.10's lesson was that a decomposition is itself a
benchmark; this is the next turn of the same screw: a *candidate* is itself a benchmark, and the
environment that makes it fast has to be the one it will actually live in.
