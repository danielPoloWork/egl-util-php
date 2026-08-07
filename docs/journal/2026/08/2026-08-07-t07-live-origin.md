# 2026-08-07 — The first real origin, and what it said about the client

Roadmap item **11.4**, spec §7's **T-07**. Route `frontier-reasoning / extra`; run at standard
tier (Opus 5), mismatch recorded — the fifth this milestone.

Item 11.1 built `HttpClient` behind a `Transport` seam and asserted its policy as a value: the
ssl options it *would* set, the `follow_location` it *would* ask for, the timeouts it *would*
carry. That is the honest limit of a unit test with no network, and it was written down at the
time as T-07's job. This item did that job, and the first test written found a defect.

## The defect

`fopen()` exposes response headers through `stream_get_meta_data()['wrapper_data']`. For one
exchange that is a status line then its headers, and the transport read it as exactly that.
**When the wrapper follows a redirect, the array holds the whole chain** — `302`, its headers,
`200`, its headers — and the body belongs to the last one.

So the response object described a response that no longer existed: `302` with the target's body
(`isSuccessful()` false for a successful fetch), a chain ending in `404` reported as `302`, and
the hops' headers merged under one name — `header('Set-Cookie')` answering from the hop that had
been left behind, which in a login flow is exactly the header someone reads.

Redirect-following is opt-in and off by default, which is why no unit test saw it: with
`follow_location => 0` there is only ever one status line, and a fake transport is handed a
synthetic array shaped like the happy case. Fixed here rather than documented as expected
behaviour (§10 does not permit the deferral, and a test recording a defect is not a guard against
one) — **ADR-0052**, spec r15, with a regression test asserting both directions, because only the
pair separates "reports the last hop" from "reports whatever is final".

## What the origin could prove that a value could not

Two of these are the reason the item carried the security floor:

- **A self-signed certificate is refused** — against a certificate this suite generates, with a
  control read in the same test. Without the control, a refusal would pass just as well against
  an origin that never started.
- **A process-wide `verify_peer => false` cannot weaken the client.** ADR-0049 was written around
  that hijack; here the hijack is performed — proved effective on a raw read in the same test —
  and the client still refuses.
- **The wall-clock ceiling ends a dripping origin.** The per-phase timeout provably cannot: every
  window delivers a byte and re-arms it. Measured stopping at 1.02 s against a budget of 1.0 s.
- **A refused redirect does not travel**, proved by a target that records its own visits rather
  than by reading the response.
- 40 KiB of binary across five read chunks, repeated `Set-Cookie` values kept separate, HTTP/1.1
  on the wire (the wrapper's own default is 1.0), and the request body arriving unchanged.

## Three process findings

1. **`git checkout -- <file>` restores from the index.** Planting a defect in a file whose own
   fix is still unstaged deletes the fix, not the plant — and the suite then goes green for the
   wrong reason. Stage your work before a planted-defect campaign. (The other half of item 10.4's
   untracked-files lesson.)
2. **A flake gets root-caused, not retried.** The TLS leg failed about two runs in five. The
   message — "HTTP request failed!" at 25 ms, after a successful handshake — said the origin was
   closing before the response arrived: my fixture never read the request, and closing a socket
   that still holds unread inbound bytes resets the connection and destroys what was written.
   Draining the request first: 6/6 green, from 3/5. **The defect was in the fixture, not the
   library**, which is worth stating plainly — the alternative diagnosis was "flaky TLS on
   Windows", and it would have been wrong.
3. **A suite name was already taken.** `T-07` was a group tag on `RequestTest`/`ResponseTest`
   (item 6.1, ADR-0025) — chosen when the spec defined only T-01…T-05, so the name belonged to
   nothing. Spec r3 then defined T-07 as this suite, and `--group T-07` began returning 86 tests
   across three unrelated classes: the spec's named suite was no longer the countable unit item
   2.6 established. The spec owns the vocabulary, so the tag was removed there and ADR-0025
   annotated rather than rewritten.

8 planted defects, 8 caught — including the two that separate this suite's two halves: reverting
the redirect fix fails the status assertion, and keeping the status fix while retaining the hops'
headers fails the leaked-`Location` one.

## Lesson

A value that describes a refusal is not a refusal. Every guarantee this client makes about TLS
survived a unit suite unchallenged, and only the first certificate that failed verification could
say whether the guarantee held — and, more usefully, whether it held while something else in the
process was trying to switch it off.
