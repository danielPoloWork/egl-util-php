# 2026-08-06 — Measuring the overhead a gateway is honest about

Roadmap item **10.6**, closing Milestone 10 alongside the follow-up item 10.9 filed at 10.5. Route
`fast / medium`; session model Sonnet 5, no mismatch — the first item this session that matched
its route on the first try.

## The comparison had to survive one honest question first

NFR-09 asks for the gateway's fetch-normalize-hydrate path against *"a hand-written PDO loop doing
the same work"*. Before writing a subject, the question worth settling was what "the same work"
actually means, because a benchmark that quietly does less work on one side is not measuring
overhead — it is measuring the gap it created.

Two decisions followed from taking that literally:

- **The manual loop trims strings by hand**, under the exact `is_string($value)` guard
  `RowNormalizer::normalize()` uses. A version that skipped normalization on the manual side would
  make the gateway pay for work its opponent never did.
- **The manual loop reads via `$pdo->query()`, not a prepared statement.** This one needed a
  decision, not just a mirror: the read has no bound value at all, and nobody hand-rolling a
  literal `SELECT` reaches for `prepare()`/`execute()` over `query()`. The gateway does not get
  that shortcut — `DatabaseConnection::select()` always prepares, because ADR-0014 pins real
  prepares as the connection's own security default and this benchmark is not a reason to bypass
  it. That asymmetry — one round trip the hand-written loop skips and the gateway cannot — **is**
  part of what NFR-09 is pricing, not an unfair thumb on the scale. Naming it here is what stops a
  future reader from "fixing" the fairness by giving the manual loop back its shortcut.

Both subjects share one `PDO` connection and one 100-row table, seeded once outside every timed
iteration — item 3.5's warm-cache convention, extended here to a warmed `ReflectionCache` and one
discarded warm-up call so the gateway's own lazily-cached column projection is paid for before the
clock starts.

## What the local number is worth, and why CI's is the one that counts

Direct PHP timing on this machine measured a ratio near 1.9×, over the 1.5× budget. This is
**not** treated as the answer: `vendor/bin/phpbench`'s own CLI fails its environment-detection
capture on this box before any subject runs — the same pre-existing quirk items 4.5, 4.6 and 9.6
all hit and all deferred to CI for the same reason. A local number that cannot even get PHPBench to
start is not a number to act on; it went into this journal as a sanity check that the benchmark
*runs* and produces a plausible order of magnitude, nothing more.

CI's Linux run is what the `bench_ratio_gate.py --max-ratio 1.5` step actually gates on, and that
number is recorded in the follow-up commit to this PR, once it exists — the same two-commit shape
items 9.6 and 3.7 used: wire first, record the real measurement second, so a reader never has to
take a number on faith from a machine that cannot run the tool.

## No new ADR, deliberately

The mechanism here — a same-invocation ratio computed by `bench_ratio_gate.py` because PHPBench's
own `@Assert` cannot compare two subjects in one run — is ADR-0011's, from item 3.5. This item
applies it to a new pair of subjects and decides no new tradeoff; item 9.6 made the identical call
for ADR-0030's harness, and T-13 made it for ADR-0017's boundary decision two items ago. Three
items in a row now that shipped real work with no ADR, each saying so and why — which is itself
worth noticing: not every item earns one, and forcing the ceremony would cheapen the ones that do.

## Lesson

A fairness decision in a comparative benchmark deserves the same treatment as a security decision:
name the asymmetry you kept, and say why, before someone "fixes" it into meaninglessness.
