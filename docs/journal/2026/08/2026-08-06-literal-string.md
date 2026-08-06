# 2026-08-06 — The guarantee the previous item wrote about and did not build

Roadmap item **10.7**, filed two sessions ago by the review of item 10.1 and taken now at the
maintainer's direction, ahead of 10.2, because `Repository` and `TableGateway` (10.3–10.4) are
the callers it exists for and should inherit it before they are written.

## What the item was

ADR-0039 wrote *"a type-level guarantee catches what a string never announces; a runtime
heuristic cannot"* — and shipped a constructor taking a plain `string`. Its Alternatives section
rejected a **runtime** placeholder-counting assertion, correctly, and never considered the
**static** option that this repository already has a gate for: PHPStan's `literal-string`, at the
`max` level `phpstan.neon` pins.

## The design change the project's own rules forced

The filed plan said "annotate the constructor". That does not survive contact with the escape
hatch: `composed()` has to accept runtime-assembled text, and if the annotated constructor were
public, `composed()` could only reach it by overriding the analyser — which this project forbids
outright (no ignore comments, no inline type overrides, stated in PHPStan's own output here).

A guarantee that must be suppressed to implement is not a guarantee. So the **constructor is
private**, and the class exposes exactly three named entry points, each stating in its own
signature what it promises: `literal()` (PHPStan checks), `fromQueryBuilder()` (ADR-0015's
allowlist checks), `composed()` (a human checks, at review). No suppression anywhere.

`fromQueryBuilder()` takes the **builder object**, not its `toSql()` string, for one specific
reason: it keeps `composed()`'s in-library usage count at **zero**. `grep composed(` is supposed
to return the places where a person had to think; two routine library calls in that list would
be two too many. The alternative — `QueryBuilder` calling `composed()` itself, which would have
been *truthful*, its text genuinely is composed — was rejected on exactly that.

## Proving it, since the whole item is a claim about a static property

Four mistakes planted, four caught: interpolation, concatenation, `sprintf()`, and `implode()`
into `literal()`. And four legitimate shapes confirmed still passing, of which the third was the
one worth checking before committing to any of this:

```
literal('SELECT c FROM stock WHERE code[1, ?] = ?', [$len, $code])    -- passes
```

Hand-written dialect SQL with a positional substring predicate: **FR-33's entire reason to
exist**, and `literal-string` permits it. Being hand-written was never the problem; being
assembled from values is. Had that row failed, the item would have had to be redesigned rather
than shipped, which is why it was probed rather than assumed.

The runtime tests assert the **mechanism** instead, per ADR-0027: that the annotation is present,
that the constructor is still private, and that the public static surface is exactly those three
names — the last pinned so a fourth entry point is a deliberate act with a test to update.
Deleting the annotation leaves every other test in the suite green, which is precisely why that
assertion exists.

## Two costs, named rather than smoothed

**A second breaking change to the same class in two PRs.** Item 10.1 retired `(string, array)`;
this retires `new SqlStatement(...)`. 51 call sites moved, mechanically, and pre-1.0 permits
both — but one PR should have shipped both, and the reason it did not is that ADR-0039 never
weighed the static alternative. That is a process finding, not a code one, and ADR-0041 says so
in its own Consequences.

**And `composed()` remains exactly as unchecked as everything was before it.** A consumer who
calls it carelessly is no safer. The gate moves that failure from invisible to reviewable, which
is worth doing and is not the same as preventing it.

## A small thing found only by running it

PHPStan parses an ignore-comment tag **wherever it appears, including inside prose**, and fails
the analysis on it. The class docblock originally explained the design by naming the tag it does
not need; that sentence had to be rewritten to describe the tag instead of spelling it.

## Lesson

When an ADR says what the right mechanism would be and then ships something else, that sentence
is the finding — read your own Alternatives sections as a checklist of what you decided not to
verify.
