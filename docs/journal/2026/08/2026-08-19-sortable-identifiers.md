# 2026-08-19 — Asserting an absence, and a floor that turned out to be a ceiling

Roadmap item **14.2** (spec r18 **FR-46**/**NFR-15**, **ADR-0063**, closes issue #96) — M14's
second unit, and the first to consume 14.1's clock seam. Route `frontier-reasoning / extra`;
session model Opus 5 — one tier below the route, recorded rather than glossed.

## The interesting problem was not the encoding

Both formats are fully specified elsewhere — 48 bits of millisecond timestamp, then entropy;
Crockford Base32 for ULID, RFC 9562's field layout for v7. Writing them is transcription, and the
one implementation nicety is that 48 and 80 are both multiples of five, so the ULID encoder needs
no 128-bit arithmetic: ten characters from an integer, sixteen from a byte stream, no padding case
to invent.

The problem worth the effort was RFC-0003's instruction to pin the monotonicity ruling **"in both
directions"**. One direction is trivial. The other has no honest behavioural form:

- "the identifiers come out unsorted" is **probabilistic** — true with probability `1 − 1/N!`, and
  a gate that can fail on a coin toss is what ADR-0030 exists to argue against;
- "they are distinct" proves the entropy is live, not that ordering is absent;
- and decisively: **a monotonic implementation passes every behavioural test in the suite.** More
  ordering never contradicts an assertion that some ordering exists.

So the pin is a mechanism (ADR-0027): `Str` declares **no properties at all**. Monotonicity needs
memory, memory needs state, state on a static-only class is a property. Adding the feature FR-46
excludes turns the assertion red and forces the spec change that decision would require.

**The plant proved it, and the number is the finding.** Planting `private static int
$lastMillisecond` — the first line of any monotonic implementation — failed **exactly one** test.
The other nineteen stayed green. That is the clearest instance this project has produced of
ADR-0027's rule: the mechanism assertion is not belt-and-braces here, it is the only thing between
the decision and its silent reversal.

## The measurement refuted the reason it was taken

`benchRandomToken` (`Str::random(10)`) went into the bench file on item 10.10's lesson: check a
budget against the already-accepted inner cost it contains before setting it. The assumption was
that ten CSPRNG bytes were the floor beneath both identifiers.

CI, run 1:

| Subject | Mode | rstdev |
|---|---|---|
| `benchUlid` | 3.453 µs | ±0.68% |
| `benchUuidV7` | 2.592 µs | ±1.34% |
| `benchRandomToken` | **6.083 µs** | ±1.08% |

Both identifiers are **faster than the thing they were supposed to be built on top of**. The
scopes are not nested at all: `Str::random()` calls `random_int()` once **per character** — ten
rejection-sampled draws — while these call `random_bytes(10)` once. The subject measures a
different entropy mechanism, not a smaller amount of the same one.

Item 10.10's lesson is usually cited as "check the outer against the inner before writing the
budget." The sharper version, earned here: **check that there is an inner at all.** The nesting
was assumed from the shape of the source ("both draw ten random bytes") and it was wrong. Caught
before the number was written rather than three items later, which is the only reason it cost a
paragraph instead of a revision.

The subject stays, relabelled a reference point. NFR-15's 10 µs rests on the identifiers' own
readings, at 2.90×/3.86× — inside ADR-0058's ≥ 2.66× never-fired band.

## A mistake of my own, caught in the draft

Writing the ADR while CI was still running, I filled in a **run-2 column with numbers I had not
measured** — invented figures, formatted to three decimals and a plausible rstdev, sitting in a
table beside real ones. Caught on re-reading before commit and replaced with `(pending)` until the
second run produced them.

Worth recording rather than quietly fixing, because the failure mode is specific and this project
has a name for its cousin: fabricated evidence reads *exactly* like measured evidence once it is
in a table. The habit that saved it is the same one item 10.8 named — read the job, not the
column — applied to my own output. The draft-PR-first method exists precisely so numbers arrive
before prose; writing the prose ahead of the numbers is what created the opportunity.

## Plants

Five, five caught, each verified *landed* by absence of the original before its result was
believed:

| Plant | Caught by |
|---|---|
| `private static int $lastMillisecond` (monotonicity) | the mechanism assertion — **and nothing else** |
| Second-resolution timestamp (drop `format('v')`) | 6 tests |
| Crockford's characters in the **wrong order** | 8 tests, including sortability |
| v4's `0x40` version nibble on `uuidV7()` | 1 test |
| Upper 48-bit guard removed | 1 test |

The third is worth keeping: it preserves the alphabet's *contents* (so the excluded-letters test
stays green) and breaks only its ASCII ascent — the property that makes lexicographic order equal
numeric order. A suite that checked "no I/L/O/U" and nothing else would have passed it.

## Numbers

2 868 tests (+20), 6 078 assertions; CS-Fixer 0/255; PHPStan max clean; deptrac **347 allowed / 0
violations / 0 uncovered** (up from 344 — the new `Str` → `ClockInterface`/`SystemClock` edges,
riding the `Support → Psr` grant ADR-0062 opened one item ago, exactly as RFC-0003 predicted its
second consumer would).
