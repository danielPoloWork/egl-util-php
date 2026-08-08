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

The enum is still the vocabulary — it is just not in the hot path. On CI the suppressed record
measures **0.081 µs against the 0.5 µs ceiling**, and this box had said 0.404 µs: a ~5× overstatement,
worse than the 2–3× I have come to expect from it.

## The 8.1 cell caught a claim I had no right to make

I wanted the cases backed by PSR-3's own constants, so the enum and PSR-3 could not disagree at all:

```php
case Emergency = LogLevel::EMERGENCY;
```

I probed it, it worked, and I wrote *"verified legal on the 8.1 floor"* — in the ADR, the class
docblock and the commit message. **PHP 8.1 refuses it**: *"Enum case value must be compile-time
evaluatable."* 8.2 and 8.3 accept it, and 8.3 is the only runtime on this machine, so the probe had
established something about 8.3 and I had reported it about 8.1. The matrix cell rejected the file in
sixteen seconds.

Same failure class as item 10.10's attribution and item 10.11's µs figures, both taken on the wrong
machine, and now with a sharper edge: those were *quantitative* errors, where the number was wrong but
the direction survived. This one was **categorical** — the feature does not exist on the floor, and no
amount of margin would have saved it. **A probe inherits the runtime it ran on. A claim about a
version floor can only be made by something that runs on that floor.**

The cases are literals now, and `LevelTest`'s both-directions assertion is the whole drift guarantee
— weaker in timing (a test run rather than a compile), identical in effect. The `RANK` map still
references `LogLevel::*`, because an ordinary class constant is evaluated on first access rather than
at compile time, which is the distinction the restriction turns on.

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

One honest note on the budget being gated. The **control subject** — a bare `AbstractLogger::debug()`
on a do-nothing sink, included on item 10.12's method — measures **0.046 µs against the subject's
0.081**, so **57% of what NFR-14 bounds is PHP's own method dispatch**, not this library's filtering.
Locally the proportion was 60%, so unlike the absolute numbers it reproduced. It is still the right
number to gate, because it is what a consumer pays; but a future breach should be read as "the dispatch
or the runner moved" before "the filter got slower". The control earned its place twice over here: it
is also what let me state that proportion at all.

The benchmark job is red, and not on my subject: `benchDispatchLastOfFiftyRoutes` at 5.188 µs against
NFR-11's 5 µs — **item 11.7, already open on master**. Worth one note beyond "not mine": item 11.5
measured that same subject at 6.874–7.145 µs, and it is 5.188 here on unchanged code. The overage is
real and the *size* of it is not stable, which is the kind of evidence 11.7's decision will want.

## Lesson

Two, and they are the same shape from opposite ends.

A planted defect that leaves the suite green is not always a hole in the tests: sometimes the code and
the tests are both right, and what is wrong is the *reason* recorded for the code — which no gate can
catch and only planting the alternative reveals.

And a probe that passes is not evidence about a runtime it did not run on. I have written that lesson
twice before, about numbers taken on this machine. This time it was not a number but a language
feature, where being wrong is not an overstatement but a build failure — which is the cheaper way to
be wrong, and only because the matrix cell was there to say so.
