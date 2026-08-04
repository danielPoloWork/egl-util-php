# 2026-08-04 — A range, not a ceiling: measuring the wrong thing would have proved nothing

Roadmap item **5.5**, closing Milestone 5. Route `frontier-reasoning / extra`, session Opus 5 —
same mismatch as 5.3/5.4, recorded.

## The thing that makes NFR-05 different

Every other budget in this project is a ceiling: NFR-01's ≤5µs, NFR-03's ≤10µs, NFR-04's ≤16MiB.
Faster and smaller are strictly better, and only one direction can fail.

NFR-05 is a **range** — 50–200ms — and the *lower* bound is the serious one. A password hash that
completes in 5ms means the work factor is inadequate. No amount of fast redeems that.

That asymmetry decided the whole item. My first instinct was a benchmark asserting the range, which
would have been useless in the direction that matters: **a stopwatch cannot distinguish strong
parameters on slow hardware from weak parameters on fast hardware.** Both produce a number. Only one
is safe.

So the requirement splits by what each half can actually prove:

- **Security → the work factor.** `memory_cost` and `time_cost` against OWASP's published Argon2id
  minimums (m ≥ 19456 KiB, t ≥ 2). Machine-independent. If a future PHP lowered its defaults, a
  fast machine could still produce a plausible duration while the work factor had quietly dropped —
  this assertion catches that; a timing assertion cannot.
- **Capacity → a benchmark that asserts nothing**, per NFR-05's own stated purpose ("documented for
  capacity planning") and the ADR-0011/0018 precedent.

The floor is OWASP's rather than a number I picked, so "deliberately slow" is checked against a
standard with an owner outside this repository.

## The measurement, and why I'm not fixing it

| subject | measured | NFR-05 |
|---|---|---|
| `make()` argon2id | **349.5ms** ±1.82% | 50–200ms — **over** |
| `make()` bcrypt | 141.1ms | within |
| `verify()` argon2id | 347.8ms | not budgeted |

~1.75× over the ceiling. **The correct response is to change nothing.** The parameters are PHP's
defaults, they clear OWASP's floor by more than 3× on memory, and the duration is what that work
factor costs on this hardware. Lowering them to land inside the range would trade security for
latency — precisely the inversion of "deliberately slow", and against ADR-0022's decision that
those parameters belong to PHP.

Two caveats recorded with the number rather than left for a reader to infer: this isn't NFR-06's
reference machine, and **bcrypt at 141ms for `cost=10` is itself a signal** — that operation is
usually 50–80ms, so this Windows PHP build looks slow at password hashing generally and *both*
figures are probably inflated. The ratio (argon2id ≈ 2.5× bcrypt) is the more portable observation.

## The number NFR-05 doesn't ask for but a capacity plan needs

`verify()` costs the same as `make()` — ~348ms, symmetric by design in Argon2id.

NFR-05 budgets `make`, which runs at registration and on upgrade. **`verify` runs on every login.**
It, not `make`, is what caps sustainable authentication throughput. A capacity plan built on the
budgeted figure alone would understate the load, so I measured it even though it's outside the
stated budget — measuring it serves NFR-05's stated *purpose* while being outside its stated scope.

## A PHP behaviour worth knowing

`needsRehash()` returns **`true` for stronger parameters too**. PHP compares for *equality* with the
current defaults, not "at least as strong".

So a hash hardened beyond the defaults is silently **downgraded** on next login. This library always
uses PHP's defaults so it can't produce such a hash itself, but a consumer migrating from a hardened
setup would lose that hardening without any signal. Documented rather than discovered later.

## Matrices, not examples

Item 5.3 tested three of the fallback policy's four corners incidentally. A policy tested at three
of four corners has an untested corner, so the fallback matrix is now the full cross-product of
*availability × policy*, plus an assertion that every cell behaves identically with **no logger** —
the logger records the decision, it must not influence it.

The rehash matrix covers current defaults, weaker memory, weaker time, stronger, a different
algorithm, bcrypt at raised cost, malformed, empty. Every well-formed entry is also asserted to
still **verify**, because upgrade-on-login depends on a login succeeding first.

Both work-factor assertions verified non-vacuous by planting weak cost parameters: 2 failures.

## Bar

1037 tests / 2346 assertions green. PHPStan max clean, deptrac 0/0, consistency lint OK.

## Milestone 5 is complete

5.1–5.5 done. Worth noticing what has accumulated: **item 7.1 now carries three deferred
measurements** — NFR-01's absolute half, NFR-03's residual, and NFR-05's range. All three were
deferred for the same reason (hardware-dependent absolutes measured on a non-reference machine), and
all three are recorded rather than waved through. That item is no longer just "a nightly harness";
it's where three separate honest deferrals come due at once.

## Next

Milestone 6 (`v0.6.0`) — HTTP, container, errors.
