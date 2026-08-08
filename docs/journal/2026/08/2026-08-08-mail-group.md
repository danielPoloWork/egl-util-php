# 2026-08-08 — Three answers to the same bytes, and a corpus that could not fail

Roadmap item **12.4**, the `Mail` group. Route `frontier-reasoning / extra`; run at standard tier
(Opus 5, the session model) with the maintainer's go-ahead — mismatch recorded, not glossed.

## The probe that decided the whole item

`mail()` on this machine cannot reach an MTA, so it returns `false` for everything. That makes it
useless as an oracle: "PHP rejected my payload" and "PHP accepted my payload and the transport
failed" are the same observation. Round two of the probes hit exactly that wall, and round three
removed the variable — a **socket server on `127.0.0.1` speaking enough of RFC 5321** to receive a
message, with `ini_set('SMTP', …)` in the probe process only, and the raw session written to a file.

What arrived on the wire:

| payload | result |
|---|---|
| `CRLF` in `$subject` | **flattened to spaces** — `Subject: a subject  Bcc: victim@example.com` |
| `CRLF` in `$to` | flattened — envelope carried `RCPT TO:<to@example.com  Bcc: victim@…>` |
| `CRLF` in an **array** header value | `ValueError`, nothing sent |
| `CRLF` in a header **name** | `ValueError` |
| NUL anywhere in a header or the subject | `ValueError` |
| `CRLF` in a **string** header block | **honoured** — a second `RCPT TO:<victim@…>` was issued |

Three behaviours for the same three bytes, chosen by which argument carries them. The last row is a
working Bcc injection and is not a PHP defect: a string header block *is* the documented way to add a
`Bcc`, so PHP parsed what it was handed.

Two decisions fall straight out of the table. **Refuse at construction**, because a library relying on
PHP's checks would be safe on one path, silently lossy on another and injectable on the third — and
because flattening is a silent data change, which is the trade this library refuses everywhere else
(ADR-0037's CSV guard, ADR-0019's escaper). And **hand `mail()` an array**, because that is the shape
PHP validates; with our own refusal upstream it is defence in depth, which is what one wants for the
day someone loosens a validation.

The array form had one more gift: PHP issues a `RCPT TO` for an array `Bcc` **and omits the header
from the message it sends**. That is what RFC 5322 asks for, and it is why the group does not
hand-roll bcc handling.

## Assert the shape, because behaviour cannot see it

Both header shapes send a working email. No behavioural test distinguishes them — the same situation
as `hash_equals` versus `===` (ADR-0027) and the session cookie ordering (ADR-0026). So the array form
is asserted as a mechanism through a `MailApi` seam: the recorded call's headers must be an array, no
name may carry a colon, no value a terminator, and **the seam's fourth parameter must be typed
`array`**, so a future edit cannot quietly build a block by hand.

## A defence I wrote, then deleted

`multipart/alternative` needs a boundary, and a body is attacker-controlled in any application that
mails user-supplied text — so the obvious move is to search both bodies for the boundary and refuse a
collision. I wrote it. Then I noticed it can never run: the boundary is drawn from a CSPRNG *after*
the bodies exist, so placing it in one means guessing 128 bits that do not exist yet. Unreachable code
cannot be tested, only asserted about, and it reads as though the surrounding code needed it.

Removed, with the reasoning in ADR-0056 §D6. Item 12.1 deleted a guard for the same reason a day
earlier; yesterday, item 12.3 kept a piece of code and deleted its *justification* instead. Three
variants of one question in three days: **what is this line actually able to prevent?**

## The corpus that could not fail

Fifteen defects planted, fourteen caught. The interesting one:

Splitting the encoded subject on **bytes** rather than characters — the exact bug that renders a
subject as replacement glyphs — **passed the suite**. My multi-byte test used `日本語の件名`, and an
encoded-word's payload here is 45 bytes: 75 minus 12 of delimiters, rounded down to a multiple of 3 so
base64 does not pad mid-word. 45 is divisible by 3. Every character in the corpus is three bytes wide.
So every split landed on a character boundary **by arithmetic**, and a byte-wise implementation was
indistinguishable from a correct one.

Worse, the reassembly assertion could never have caught it either: concatenating byte chunks returns
the same bytes. Only per-word UTF-8 validity can see it, and only with characters whose width does not
divide the chunk size.

The test now runs two-byte, four-byte and mixed-width subjects, and the plant is caught. The rule is
general enough to write down: **a corpus whose members all share one width cannot test a boundary
computed in that width.** It is the same failure as a benchmark whose subject is the wrong shape
(ADR-0018, ADR-0020) — the instrument agreed with the code because both were measuring the easy case.

The fifteenth plant was not a defect at all: lower-casing an address before slicing off the domain is
byte-for-byte equivalent to slicing then lower-casing, since `strtolower()` preserves length. A bad
plant, like item 12.3's `if`-reordering — and the campaign's own check (verify the plant *applied*)
is what keeps those from being read as passes.

## Two small honest limits

`filter_var()` refuses `user@example` — a bare hostname, legal per RFC 5321 and used by real intranet
addresses. Owning an RFC 5322 grammar is the alternative, and it is worse. Documented on the class.

The envelope sender reaches a `sendmail` command line, so it is **a no-op on the Windows SMTP
transport**. Also documented, because a silent no-op that only manifests on one platform is worse than
a stated limit. It is safe on that command line for a reason worth naming: it is an `EmailAddress`, and
one cannot contain a space, a quote, a semicolon or a newline — the type *is* the argument-injection
defence.

## Postscript — the same commit failed two different gates

CI's benchmark job went red, and my first read of it was wrong in a way worth recording.

**Run 1:** the *relative* gate failed — five subjects between +11.19% and +19.44%, none of them
touching `Mail`. Among them `RowNormalizerBench::benchInlineTrimHundredRows`, which is **the
control**: a hand-written inline `trim()` loop that calls no library code and therefore cannot
regress. I concluded runner noise and re-ran the same commit, saying a re-run would clear it.

**Run 2:** it did not. The relative gate passed and the **absolute** ceiling tripped instead, on item
11.7's router (7.021 µs against 5). Every subject in run 2 was **27–103% slower** than in run 1 on
identical code — `benchWriteTenThousandByTen` went 9 691 → 19 660 µs.

So both failures were the runner, and the re-run did not fix it; it moved which gate noticed. The
mechanism, once the two runs are put side by side, is specific: ADR-0030's same-runner A/B measures
base and head **sequentially**, so a runner that changes speed *between the halves* shifts every
subject in one direction and the gate reads it as a regression in whichever half ran second. On a
uniformly slow runner the halves agree with each other and the absolute ceiling is what gives way.
ADR-0045's exclusions do not cover this — they name three subjects for *cross-run* noise, while this
moves everything, control included.

Filed as item **12.6** with four options and no decision taken, and the router evidence appended to
item 11.7, where it qualifies the "40% over" figure without changing the diagnosis: five runs on
unmodified code now read 6.874–7.145 · 5.188 · 5.673 · 4.735 · 7.021 µs, once *inside* the ceiling.

The control subject earned its keep twice here. In run 1 it was the proof the code was innocent —
without it I would have had only "my diff doesn't touch hydration", which is an argument rather than
evidence. And it is the instrument item 12.6 proposes to use: **a control moving past the threshold
means the run is invalid, not that the code regressed.**

## Lesson

An oracle that cannot fail is worse than no oracle. `mail()` returning `false` for every input made
the first two probe rounds unable to answer their own question, and a corpus of uniformly-3-byte
characters made a test unable to fail. Both looked like evidence. The fix in both cases was to remove
the thing that made every answer identical — the missing MTA, and the shared character width.

The postscript is the same lesson pointed at a gate: one that can fail for a reason unrelated to the
change is an oracle whose *green* is worth less than it looks, and whose red costs an investigation
each time. The control subject is what tells the two apart — which is why it belongs inside the
benchmark rather than in the reasoning about it afterwards.
