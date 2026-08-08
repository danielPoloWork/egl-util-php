# ADR-0056: Refuse the terminator at construction, and hand `mail()` an array

- **Status:** Accepted
- **Date:** 2026-08-08
- **Deciders:** tech-lead (agent-drafted), maintainer (merge)
- **Related:** ROADMAP item **12.4** (security) · spec **FR-43**, **FR-44**, **T-10** ·
  [ADR-0025](0025-typed-http-wrappers-that-refuse-rather-than-coerce.md) (CR/LF refused in a
  response header **at set time, not send time** — the stance this applies to SMTP) ·
  [ADR-0026](0026-session-hardening-as-a-value-and-csrf-through-a-seam.md) (the `SessionApi` seam
  that makes a call sequence assertable) ·
  [ADR-0027](0027-constant-time-comparison-is-asserted-by-mechanism-not-by-timing.md) (a property
  no behaviour can distinguish is asserted as a mechanism) ·
  [ADR-0019](0019-four-escaping-contexts-and-the-unquoted-attribute-assumption.md) (no undeclared
  `mbstring`; PCRE instead) ·
  [ADR-0022](0022-argon2id-by-default-with-a-fallback-decided-at-construction.md) and item 12.1
  (defensive code a probe proves inert is removed) ·
  [ADR-0029](0029-result-carries-a-throwable-and-production-withholds-the-message-too.md) (a
  logger does not escalate — why `Mail` does not reach `Errors`) ·
  [ADR-0043](0043-two-named-edges-out-of-persistence-and-no-catch-at-all.md) (the layering-edge
  precedent this group deliberately does not need)

## Context

Spec FR-43 and FR-44: a validated `EmailAddress`, a readonly `MailMessage` refusing `CR`, `LF` and
NUL in any header-bound value at construction, a `Mailer` interface, and a `NativeMailer` over PHP's
`mail()` configured through its constructor rather than by mutating globals — with an SMTP client
declared a non-goal.

The estate this replaces sent mail by calling `ini_set()` for `SMTP` and `sendmail_from` immediately
before `mail()`, with the recipient and subject interpolated from request data and no check on
either. Two questions had to be answered before any of it could be designed, and both were settled
by probing rather than by reading the manual.

**Does PHP already defend this?** It does, partially, inconsistently, and differently depending on
which argument carries the bytes. Probed on PHP 8.3 against a real SMTP transport — a sink on
`127.0.0.1` speaking enough of RFC 5321 to receive a message, because `mail()` returning `false` on
a host with no MTA says nothing about whether the payload was accepted:

| payload | what actually happened |
|---|---|
| `CRLF` in `$subject` | **flattened to spaces** — `Subject: a subject  Bcc: victim@…` reached the wire |
| `CRLF` in `$to` | flattened — and the envelope carried `RCPT TO:<to@…  Bcc: victim@…>` |
| `CRLF` in an **array** header value | `ValueError`, nothing sent |
| `CRLF` in a header **name** (array form) | `ValueError` |
| NUL in `$subject` or in a header | `ValueError` |
| `CRLF` in a **string** header block | **honoured** — a second `RCPT TO:<victim@…>` was issued |

So the same bytes are silently rewritten, refused with an exception, or obeyed, according to where
they were put. The last row is a working Bcc injection, and it is not a PHP defect: passing a string
header block *is* the documented way to add a `Bcc`, so PHP parsed exactly what it was given.

**Where does encoding come in?** A header is 7-bit by RFC 5322, so a non-ASCII subject cannot be
sent literally. `mb_encode_mimeheader()` would do it, and `mbstring` is not a declared dependency of
this library — ADR-0019 already refused to acquire one for a job PCRE could do.

## Decision

### D1 — Every header-bound value is refused at construction, never cleaned

`EmailAddress::of()` and `MailMessage::create()` refuse `CR`, `LF` and NUL outright. This is
ADR-0025's response-splitting stance — *refuse at set time, not at send time* — applied to the other
protocol, and the probe above is why it is a refusal rather than a strip:

- **Stripping is what PHP does to `$subject`, and it is a silent data change.** A subject arriving as
  `a subject  Bcc: victim@example.com` is safe and is not the subject anyone wrote. Every other
  component in this library refuses that trade (ADR-0037's CSV formula guard is opt-in for the same
  reason; ADR-0019's escaper substitutes rather than deletes).
- **Waiting for PHP would inherit three behaviours.** A library that relied on `mail()`'s checks
  would be safe on the array path, silently lossy on `$subject`, and injectable on the string path.

The consequence is a type-level guarantee: an `EmailAddress` cannot hold a terminator, so every
address-shaped header value in `MailMessage` and `NativeMailer` is already clean without any of them
re-checking. The subject is the only free-text header value a caller supplies, and it is checked in
exactly one place.

The explicit `CR`/`LF`/NUL loop in `EmailAddress::of()` is **redundant against `filter_var()`**,
which rejects all three today. It is kept deliberately, and not as belt-and-braces: it is the
statement of the one property this class must never lose, expressed as a check against *this
library's* rule rather than against PHP's current filter definition. The planted-defect campaign
confirms it is observable — removing it changes the refusal message, and a test reads that message.

### D2 — Bodies are not header values, and are not checked

A body may contain anything, including `CRLF`; it is not a header. Stated because the natural
next edit after D1 is to copy the check onto the body, where it would refuse ordinary multi-line
text — and a test pins that a body with newlines is accepted.

### D3 — `NativeMailer` hands `mail()` an **array**, never a string block

The array form is the one PHP validates. This library refuses the same bytes upstream, so the array
form is defence in depth rather than the defence — and defence in depth is exactly what one wants
against the failure mode where a *future* edit loosens D1.

Both shapes send a working email, so no behavioural test can see which one is used. It is asserted
as a **mechanism** (ADR-0027's rule) against a `MailApi` seam (ADR-0026's `SessionApi` pattern):
the recorded call's headers must be an array, no header name may contain a colon, no value may
contain a terminator, and the seam's own fourth parameter must be typed `array` so a future edit
cannot quietly build a block by hand.

`Bcc` goes through as an array header on purpose. Probed: PHP issues a `RCPT TO` for it **and omits
the header from the message it sends** — which is what RFC 5322 asks for, and which is why this is
not done by hand.

### D4 — Nothing global is mutated; the only configuration is the envelope sender

Where the MTA lives is deployment configuration. `NativeMailer` reads none of it and sets none of it:
the estate's `ini_set('SMTP', …)` changed the behaviour of every other `mail()` call in the process,
including calls made by code that never asked.

That leaves the **envelope sender**, a constructor argument passed as `mail()`'s fifth parameter. Two
honest limits are documented rather than discovered: it reaches the `sendmail` command line, so it is
**a no-op on the Windows SMTP transport**; and it is safe to put on a command line for a reason worth
naming — it is an `EmailAddress`, which cannot contain a space, a quote, a semicolon or a newline, so
the type is the argument-injection defence.

### D5 — Subjects are RFC 2047 encoded-words, hand-rolled and folded

A non-ASCII subject becomes `=?UTF-8?B?…?=`, built with `base64_encode()` and PCRE — no `mbstring`
(ADR-0019). RFC 2047 caps one encoded-word at 75 characters including delimiters, and a 30-character
accented subject already produces 92, so long subjects fold into several words joined by `CRLF` and a
space — the one place this class emits `CRLF`, and it is RFC 5322's folding sequence rather than a
caller's value.

The split walks **characters, not bytes**, because a multi-byte character cut across two words
decodes to a replacement glyph in every client. That the test for it was initially *vacuous* is
recorded in the Consequences: it deserves more attention than the decision.

### D6 — Bodies are base64-encoded, and two bodies become `multipart/alternative`

Base64 rather than raw 8-bit: an RFC 5322 body line is capped at 998 octets, and a long UTF-8
paragraph sent raw is a message a relay may fold, mangle or reject. `chunk_split(…, 76)` is RFC
2045's line limit. The MIME boundary is 128 bits of CSPRNG per message.

**There is deliberately no check that the boundary does not occur in a body.** It is the obvious
defensive move — a body is attacker-controlled in any application that mails user-supplied text — and
it would be unreachable code: the boundary is drawn *after* the bodies exist, so placing it in one
means guessing 128 unborn bits. Being unreachable it could never be tested, only asserted about. It
was written, then removed, on ADR-0022's and item 12.1's precedent.

### D7 — `Mail` is a Support-only layer, and deliberately does not reach `Errors`

A new deptrac layer with one allowed edge. In particular a mailer that logged its own failures would
invert ADR-0029's stance: the decision to log belongs to the caller who catches `MailException`, not
to the transport. `Persistence`'s two named cross-group edges (ADR-0043) remain the only exception in
the file. Proved by planting a `Mail → Errors` type dependency: 2 violations, restored to 0.

## Alternatives

1. **Strip `CR`/`LF` from the subject instead of refusing** — rejected (D1): it is what PHP does on
   one path, and it silently sends a message the caller did not write.
2. **Rely on `mail()`'s own checks** — rejected: three different behaviours across three arguments,
   one of which honours the injection.
3. **A string header block**, the shape almost all legacy code passes — rejected (D3): it is the one
   PHP does not validate, and the probe shows an injected `Bcc` delivered.
4. **`mb_encode_mimeheader()`** for D5 — rejected: `mbstring` is undeclared here, and ADR-0019
   already made this call for the escaper. The hand-rolled encoded-word is ~10 lines and testable.
5. **Refuse non-ASCII subjects** instead of encoding them — rejected: it would push every consumer
   into building encoded-words themselves, which is where header injection gets reinvented.
6. **Accept a display name** (`Name <a@b>`) in `EmailAddress` — rejected: a name is free text that
   must be quoted *and* RFC 2047-encoded, and mixing it into the address value is precisely how
   `From: "Foo\r\nBcc: x" <a@b>` gets built. If display names are wanted later they arrive as a
   separate, separately-validated field.
7. **An SMTP client** — rejected at RFC-0002 level and restated here: authenticated submission,
   TLS, queueing and retries are a project, and `Mailer` is the seam that lets one be plugged in
   without touching calling code.
8. **Attachments / custom headers** — rejected as non-goals. Both are where a MIME builder becomes a
   library; a consumer who needs them has outgrown this class and wants theirs behind `Mailer`.
9. **A boundary-collision check** — written, then removed as unreachable (D6).
10. **Validating the address with a hand-rolled RFC 5322 parser** — rejected: `filter_var()` is
    stricter than the RFC in one visible way (it refuses `user@example`, a bare hostname), and that
    is a smaller cost than owning an address grammar. Documented on the class rather than left for a
    consumer to discover.

## Consequences

**Easier:** a `MailMessage` that exists is sendable — no transport re-validates anything; a consumer
swapping in a real mail library implements one method; the `Bcc`-injection class of bug is
unreachable through this API even if PHP's own behaviour changes again.

**Harder / accepted costs:** `filter_var()`'s strictness will refuse some RFC-legal intranet
addresses (`user@example`); the envelope sender is a documented no-op on one platform; no
attachments; and the group carries a seam (`MailApi`) whose only purpose is to make a mechanism
assertable — a cost ADR-0026 already accepted for the same reason.

**The campaign found a vacuous test of mine, and the arithmetic is worth carrying.** 15 defects
planted, 14 caught. Of the two that were not:

- One was **not a defect**: lower-casing the address before slicing the domain is byte-for-byte
  equivalent to slicing then lower-casing, because `strtolower()` preserves length. A bad plant, not
  a test gap — the same shape as item 12.3's first-round plant that reordered two `if`s and changed
  nothing.
- One was **a real hole**. Splitting the subject on **bytes** instead of characters passed the suite,
  because the multi-byte test used only three-byte characters (`日本語の件名`) and an encoded-word's
  payload here is 45 bytes — a multiple of three. Every split landed on a character boundary *by
  arithmetic*. The test now covers two-byte, four-byte and mixed-width subjects, and the plant is
  caught. Reassembly assertions could never have caught it either: concatenating byte chunks returns
  the same bytes.

  **The transferable rule: a corpus whose members all share one width cannot test a boundary
  computed in that width.** It is the same failure as a benchmark whose subject is the wrong shape
  (ADR-0018, ADR-0020) — the instrument agreed with the code because both were measuring the easy
  case.

**No spec amendment.** FR-43, FR-44 and T-10 were implementable exactly as written — the second item
in a row, after four of the previous six needed the spec corrected in the same PR.

**No patterns-catalogue entry.** `Mailer` is an interface over a transport and `MailApi` is a seam;
neither is a deliberate pattern adoption, and ADR-0026's `SessionApi` set the precedent of not
claiming one. Recorded here so the absence is a decision rather than an omission (§8).

## References

- RFC 5322 §2.2 (header fields are 7-bit; folding), §2.1.1 (line limits: 78 SHOULD, 998 MUST)
- RFC 5321 §4.1.2 (envelope), §2.4 (the local part is case-sensitive; the domain is not)
- RFC 2045 §6.8 (base64 line length), RFC 2047 §2 (encoded-word, 75-character limit)
- OWASP Cheat Sheet Series: *Email Header Injection*
- PHP manual: `mail()` (the array header form, PHP ≥ 7.2), `filter_var()` / `FILTER_VALIDATE_EMAIL`
- Item 12.4's probes (the SMTP-sink capture table above) — recorded in the journal entry for
  2026-08-08
