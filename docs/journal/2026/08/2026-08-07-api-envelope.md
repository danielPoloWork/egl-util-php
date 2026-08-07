# 2026-08-07 — Three envelopes become one, and the exception stays home

Roadmap item **11.3**, size S, `standard / medium` — **the first item this milestone whose route
matches the session model**, so no mismatch to record.

Mostly transcription: FR-39 fixes the shape (`status`, `code`, `messages`, `data`), the nine
outcomes and the rule that message strings are the caller's. The estate had three implementations
of this, 232+ construction sites, and no two agreeing on field names — so a client written against
one could not read another.

Two things in it were decisions rather than transcription.

## `caught()` does not take a `Throwable`

The obvious signature is `caught(Throwable $e)`, and the estate's envelope had it. It puts
`$e->getMessage()` on the wire.

ADR-0029 already settled that production withholds the **message as well as the trace**, because a
message names schemas, file paths and query fragments as readily as a stack trace does. An
envelope that accepts the exception either duplicates that decision — with an env check of its own,
in a second place — or leaks by default when the configuration is wrong.

So it takes a **correlation reference**: the client gets an id, the exception goes to the log under
the same id, and `ExceptionHandler` already produces exactly that pairing. The signature cannot
receive what it must not send.

That is asserted as a **mechanism**, on the method's reflected parameters, because no behavioural
test can see an overload that does not exist — a future `caught(Throwable $e)` would pass every
other test in the file. Same reasoning as ADR-0027, and the third time this project has needed it.

## Two status codes worth arguing about

`Invalid` is **422**, not 400. The request was well-formed and understood; a client can then tell a
malformed request from a rejected one without reading the body.

`Empty` is **200**, not 404. A search with no results is a successful search. The estate 404-ed
empty collections, which is how clients learn to treat "no rows" as a failure worth retrying.

Neither is forced by the spec, so both are in the ADR with their reasons rather than sitting
silently in a `match`.

## The shape is the product

Every key is serialized on every outcome, including `data: null` and `messages: []`. The test that
matters most here is the dull one — `"data":null` present in the JSON — because the "tidy up the
payload with `array_filter`" instinct is real, it is invisible in PHP, and it breaks every client
that reads `payload.data` without a guard.

`messages` is also pinned to encode as `[]` rather than `{}`: PHP's empty array is ambiguous until
something asserts which one it becomes.

## What stayed out

`Result` → envelope. `Errors\Result` is in another group, RFC-0001's layering rule forbids `Http`
importing it, and the adapter is three lines. It went in the endpoint-kernel pattern doc instead of
the library — the same call ADR-0050 made for status codes: the library supplies the vocabulary,
the application supplies the policy.

## Lesson

**A signature is a cheaper security control than a conditional.** `caught(Throwable)` with an
env-gated message is one misconfiguration away from disclosure; `caught(string $reference)` is
never one line away from it. When the safe behaviour can be made structural rather than
conditional, the structural version does not need to be got right twice.
