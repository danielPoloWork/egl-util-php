# 2026-08-08 — The last planned item, and the milestone that still doesn't close

Roadmap item **12.5**, `CryptoBench` for NFR-13. Route `fast / medium`; the session model was
switched to Sonnet 5 immediately before this item — first match this milestone rather than a
recorded mismatch.

Small on purpose. The spec names one number — a 1 KiB `Crypto` encrypt+decrypt round trip,
≤ 60 µs — and the class does exactly that: one subject, the round trip rather than either half
alone, because `decrypt()` cannot be measured honestly without a real token a matching
`encrypt()` produced. Local sanity (this box's phpbench CLI has a standing, documented capture
bug, so direct `hrtime()` timing stood in): **~14 µs**, comfortable headroom, nowhere near the
knife-edge item 11.7 lives on.

## The decision that wasn't made twice

Two things this item did *not* add, both because yesterday's item already settled them.

**No `Bench\Assert`.** The budget lives in `bench_budget_gate.py`'s CI/nightly invocation, same as
every prior item this milestone — one home per number, so a future reader checking "what is
NFR-13's ceiling" has exactly one place to look instead of two that could disagree.

**No new control subject.** Item 12.4 found that a run-wide runner slowdown moves *every* subject
in one CI job together — control included — and filed the fix as item 12.6.
`RowNormalizerBench::benchInlineTrimHundredRows` already plays that role for this job. Adding a
second control here would not catch anything the first one doesn't; it would just be a second
copy of the same signal in the same log.

## The milestone stays open

M12's *planned* scope is done, and `README.md`'s row for it stays "⏳ planned" anyway —
`consistency_lint`'s milestone check enforces that structurally, since item 12.6 (filed at 12.4)
is still an unchecked `- [ ]`. Same shape M11 closed in: 11.4/11.5 finished the written work while
11.6/11.7 stayed open as maintainer decisions on numbers. 12.6 is a different kind of open item —
not a number to pick, but a question about the gate's own design (does a control-subject breach
invalidate a run, or does the comparison itself need to change) — and it is exactly the item this
session filed the evidence for a few hours ago.

## Lesson

Not every item needs its own instrument. The temptation, having just learned that a control
subject would have saved a wrong diagnosis, is to add one everywhere. The right scope is one per
place the signal actually differs — here, one per CI job, because that is the granularity at which
"the runner got slow" is true or false.
