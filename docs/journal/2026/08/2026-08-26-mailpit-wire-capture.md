# 2026-08-26 — The receiver rewrote the evidence, and the guard was inert

Issue **#101**, both criteria. Route `standard / medium`; session model Opus 5. **ADR-0078**
annotated, spec **r26**.

T-10 had three legs and every one of them stopped at the `MailApi` seam. That placement is correct
— ADR-0027's rule puts a property no behaviour can distinguish at a mechanism assertion, and "the
headers are an array, not a string block" is exactly that. What it means is that three of ADR-0056's
load-bearing claims rested on a probe run by hand in August and written into prose, which is a claim
with an expiry date nobody can see.

This leg is the probe, re-run by something that runs on every PR. Two things went differently than
planned, and both are the reason the issue was worth doing rather than incidental.

## The assertion anyone would write first is confidently wrong

The obvious test for ADR-0056 D3 is: msmtp strips `Bcc:` on the wire, so the captured message must
not contain a `Bcc:` header. I researched Mailpit's API before writing it, and the answer was that
this assertion **fails against a pipeline that is working perfectly**.

Mailpit has no field anywhere that reports the SMTP envelope. What it does instead is *rewrite the
message it stores*: at ingest it diffs the envelope's recipients against the addresses in the
headers and prepends a synthetic `Bcc:` header naming whatever the headers left out. So the header
is back, and a maintainer reading that red would have concluded the `Bcc` had leaked onto the
wire — the exact opposite of what happened.

The correct test is the inverse, and it is *more* discriminating than the one I set out to write.
Mailpit only injects what the headers omit; msmtp strips `Bcc:`; therefore a bcc address arrives
only as an envelope recipient, and had it never reached `RCPT TO` the header would have been
stripped and Mailpit would have had nothing to add. A non-empty `Bcc` field is a witness that the
address was **delivered**. Nothing else in the API is.

The half that cannot be witnessed this way — that the header did not travel — is recorded as a
known limit rather than dressed up. It would need a sink that reports the envelope verbatim, which
is a bigger dependency than a container.

## `--fail-on-skipped` does not do what this repository assumed

I put the whole-class precondition in `setUpBeforeClass()`, which is where it belongs, and then
checked the exit code rather than trusting it. Four invocations, all green:

| invocation | exit |
|---|---|
| `--group mail-wire` | 0 |
| `--group mail-wire --fail-on-skipped` | **0** |
| `--group mail-wire --fail-on-empty-test-suite` | **0** |
| both | **0** |

A skip raised from `setUpBeforeClass()` is a skipped *test suite* with zero executed tests, and
neither flag reports it. **ADR-0071's fourth fail-not-skip guard is only sound because the database
leg raises its skips per test**, from `enginePdo()` — a dependency nobody had written down, and one
this leg walked straight into. Kept where I first put it, a CI job whose `EGL_TEST_MAILPIT_URL`
never arrived would have printed a green wire leg having sent no mail at all: issue #101's second
criterion defeated by the guard against it.

Moved into `setUp()`, the skip is a skipped *test* and `--fail-on-skipped` exits 1 on all fifteen.
That is now written into ADR-0078 as a property of the pattern, so the next suite that copies
ADR-0071 does not have to rediscover it.

## What the leg reaches that nothing had

Two paths in shipped code had never been executed against a real transport:

- **A folded subject.** `encodedSubject()` splits anything over 45 bytes into several encoded-words
  joined by `CRLF` and a space — and ADR-0056's own probe table records that PHP *flattens `CRLF` in
  `$subject` to spaces*. The existing unit test uses `Résumé`: six characters, one encoded-word, no
  fold. So whether the fold survives, is flattened harmlessly (RFC 2047 §6.2 ignores whitespace
  between adjacent encoded-words), or is refused outright is genuinely unknown until CI runs. The
  corpus spans 2-, 3- and 4-byte and mixed widths at both lengths, because ADR-0056's own planted-
  defect campaign was defeated once by a corpus that shared one width.
- **The envelope sender.** ADR-0056 D4 documents `mail()`'s fifth argument as reaching the
  `sendmail` command line and being a no-op on Windows. Nothing had ever observed it arriving
  anywhere.

The strongest test in the suite is the counterfactual: `mail()` called directly with a string header
block, asserting the injected `Bcc` **is** delivered. D3 chose the array form because the
alternative is dangerous, and no test that stays inside this library's API can show that — the
library cannot build a string block. A passing test there is what makes D3 a decision rather than a
preference, and if it ever fails, PHP changed and D3 needs re-reading.

## Where this leaves the project

No production code changed. Two fixtures, one suite, one CI job, and documentation. Locally the
suite skips and `vendor/bin/phpunit` is unchanged at 3 214 tests, 24 skipped, 0 failed; PHPStan and
CS-Fixer are clean.

There is no Docker on this machine, so nothing here had met a real Mailpit before CI did. The
pipeline is three hops — PHP, msmtp, Mailpit — each of which has to be configured, and the preflight
was built accordingly: it reads `sendmail_path` back rather than trusting the step that set it, polls
the receiver rather than assuming a started container is a ready one, and sends one message end to
end that must arrive before PHPUnit gets a turn, so a broken pipeline cannot let the negative
assertions pass vacuously.

**It worked on the first run** — `OK (15 tests, 31 assertions)`, fifteen *executed*, which is the
number that distinguishes this from the failure mode the whole ADR is about. And the open question
resolved in the design's favour: **the folded subject survives.** All four folded widths round-trip.
What is proven is that the subject decodes to what was written; whether PHP flattened the `CRLF` fold
to spaces on the way is not observable here and does not matter, because RFC 2047 §6.2 has a decoder
ignore whitespace between adjacent encoded-words either way. D5 was right and had simply never been
run.

So this leg confirms the prose it replaced, rather than correcting it — the opposite of the database
leg, which found `PDO_PGSQL` truncating a bound parameter at its first NUL byte. Both outcomes are
worth the job; only one of them is worth a bug fix, and knowing which is the point.

## The other job on this PR went red, and that one found something

`build / php-8.3 / random-order` — yesterday's cell, from issue #100 — failed on this PR. Its own
documentation says a failure there is not a flake to re-run, so I did not.

The first guess was wrong and worth recording as such. The failing test asserts that a *total* time
budget stops a dripping origin; it reported `produced no response`, which is the **per-phase** timeout
firing instead, and the obvious reading is a thin wall-clock margin on a loaded runner — especially
since this test had already flaked twice. I widened the margin, and the reproduction stayed red.

It stayed red because the failure was **deterministic for its seed**: three of three, and — the
control that mattered — three of three on *unmodified* code too, which is what established it as
pre-existing rather than something this PR introduced. A failure that reproduces on a fixed seed is
not a timing accident; the order is doing it.

`php -S` is **single-threaded**, and `HttpClientLiveTest`'s fourteen tests share one. The
silent-origin test's client gives up after 0.4 s while the origin sleeps on for 1.6 s, so **1.2 s of
server-side sleep outlives the request that started it** — longer than a neighbouring test's entire
budget. Every order that put the drip test straight after the silent one failed, its `fopen()`
timing out against a server still asleep on someone else's request. Declaration order happens to put
the drip test first, which is why five months of green said nothing about it.

The fix is to stop the origin over-sleeping (0.8 s, still twice the timeout it exists to exceed), not
to widen the drip test's margin — the margin was the symptom. Seed `1787753886` now passes three of
three, and ten fresh random orders pass.

**Two corrections came out of it.** The #100 documentation offered one diagnosis for a red cell —
coupling — and this failure looked like the other one, timing; it now names both and, more usefully,
says how to tell them apart: re-run the seed, because coupling reproduces and timing does not. And
the generalisable lesson is worth more than the fix: **a fixture that keeps working after the test
abandoned it is shared state**, even when it presents as nothing but a slow response.
