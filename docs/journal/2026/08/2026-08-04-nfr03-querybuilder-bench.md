# 2026-08-04 — The same shape as NFR-01, found in a different class

Roadmap item **4.5**, closing Milestone 4. Route `fast / medium`; session switched to Sonnet 5
mid-session (the fast tier) — match.

## Two claims, two kinds of evidence

NFR-03 packs two independent facts into one sentence: *"5-condition SELECT builds in ≤ 10 µs; 0
queries executed at build time."* A benchmark can prove the first. It cannot prove the second — no
amount of "this ran fast" demonstrates an absence. So the zero-queries half needed a different
instrument: reused item 4.4's `QueryLog`/`LoggedStatement` fixture, called the identical build
sequence the benchmark times, and asserted the log stays empty. Verified non-vacuous by planting an
accidental `$this->connection->select(...)` inside `toSql()` — caught.

## The number

`QueryBuilderBench::benchBuildFiveConditionSelect` — 5 `select()` columns, 5 `WHERE`-family
conditions, `orderBy`, `limit`/`offset`, then `toSql()` + `bindings()` — came in at **~23–24µs**
across two independent runs, against a ≤10µs budget. Roughly 2.3× over.

Rather than guess at why, I isolated the contributors:

```
DatabaseConnection::driver() alone           0.20 us
constructor (1 identifier)                   0.95 us
constructor + select() of 5 columns          5.43 us
full build (12 identifiers total)           17.60 us
```

Scales at roughly **1µs per identifier** — a driver lookup, the allowlist regex, quote-and-double,
all per ADR-0015 — plus a `clone $this` per fluent call from the same ADR's immutability
guarantee. Twelve identifiers and eight chained calls is simply more of both than 10µs leaves room
for. Neither cost is a defect; both are the direct, intended consequence of decisions already made
and recorded.

## Recognizing the shape before reacting to it

This is structurally identical to item 3.5's NFR-01 finding: a real, measured gap against an
absolute µs budget, in a class whose behavior is otherwise correct. I didn't re-derive how to
handle it — ADR-0011 already set the precedent, and the honest move was to apply it rather than
relitigate it: measure, report truthfully, ship non-blocking, file a scoped follow-up, and don't
touch the number or the code under a benchmark item's own route.

Put the three options to the maintainer anyway rather than assuming the precedent transfers
silently — same shape doesn't mean automatically same answer, and a genuine trade-off is the
maintainer's to make each time it recurs, not something to rubber-stamp from a prior decision.
Chosen: the same option as before.

## The workaround this benchmark needed that 3.5's didn't

Item 3.5's benchmarks needed no PHP extension, so `--php-disable-ini` alone cleared the broken-DLL
warnings polluting phpbench's environment probe on this machine. This benchmark genuinely needs
`pdo_sqlite`, and `--php-disable-ini` strips *all* extensions, including the one needed. Fixed with
`--php-config='{"extension_dir":"...","extension":"pdo_sqlite"}'` alongside it — load exactly one
clean extension rather than the whole broken ini. Confirmed it's unnecessary in CI: the Linux
runner has no broken extensions and the workflow already runs the plain command.

## Filed rather than fixed

**Roadmap item 4.6**: investigate closing the gap, most plausibly by caching the `driver()` value
the constructor already resolves once instead of the `getAttribute()` call currently repeated per
identifier — without touching the allowlist or the immutability guarantee. Needs its own
measure-first pass, same as item 3.7 needed for NFR-01. Not attempted here; item 4.5's route
(`fast / medium`) fits authoring a benchmark, not redesigning a security-relevant class.

## Bar

597 tests / 1254 assertions green (up from 596). `--group T-02` now 322, `--group T-04` still 17.
PHPStan max clean, deptrac 0/0.

## Milestone 4 is complete (4.1–4.5)

Carried forward, all named rather than lost: item 4.6 (this gap), item 5.2's LIKE-wildcard leg for
T-02 (item 4.4), and the MySQL-only behaviours still untested against a real server (a CI service
container would close both the `SET NAMES` and this benchmark's driver-honesty question — a
build-infrastructure decision, not a test one).
