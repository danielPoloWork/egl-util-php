# 2026-08-06 — Two locks are one too many

Roadmap item **9.5**, fifth in Milestone 9. Route `standard / medium`, session model matched
— **the first item in this milestone with no route mismatch to record**.

## The item was filed for a counter; the problem was a primitive

FR-32 reads like a small class: keep a number in a file, roll it by window, cap it. The
first real question killed that framing — a counter is a **read-modify-write**, and nothing
in `File` could express one safely.

```
process A: [lock] read 5 [unlock]                [lock] write 6 [unlock]
process B:            [lock] read 5 [unlock]  [lock] write 6 [unlock]
```

`read()` takes a shared lock, `write()` an exclusive one, and composing them is a race. For
most data a lost update is annoying. For a sequence it is a **duplicate identifier**, which
surfaces far from the cause — a duplicate key, or a row quietly attached to the wrong
record.

So the deliverable grew a second half: `File::update()`, holding one exclusive lock across
the read and the write, calling the mutator before anything is written so a refusal leaves
the file untouched. That followed ADR-0037's precedent rather than re-litigating it — a
`flock()` inside `FileSequence` would have put ADR-0005's discipline in a second place, where
it drifts. `File` now has three writers for three shapes: replace, replace-by-streaming,
read-modify-write.

## The concurrency test had to be real, and then had to be proved real

Everything inside one PHP process shares a lock owner, so `flock()` never contends: an
in-process "concurrency" suite passes against an implementation with **no locking at all**.
T-14 therefore spawns four actual processes — the same reasoning that put T-03 against a live
`php -S` — each drawing 30 numbers, asserting the union is exactly `1..120` with no duplicate
and no gap.

A green concurrency test proves nothing by itself, so the first planted defect was the split
lock: `update()` reduced to a read followed by `File::write()`. **T-14 caught it.** That one
result is what makes the other 40 tests worth having.

## Three refusals, each against a tempting alternative

- **The cap refuses; it does not wrap.** Wrapping to `1` re-issues identifiers already in
  use, silently, at exactly the load that makes duplicates expensive. The refusal also leaves
  the state untouched, so catching it and retrying in the next window costs nothing.
- **A corrupt state file is refused and left on disk.** Resetting is the reflex and has the
  worst blast radius: it re-issues the entire window. The distinction that matters is
  *absent* versus *unparseable* — an absent or blank file is what `touch` and deploy scripts
  leave behind, and is a legitimate fresh start.
- **The window stays an opaque caller-supplied string.** The estate's helper called
  `date_default_timezone_set()` as a side effect of minting an identifier, changing the
  timezone for the rest of the request. Keeping the calendar out means the library never
  makes that decision.

The cost of the third is a limit I could not engineer away: opaque strings cannot be ordered,
so *any* window change resets, including a change to an earlier one. A lexicographic
"must not go backwards" guard would work for `2026-08-06` and padded `008` and silently break
unpadded numbers, where `10` sorts below `9`. A guard that is right for some formats and
wrong for others is worse than a limit written down — so it is written down, in the class, in
the ADR, and in a test.

## Gates

41 new tests (T-14 tagged); full suite 1601 green. **8 planted defects, 8 caught** — the split
lock, cap check removed, cap wrapping, corrupt state reset, window change not resetting, blank
file treated as corrupt, window guard removed, `cap: 0` accepted. PHPStan max clean on the
first run this time, CS-Fixer clean, deptrac 0/0, `composer normalize` clean,
`consistency_lint` green.

## Lesson

Before writing the class a requirement names, check whether the primitives it will stand on
can express its core operation. FR-32 looked like a counter; the missing piece was a lock
that spans two operations, and building the counter first would have produced something that
passed every test and lost increments in production.
