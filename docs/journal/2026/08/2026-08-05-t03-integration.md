# 2026-08-05 — Measuring the requirement instead of arguing with it

Roadmap item **6.3**, T-03. Route `frontier-reasoning / extra`, session Opus 5 (standard) — the
mismatch accepted at 5.3, carried since. Recorded.

This item finally exercises what item 6.2 spent its whole life unable to reach.

## Two probes decided the harness

```
Set-Cookie: PHPSESSID=…; path=/; secure; HttpOnly; SameSite=Lax
```

That is a **plain-HTTP** response. PHP writes `secure` unconditionally and leaves enforcement to the
browser — so the entire suite works against `php -S` with no TLS anywhere. Had I assumed the
opposite (which is the intuitive reading), I would have concluded T-03 needed a certificate and
built something far worse.

The second probe was a bug, not a discovery: reading a live process's stderr pipe blocks until EOF,
and a server that is working correctly never sends one. The harness hung. Server output goes to a
file now, and the docblock says why so nobody "simplifies" it back.

One near-miss worth recording. The first probe run reported all three cookie flags **ABSENT** — and
that was not a finding, it was a fatal `require` on a bad autoload path before any session started.
Item 6.1's lesson, arriving again: *verify the probe landed before believing what it says.* I nearly
had a very confident, very wrong answer.

## The spec asked for something that does not exist

Spec §7 required a `hash_equals` **timing test**. ADR-0026 §7 had already rejected timing assertions
— but rejected them by *reasoning*, on the grounds that PHP-level timing is too noisy. A spec
requirement should not be set aside on an argument when it can be settled with numbers.

So I measured. 64-char tokens, 2M iterations × 5 rounds:

| scenario | median ns/op | spread |
|---|---|---|
| `===` differing at byte 0 | 101.517 | 2.63 ns |
| `===` differing at byte 63 | 104.352 | 2.12 ns |
| `hash_equals` byte 0 | 232.103 | 38.22 ns |
| `hash_equals` byte 63 | 227.929 | 29.85 ns |

The gradient a timing test lives on is **+2.8 ns/op**. The noise is **38 ns/op**. The signal is ~13×
below the floor on an idle machine, and `hash_equals`'s own gradient comes out *negative* — noise
with a sign on it. Over HTTP, where T-03 runs, 2.8 ns is six orders of magnitude under request
latency.

The right answer here was not mine to pick, so I put it to the maintainer with the numbers. They
authorised amending the spec — the option I had ranked second, and the better one: a deviation is a
disagreement someone has to keep re-reading, and this one has no open question left in it.

The scoping argument they asked me to state explicitly is the part I had underweighted. Whether
`hash_equals` is constant-time is **PHP's** contract, tested upstream. The property that exists at
*our* layer is which comparator the code invokes — and that is not a consolation prize for failing
to measure timing, it is the whole of what this codebase can get wrong, decidable exactly from the
source.

## What the amendment actually cost

Not a one-line edit. The new spec text says *every* secret-comparison path, positively and
negatively — so the single assertion in `CsrfTokenTest` had to become a registry covering
`CsrfToken::validate()` (`hash_equals`) and `Hash::verify()` (`password_verify`, which is the
correct comparator there; demanding the literal name `hash_equals` everywhere would be cargo-culting
a function instead of a property).

And a registry that can be forgotten is ADR-0026 §8 all over again, so it guards its own
completeness: every constant-time call in the library must fall inside a registered path.
`token_get_all()` strips comments first — these classes discuss `hash_equals()` at length in their
docblocks, and a text search matches prose.

The old test was deleted rather than kept alongside. Two files asserting one property is how they
drift.

## The finding this suite is for

Planting `session_regenerate_id(false)` — the one-character change that turns rotation into a rename
and leaves the old identifier valid — failed **exactly one test**:

```
testTheIdentifierFromBeforeRegenerateIsDeadAfterwards
```

Sixteen others stayed green. The id still rotated, the data still survived, the cookie still carried
its flags. Session fixation is invisible to every plausible test except the one that deliberately
replays the stale identifier, which is why that test exists and why it uses a second client rather
than trusting a cookie jar to misbehave on request.

Other probes: `httponly => false` failed 2; a constant CSRF token failed 3 (cross-session, scope
isolation, rotate); `hash_equals` → `===` failed 2 in the new class; an unregistered `hash_equals()`
planted in `Session` failed the completeness guard, naming the file and line. A forced
server-startup failure errors loudly with the reason — it does **not** skip, because a suite that
skips itself into silence is how T-03 would quietly stop running.

## Bar

1196 tests / 2710 assertions green (up from 1175). `--group T-03` runs 87. PHPStan max clean,
deptrac 0/0, consistency lint OK. **No production code changed in this item** — coverage is
unmoved at 90.87%, since T-03's library code executes in the server process, not the test one.

## Next

**6.4** — `Container` (PSR-11) + `ServiceProvider`, NFR-02 benchmarks.
