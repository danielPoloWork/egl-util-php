# ADR-0038: One lock across the read and the write, and a sequence that refuses to wrap

- **Status:** Accepted
- **Date:** 2026-08-06
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 9.5 · spec r6 FR-32, FR-22/23, §6 T-14 ·
  [RFC-0002](../rfc/0002-application-layer-groups-from-legacy-intake.md) §Decision (`Support`
  additions) · [ADR-0005](0005-atomic-file-writes-with-a-sidecar-lock.md) (the sidecar lock
  this composes) · [ADR-0037](0037-disable-phps-escape-character-and-keep-the-formula-guard-opt-in.md)
  (the immediately preceding case for extending `File` rather than duplicating it) ·
  [ADR-0027](0027-constant-time-comparison-is-asserted-by-mechanism-not-by-timing.md) (a
  property no in-process test can observe) · [ADR-0036](0036-refuse-the-downgrade-and-the-characters-parse-url-launders.md)
  (naming a limit rather than pretending it away)

## Context

FR-32 asks for a rolling counter with an explicit cap. The estate's version generates a
daily identifier from a `.state` file holding `window|counter` — the format is sound. Three
things around it are not:

- the counter file sits **beside each deployed endpoint**, so one counter exists per deploy
  folder while every folder mints identifiers into the same table;
- the ceiling is computed and compared by hand at the call site, and the failure is a bare
  `RuntimeException` built from string interpolation;
- generating an identifier calls `date_default_timezone_set()` as a side effect, changing
  the timezone for everything else in the request.

None of those is the interesting problem. The interesting problem is that a counter is a
**read-modify-write**, and this library's existing file primitives cannot express one safely.

## Decision

### 1. `File::update()` — the lock spans the read *and* the write

`File::read()` takes a shared lock. `File::write()` takes an exclusive one. Composing them
gives:

```
process A: [lock] read 5 [unlock]                [lock] write 6 [unlock]
process B:            [lock] read 5 [unlock]  [lock] write 6 [unlock]
```

Both read `5`, both write `6`, and one increment is gone. For a sequence, a lost increment
is not a lost update — it is a **duplicate identifier**, surfacing later as a duplicate key
or, worse, as a row quietly attached to the wrong record.

So `File` gains a third writer: `update(string $path, callable $mutator)`, which acquires the
exclusive sidecar lock, reads, calls the mutator, writes atomically, and only then releases.
The mutator is called **before** anything is written, so a mutator that throws — refusing an
increment past a cap — leaves the file exactly as it was.

This follows ADR-0037's precedent rather than re-deciding it: `FileSequence` could have
opened its own `flock()`, and that would have put ADR-0005's discipline in a second place,
where it would drift. The three writers now cover the three shapes — replace, replace by
streaming, read-modify-write — and share one implementation of the lock, the
same-directory temporary file, the mode-before-rename ordering, and the cleanup.

**Why not lock the target itself**, which would be simpler? ADR-0005 already measured that:
a handle held on the target makes `rename()` fail on Windows. The sidecar is what makes the
replacement atomic *and* lockable at the same time.

### 2. The cap refuses; it never wraps

`next()` returns `1..cap` within a window. Reaching the cap raises
`SequenceExhaustedException` — the type spec r3 already named — with the path, the window,
and both numbers in the message.

Wrapping is the tempting alternative and it is the dangerous one: returning to `1` re-issues
identifiers that are already in use, and does so silently, at exactly the moment the system
is busiest. A refusal is loud, local, and recoverable — the caller widens the cap, narrows
the window, or stops.

The refusal leaves the state file untouched, so a caller that catches it and retries in the
next window is not penalised. Asserted by a test.

### 3. A corrupt state file is refused, not reset

Resetting is the reflex. It is also the failure mode with the worst blast radius: a
sequence that treats unparseable state as "start again" re-issues **every** number in the
window.

The distinction drawn is between *absent* and *unparseable*. An absent or blank file is the
legitimate first-run state — and is also what `touch` and most deploy scripts leave behind —
so it starts at `1`. Anything else that does not match `window|digits` raises `FileException`
and, deliberately, **does not overwrite the file**: the evidence a human needs to diagnose it
has to survive the failure.

### 4. The window is an opaque caller-supplied string — with a named limit

`next(string $window)` takes the window rather than deriving it. Keeping the calendar out of
the class avoids the estate's timezone side effect, and lets a caller roll by date, by
day-of-year, by shift, or by anything else.

The cost is stated rather than hidden: **the class cannot order opaque strings**, so *any*
change of window resets the counter — including a change to an earlier one. A caller
supplying a window that goes backwards (a clock stepped back, a hand-passed constant)
re-issues numbers. Callers must supply a monotonically advancing window; `peek()` exists so
this is observable, and a test pins the behaviour so the limit is visible rather than
discovered.

A lexicographic "must not go backwards" check was considered and rejected: it works for
`2026-08-06` and for zero-padded `008`, and silently breaks unpadded numeric windows, where
`10` sorts below `9`. A guard that is correct for some formats and wrong for others is worse
than a documented limit.

### 5. `peek()` and `remaining()` are advisory, and say so

Both read without the lock. Any value they return may be stale before the caller acts on it,
so branching on them to decide whether `next()` will succeed is a race. Documented on both
methods: call `next()` and catch the refusal.

## Alternatives Considered

1. **`read()` + `write()` at the call site** — rejected: the interleaving above loses
   increments, which for a sequence means duplicate identifiers.
2. **`flock()` inside `FileSequence`** — rejected on ADR-0037's precedent: a second copy of
   ADR-0005's discipline is one that will drift.
3. **Lock the target file instead of a sidecar** — rejected: ADR-0005 measured that a held
   handle breaks `rename()` on Windows, and losing atomicity to gain simplicity is the wrong
   trade for a file whose corruption re-issues identifiers.
4. **Wrap at the cap** — rejected: silent duplicate identifiers, at peak load.
5. **Reset on a corrupt state file** — rejected: re-issues the whole window, and destroys
   the evidence.
6. **Derive the window from the clock inside the class** — rejected: it forces a timezone
   decision the library cannot make, which is how the estate's helper ended up mutating
   global state from inside an identifier generator.
7. **Refuse a lexicographically-earlier window** — rejected: correct for ISO dates and padded
   numbers, wrong for unpadded ones, and a guard that is wrong for some callers is worse than
   a named limit.
8. **Test concurrency with threads or sequential calls** — rejected as vacuous: everything
   inside one PHP process shares a lock owner, so `flock()` never contends and the suite
   would pass against an implementation with no locking at all.

## Consequences

**Easier:** a counter shared by several processes — or several deploy folders — issues each
number exactly once; exhaustion is a typed, catchable event rather than a wrap nobody
notices; and `File::update()` is available to any future read-modify-write.

**Harder / accepted:** `File`'s public surface has grown to three writers in two items, which
is deliberate but worth watching; a corrupt state file now stops the caller rather than
limping on; and the window's monotonicity is the caller's responsibility, stated as a limit
rather than enforced.

**Verification:** 41 tests, of which the load-bearing one is **T-14**
(`#[Group('T-14')]`) — four real processes drawing 30 numbers each, asserting the union is
exactly `1..120` with no duplicate and no gap, plus a capped variant asserting that no number
above the cap is ever issued and that at least one worker is refused. The suite is **proved
non-vacuous by 8 planted defects**, the first of which is the one that matters: splitting
`update()` into a separate read and write reproduces the classic race, and **T-14 catches
it**. The others — cap check removed, cap wrapping, corrupt state reset, window change not
resetting, blank file treated as corrupt, window guard removed, `cap: 0` accepted — were each
caught by the unit suite.

## References

- ROADMAP item 9.5
- spec r6 FR-32, FR-22/23, §6 T-14
- [RFC-0002](../rfc/0002-application-layer-groups-from-legacy-intake.md) §Decision (`Support` additions)
- [ADR-0005](0005-atomic-file-writes-with-a-sidecar-lock.md) (the sidecar lock this composes)
- [ADR-0037](0037-disable-phps-escape-character-and-keep-the-formula-guard-opt-in.md) (the immediately preceding case for extending `File` rather than duplicating it)
- [ADR-0027](0027-constant-time-comparison-is-asserted-by-mechanism-not-by-timing.md) (a property no in-process test can observe)
- [ADR-0036](0036-refuse-the-downgrade-and-the-characters-parse-url-launders.md) (naming a limit rather than pretending it away)
