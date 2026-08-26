# ADR-0078: A wire witness for T-10, and the receiver that rewrites the evidence

- **Status:** Accepted
- **Date:** 2026-08-26
- **Deciders:** project architect (agent), maintainer
- **Related:** issue [#101](https://github.com/danielPoloWork/egl-util-php/issues/101) ·
  [ADR-0056](0056-refuse-the-terminator-at-construction-and-hand-mail-an-array.md)
  (the decisions this makes testable) ·
  [ADR-0071](0071-one-dsn-points-the-behavioural-suites-at-an-engine-and-an-unreachable-one-is-red.md)
  (the service-container leg this copies — and whose fourth fail-not-skip guard it corrects) ·
  [ADR-0027](0027-constant-time-comparison-is-asserted-by-mechanism-not-by-timing.md)
  (why the seam assertion exists and why it is not enough) · spec **T-10**, revision **r26**

## Context

T-10 had three legs and all of them stopped at the same place. `MailMessageTest` and
`EmailAddressTest` prove the construction-time refusals; `NativeMailerTest` proves what reaches PHP's
`mail()` **arguments**, asserted against the `RecordingMailApi` seam. That seam is correctly placed —
ADR-0027's rule says a property no behaviour can distinguish is asserted as a mechanism, and "the
headers are an array, not a string block" is exactly such a property.

What no seam can witness is SMTP. Three of ADR-0056's load-bearing claims were settled by probing a
real transport **by hand** in August and writing the results into prose:

1. **D3's `Bcc` claim** — "probed, PHP issues a `RCPT TO` for it and omits the header from the
   message it sends."
2. **Alternative 3's rejection** — the string header block was rejected because a hand-probe showed
   an injected `Bcc` *delivered*. This is the justification for D3 existing at all.
3. **D5's folding** — a subject over 45 bytes folds into several encoded-words joined by `CRLF`.
   ADR-0056's own probe table records that PHP **flattens `CRLF` in `mail()`'s `$subject` to
   spaces**, and no test had ever sent a folded subject through a real `mail()`. The existing unit
   test uses `Résumé`: six characters, one encoded-word, no fold.

A prose record of a probe nobody can re-run is a claim with an expiry date nobody can see. Issue
#101 (SDET Lead, 2026-08-09) asks for the leg that re-runs it.

## Decision

**A `mail-wire` CI job puts a real MTA behind `mail()` — msmtp relaying into a Mailpit sink — and a
new `WireCaptureTest` asserts on the captured messages. Configured-or-skipped, and never
configured-and-green.**

The pipeline is `PHP` → `sendmail_path` (msmtp) → Mailpit SMTP `:1025`, with the suite reading
Mailpit's HTTP API on `:8025`. Two fixtures: `Mailpit` (the API client and the configuration switch)
and `CapturedMail` (one stored message, in its three representations).

### 1. The receiver rewrites the evidence, and the obvious assertion is therefore wrong

This is the finding that shaped the suite, and it inverts the test anyone would write first.

**Mailpit does not expose the SMTP envelope.** No field in its API reports the `RCPT TO` list.
Instead, at ingest it compares the envelope's recipients against the addresses in the headers and, for
any envelope recipient the headers do not mention, **prepends a synthetic `Bcc:` header to the copy it
stores**.

So the natural assertion — *"msmtp stripped `Bcc:`, therefore the raw source must not contain a
`Bcc:` header"* — **fails against a pipeline that is working perfectly**. Worse than failing: a
maintainer reading that red would conclude the `Bcc` header had leaked onto the wire, which is the
opposite of what happened.

The correct test is the inverse, and it is *more* discriminating than the naive one:

> Mailpit only injects addresses the headers **omit**. msmtp strips `Bcc:` on the wire. So a bcc
> address reaches Mailpit only as an envelope recipient — and if it had never reached `RCPT TO`, the
> header would have been stripped and Mailpit would have had nothing to add, leaving the field empty.
> **A non-empty `Bcc` is therefore a witness that the address was delivered as an envelope
> recipient.**

Two smaller consequences of the same rewrite, both able to break a careless assertion: the injected
header is prepended, so header **ordering** and byte-for-byte comparison against what PHP handed
`sendmail` are both meaningless here; and msmtp adds `From`, `Date` and `Message-ID` when absent, so
Mailpit's header set is a **superset** of what this library emitted. The suite asserts on the headers
the library sets, never on the whole set.

### 2. `--fail-on-skipped` does not see a suite skipped from `setUpBeforeClass`

ADR-0071 lists four independent things enforcing fail-not-skip for the database leg, the fourth being
`phpunit --fail-on-skipped`. **That guard is only sound because the database leg raises its skips per
test**, from `enginePdo()`. Nobody had written down the dependency, and this leg walked straight into
it.

The whole-class precondition here belongs, obviously, in `setUpBeforeClass()`. Written there and
measured:

| invocation | exit |
|---|---|
| `phpunit --group mail-wire` | 0 |
| `phpunit --group mail-wire --fail-on-skipped` | **0** |
| `phpunit --group mail-wire --fail-on-empty-test-suite` | **0** |
| both flags together | **0** |

A skip raised from `setUpBeforeClass()` becomes a skipped *test suite* with **zero executed tests**,
and neither flag reports it. Left there, a CI job whose `EGL_TEST_MAILPIT_URL` never arrived would
have printed a green wire leg having sent no mail at all — precisely the failure issue #101's second
criterion is written against, wearing the costume of the guard against it.

**The skip is therefore raised from `setUp()`**, per test, where `--fail-on-skipped` does see it
(measured: exit 1, 15 skipped). The reachability probe is cached in a static so moving it per-test
costs one HTTP call, not fifteen.

### 3. Configured-or-skip, because this leg cannot provision its own receiver

T-03 and T-07 **fail** when their dependency is missing, and are right to: `php -S` ships with PHP,
so there is no environment where skipping is honest. Mailpit is a container. A bare `git clone` has
no way to conjure one, so an unconfigured run skips — ADR-0071's fork, drawn for its reason, with the
`EGL_TEST_MAILPIT_URL` empty-string-is-absent rule copied verbatim so a workflow that forgets a key
cannot be "configured to nothing" and green.

Configured, four things make a silent pass impossible: the preflight verifies `sendmail_path` is set
at all (it is `PHP_INI_SYSTEM`, so the suite cannot set it and a bare `mail()` would invoke an absent
`/usr/sbin/sendmail`); it polls Mailpit until it answers; it sends one message end-to-end and
**refuses to continue unless it arrives**, so a broken pipeline cannot let the negative assertions
pass vacuously; and `--fail-on-skipped` catches the rest.

### 4. The counterfactual is the strongest test in the leg

ADR-0056 D3 chose the array form. Its justification is that the rejected alternative is *dangerous* —
and no test inside this library's API can show that, because the library cannot build a string header
block. `testTheRejectedStringHeaderBlockWouldDeliverAnInjectedBcc` calls `mail()` directly, as the
rejected alternative would, and asserts the injection **succeeds**.

A passing test there is the evidence that D3 is load-bearing rather than superstition. If it ever
starts failing, PHP's behaviour has changed and D3's justification needs re-reading — a finding, not
a regression, and the docblock says so.

## What the first run found

CI run 32977655189, `ubuntu-24.04`, PHP 8.3, msmtp 1.8.24, Mailpit `v1.21`: **`OK (15 tests, 31
assertions)`**, with the preflight reporting `sendmail_path: /usr/bin/msmtp -C /tmp/msmtprc -t -i`,
Mailpit answering on the first attempt, and one preflight message captured before PHPUnit was given a
turn. The count matters as much as the colour: fifteen *executed* tests is what distinguishes this
from the green-having-sent-nothing failure §2 describes.

Four claims that had never been re-checked since August now have a run behind them:

1. **The folded subject survives — D5 is sound on a real wire.** This was the genuinely open
   question. All four folded variants round-tripped (2-byte × 40, 3-byte × 30, 4-byte × 20, and the
   mixed-width one), as did the four single-word ones. Note precisely what is proven: the subject
   **decodes to what was written**. Whether PHP flattened the `CRLF` fold to spaces on the way — as
   ADR-0056's probe table says it does to `$subject` — is *not* observable here and does not need to
   be, because RFC 2047 §6.2 has a decoder ignore whitespace between adjacent encoded-words either
   way. The design was correct; it had simply never been exercised.
2. **D3's delivery claim holds.** A `bcc` address arrives as an envelope recipient and is absent from
   the disclosed `To`/`Cc` fields.
3. **D3 is still load-bearing.** The rejected string header block delivered the injected `Bcc`, so
   PHP behaves today as it was probed in August, and the array form is buying something real.
4. **D4's envelope sender arrives.** `mail()`'s fifth argument reaches the wire on a `sendmail` host,
   distinguishable from msmtp's configured fallback because the two addresses are deliberately
   different.

Nothing needed fixing. That is the honest outcome of a leg whose purpose was to check claims rather
than to change behaviour — and unlike the database leg, which found `PDO_PGSQL` truncating at a NUL
byte (ADR-0071), this one confirms the prose it replaced.

## Alternatives Considered

- **Assert the absence of a `Bcc:` header on the wire.** The obvious test; rejected in §1 because
  the receiver re-adds it. This is recorded rather than quietly avoided, since it is the mistake the
  next person to touch this suite will reach for.
- **Parse the raw source for headers instead of using Mailpit's header map.** Considered — "what left
  on the wire" argues for the rawest possible evidence. Rejected because the raw source is *also*
  post-rewrite (§1), so it buys no fidelity while costing a header parser in the test tree. The raw
  source is still used for the body, where the base64 encoding is itself the claim.
- **Reuse the test tree's `BrowserClient` for the API calls.** Rejected: it is a GET-only cookie jar
  built for T-03, with no `DELETE` and no JSON.
- **Route the API calls through the library's own `HttpClient`.** Rejected for a reason that matters
  more than convenience: it would make a red Mail leg ambiguous between `Mail` and `Http`. The
  fixture uses streams, which adds no dependency (`ext-curl` is undeclared here).
- **A container health check (`mailpit readyz`) instead of a readiness poll.** Rejected: it depends
  on the binary's path inside the image, an assumption this repository cannot verify without pushing
  a commit, and it fails as a bare "unhealthy". The poll is equivalent, reviewable by reading it, and
  fails in our words.
- **`setup-php`'s `ini-values` for `sendmail_path`.** Rejected in favour of appending to the loaded
  `php.ini`: the value contains spaces and quotes, and the appended form is read back and asserted by
  the next step rather than trusted.
- **A `Mailer` implementation over SMTP, tested directly.** Out of scope and still rejected at
  RFC-0002 level; ADR-0056 alternative 7 stands. This leg tests the transport the library actually
  ships.
- **Send each injection payload as its own test.** Rejected on cost: nineteen payloads each waiting
  to be sure nothing arrived is a minute of wall clock for one assertion. The corpus runs in one test
  with a single settle, guarded by an attempt counter so an empty corpus cannot pass vacuously.

## Consequences

- **No production code changes.** Everything added is under `src/test`, plus one CI job and
  documentation. A consumer's `composer require` is untouched.
- **The default developer experience is unchanged.** `vendor/bin/phpunit` skips the 15 new tests and
  the suite runs as before (3 214 tests, 24 skipped, 0 failed).
- **CI grows one job**, ~2 minutes including container start and an `apt-get`.
- **Three ADR-0056 claims now have something that re-checks them**, and the leg reaches two paths
  nothing had ever executed: the folded multi-encoded-word subject through a real `mail()`, and the
  envelope sender arriving anywhere at all.
- **ADR-0071's fourth guard is now documented as conditional.** `--fail-on-skipped` protects a leg
  only where its skips are per-test. That is a property of every future suite that copies the
  pattern, so it is recorded here rather than left to be rediscovered.
- **Known limit, and it is the half of D3 that reads best in prose.** That the `Bcc` header *did not
  travel* is **not observable through this receiver** — Mailpit reconstructs it. The suite asserts
  the delivery half, which is what a consumer depends on, and does not dress up the other half as
  proven. Witnessing it would need a sink that reports the envelope verbatim, which is a different
  and larger dependency than a Mailpit container.
- **A second known limit:** the leg proves this library against msmtp. `sendmail`, Postfix and exim
  each have their own `-t` handling, and nothing here is evidence about them — the same
  version-pinning caveat ADR-0071 records for MySQL and PostgreSQL.
- **The suite is not a delivery test.** Mailpit accepts everything; nothing here says anything about
  what a real relay would do with the message.

## References

- Issue [#101](https://github.com/danielPoloWork/egl-util-php/issues/101) — the acceptance criteria,
  from the 2026-08-09 release review board (seat: SDET Lead).
- Spec **r26** — the recorded T-10 amendment.
- Mailpit's ingest rewrite: `internal/smtpd/main.go` and `internal/tools/headers.go` (the synthetic
  `Bcc:` header), upstream issue axllent/mailpit#35 for why it exists.
- msmtp(1) — `-t` reads `To`, `Cc` **and** `Bcc`; `remove_bcc_headers` defaults to removing them;
  `-f` sets the envelope sender independently of `-t`.
- RFC 2047 §2 (the 75-character encoded-word limit), §6.2 (whitespace between adjacent encoded-words
  is ignored on display — why a flattened fold can still decode correctly).
