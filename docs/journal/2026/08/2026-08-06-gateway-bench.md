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

## The number, and the fix that wasn't enough

CI's real number: **1.85×**, against the 1.5× budget. This is red, and it stays red in this PR —
not because nothing was tried, but because what was tried was profiled first and turned out not
to touch the actual cost.

Profiling in-process — the real `Hydrator` and `RowNormalizer`, `hrtime`, no benchmark harness in
the loop — found one legitimate win before accepting the miss: `TableGateway::query()` built a
fresh `QueryBuilder` on every single call, which re-ran `Identifier`'s allowlist on the table name
(already checked once, in the constructor) and on every projected column, every time — pure
repetition, since neither can change after construction. Cached per instance, safely: `QueryBuilder`
is immutable and every fluent method already returns a `clone`, so a caller chaining off the shared
cached base can never see or corrupt what the next caller gets. It moved the ratio from 1.82× to
1.85× — noise, not progress.

That was the tell that the real cost lay elsewhere. Breaking the ~416 µs total down: `select()`
alone costs ~114 µs, `RowNormalizer::normalize()` adds ~98 µs — and **hydration adds ~184 µs, 44%
of the total and the entire gap**, because the manual loop pays roughly the same for the read and
the trim (it replicates both by hand) but pays almost nothing for its equivalent of hydration — a
direct `new GatewayRow(...)` call.

That number was not a surprise once one more fact was checked: item 7.1 already measured hydration
itself, through ADR-0013's compiled-closure fast path (which `GatewayRow` qualifies for — builtin,
non-variadic, no-default parameters), at **2.40× a manual constructor call**. That is not an
unoptimized number — it is the floor a whole `standard/high` item (3.7) was spent reaching, and
nothing has beaten it since. Diluted by the shared fetch+normalize cost both subjects pay, that
floor arithmetically propagates to almost exactly the 1.8× measured here. NFR-09's 1.5× budget was,
very likely, never reachable as specified — not because this item under-delivered, but because it
asks a gateway that must hydrate to beat a comparison that does not, by a margin smaller than
hydration's own accepted cost.

**Filed as item 10.10 rather than decided here.** Two honest paths exist — revisit the budget
(a spec-scope decision this project's own rule, ADR-0040, reserves for the maintainer, not an
agent) or reopen hydration's optimization (a `standard/high`-sized decision, which a `fast/medium`
item has no business making unilaterally — exactly the over-reach item 10.9 already named two
items ago). Both are named; neither is chosen.

## A two-hour detour that was not this project's problem

Between pushing the wiring commit and CI running it at all, nothing happened for roughly two
hours — a genuine, GitHub-wide Actions incident (webhook triggers throttled to aid recovery,
confirmed against the public status feed rather than assumed from silence), not anything in this
branch. Verified rather than guessed: `git diff origin/master...HEAD --name-only` showed the branch
untouched by anything CI-relevant while waiting, and the incident's own status updates tracked the
exact symptom seen here (no run appearing at all, as distinct from a run appearing and failing).
Once GitHub declared webhook throughput restored and ten more minutes still produced nothing, the
original event had been dropped rather than delayed — an empty, no-op commit was pushed to
generate a fresh `synchronize` event, which is what finally got this PR's first real run to start.
Worth recording as its own class of "not a code problem": distinguish a dropped webhook from a
merely slow one by checking the platform's own status before waiting indefinitely on a retry that
was never coming.

## No new ADR, deliberately

The mechanism here — a same-invocation ratio computed by `bench_ratio_gate.py` because PHPBench's
own `@Assert` cannot compare two subjects in one run — is ADR-0011's, from item 3.5. This item
applies it to a new pair of subjects and decides no new tradeoff; item 9.6 made the identical call
for ADR-0030's harness, and T-13 made it for ADR-0017's boundary decision two items ago. Three
items in a row now that shipped real work with no ADR, each saying so and why — which is itself
worth noticing: not every item earns one, and forcing the ceremony would cheapen the ones that do.

## Lesson

Two lessons, not one. A fairness decision in a comparative benchmark deserves the same treatment
as a security decision: name the asymmetry you kept, and say why, before someone "fixes" it into
meaninglessness. And a small, real, safe optimization that moves a number by noise is itself a
finding — it is what proves the cost lies somewhere else, and profiling before filing is what told
the difference between "unoptimized" and "already at this project's own accepted floor."
