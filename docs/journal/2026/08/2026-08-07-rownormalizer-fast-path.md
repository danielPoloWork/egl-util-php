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

## Lesson

**Measure the candidate in its real home.** A variant benchmarked in a scratch file and the same
code inside its namespace differed by 26%, and the gap was not noise — it was a language feature
the scratch file did not exercise. Item 10.10's lesson was that a decomposition is itself a
benchmark; this is the next turn of the same screw: a *candidate* is itself a benchmark, and the
environment that makes it fast has to be the one it will actually live in.
