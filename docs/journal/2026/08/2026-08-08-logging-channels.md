# 2026-08-08 — The idiomatic enum was too slow, and one of my own arguments was dead

Roadmap item **12.3**, the logging channels. Route `standard / medium` → Opus 5, the session model.
No mismatch — second time this milestone.

## Two measurements before a line was written

The requirement names a `Level` enum "with an ordering". The idiomatic way to give an enum an
ordering is `match ($this)`, and NFR-14 budgets a level-suppressed record at 0.5 µs in total. Those
two facts turn out to be in tension, which only measuring finds:

| shape (OPcache off, as NFR-06 pins it) | µs |
|---|---|
| `tryFrom()` alone | 0.147 |
| `tryFrom()` + `match ($this)` for the rank | 0.564 |
| `tryFrom()` + a const-map lookup on the backing value | 0.246 |
| a **static** map lookup, no case hydrated | 0.216 |

End to end through a real decorator: **1.089 µs** for the enum-hydrating shape — 218% of the
budget — against **0.435 µs** for one that compares integers. So `Level::rankOf()` exists, taking
whatever PSR-3 handed `log()` and answering *"is this a level"* and *"how severe"* in one array
lookup. `AbstractLogger::debug()` passes a **string**, so a decorator that hydrated a case would be
paying `tryFrom()` for a value it only wants an integer from.

The enum is still the vocabulary — it is just not in the hot path. And its cases are PSR-3's own
`LogLevel::*` constants rather than copies of their text (verified legal on the 8.1 floor), so the
two cannot drift; a literal would drift silently, because a wrong level string is still a valid
string.

## The duplicate that would have happened

`Logger` had a private severity map. Nothing was wrong with that while nothing else needed an
ordering — and the moment `LevelFilteredLogger` arrived it would have been two copies of one rule.
That is not a hypothetical here: it is item 10.5's finding, where ADR-0015's identifier allowlist
lived in two builders until the newer, weaker copy was noticed, with both suites green. So the map
moved to `Level` and `Logger` reads it. Its constructor widened to `Level|string`, which is
additive.

PHPStan max was the useful adversary while doing it. My first attempt kept a `(string) $level` cast
and it was right to refuse: after a runtime check I *knew* the value was a string, but the code did
not say so. An assertion annotation did not narrow it either. The version that works is three plain
narrowing steps — which reads better than what I started with, and the proof is in the code rather
than in a suppression.

## One of my own arguments turned out to be dead

Eleven defects planted, ten caught. The eleventh is the one worth the entry.

PSR-3 ships a `NullLogger`, the obvious implementation of a disabled channel. I probed it, found it
**accepts an invalid level without complaint**, and concluded that a disabled channel therefore
needed an empty `MultiLogger` — which validates and discards — or else switching a channel off would
switch a check off with it. That argument went into the ADR, the class docblock and the factory
docblock.

Then I planted the substitution. **The suite stayed green.** The channel is a
`LevelFilteredLogger` wrapping the sink, and the filter validates *before* the sink is reached, so
the sink's own strictness is unobservable through the factory. The guarantee I wanted is real — a
disabled channel does refuse an unknown level — but it comes from validating before filtering, not
from the choice of sink.

The code stayed; the justification changed. The empty composite is now kept on a smaller claim (one
sink type in both branches reads more plainly than a branch that changes the class), and
`MultiLogger`'s own validation is asserted directly against `MultiLogger`, where it *is* observable.
Item 12.1 did the same thing in the other direction a day earlier: a guard a probe proved inert was
deleted. Same lesson, opposite remedy — **a planted defect that passes is telling you something
about your reasoning, not only about your tests.**

Two of the eleven "misses" in the first round were my *script's* fault, not the code's: one plant
reordered two `if`s while keeping both, so it was behaviourally identical to the original, and
another appended a line, which made my "the original text must be absent afterwards" check
impossible to satisfy. Both were re-done and both were caught. The check that catches a plant which
never applied is worth having — items 11.1 and 11.2 each lost time to a `sed` that matched nothing —
but an *additive* plant needs the inserted text asserted instead.

## The asymmetry I had to decide

`Logger` deliberately swallows its own write failures (ADR-0029): a logger that throws while an
exception handler is using it turns a handled failure into a fatal one. Should the composite do the
same?

No — and the boundary is ownership of a destination. A leaf owns one, can describe a failure in its
own terms, and must not escalate. A composite owns none, so a swallow there would make a fan-out
where *every* delegate failed indistinguishable from one that worked. In the normal wiring nothing
is thrown anyway, because the leaves already refuse to escalate — probed: a `Logger` whose file
became unwritable after construction returns silently. What remains is the third-party logger, and
hiding its failure would be this library deciding on someone else's behalf that a lost log does not
matter.

Only the first failure survives. PHP has no suppressed-exception mechanism, which ADR-0016 recorded
when a failing rollback had to lose either its own error or the original cause. A test pins that the
second failure is *not* chained, so the trade is stated rather than discovered.

## And nothing to amend

FR-41, FR-42, T-12 and NFR-14 were implementable exactly as written. That is the first item in six
where the spec needed no correction in the same PR — 10.4's SELECT-only builder, 11.1's unbounded
timeout, 10.10's unsatisfiable ratio and 11.4's tag collision all did. Worth writing down, because
an absence leaves no trace otherwise.

One honest note on the budget being gated: locally the **control subject** — a bare
`AbstractLogger::debug()` on a do-nothing sink, included in the benchmark on item 10.12's method —
is about **60% of the measured subject**. Most of what NFR-14 bounds is PHP's own method dispatch,
not this library's filtering. It is still the right number to gate, because it is what a consumer
pays; but a future breach should be read as "the dispatch or the runner moved" before "the filter
got slower".

## Lesson

A planted defect that leaves the suite green is not always a hole in the tests. Sometimes the
implementation is fine, the tests are fine, and the thing that is wrong is the *reason* recorded for
the implementation — which no gate can catch, and which only planting the alternative reveals.
