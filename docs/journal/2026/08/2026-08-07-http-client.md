# 2026-08-07 — The default that is not ours to trust

Roadmap item **11.1**, opening Milestone 11. `HttpClient`: the replacement for the estate's
three-line helper that rebuilt every address as `"http://{$host}{$path}"` and read it with no
timeout — a forced plaintext downgrade and an unbounded hang, together.

Four probes ran before any code. Two of them changed what the item could promise.

## "TLS verification on by default" was the wrong guarantee

The item said it, the spec said it, and PHP has verified certificates by default since 5.6, so
there was nothing obviously to check. I checked anyway, and a fresh `stream_context_create()`
turns out to carry **no `ssl` options at all**. Verification is therefore not our default — it
is the *process* default, and one line in a host application's bootstrap moves it:

```php
stream_context_set_default(['ssl' => ['verify_peer' => false]]);
```

Measured: after that call, a context we build reports `ssl: none` and inherits the weakened
policy. Measured also: an option we state **wins** over the default. So the client writes its
TLS policy out on every request. "On by default" would have been true of PHP and false of this
library, which is the worst kind of true.

## "Explicit connect/read timeouts" was not implementable

PHP's http wrapper has one `timeout`. Probed against a blackhole address with
`default_socket_timeout` at 5 s and `timeout` at 2 s: it returned at **2.01 s**, so the option
covers connect as well as reads. There are no separate values to expose.

Worse, it re-arms. Each read gets its own window, so an origin sending one byte per window holds
`file_get_contents()` open indefinitely — a timeout that bounds no request. So the client
carries **two** limits: the per-phase value PHP understands, and a wall-clock ceiling enforced
by a read loop in the transport. Verified against a server that answers and then stalls for four
seconds: the deadline fired at 1.5 s and the bytes already received were intact.

The spec said the unimplementable thing, so the spec was amended (r14) rather than the code
quietly diverging.

## What the tests can and cannot prove

Everything above is a property of the *context the client builds*, and a request that succeeds
against a cooperative server proves none of it. So the policy is exposed as a pure value and the
network sits behind a `Transport` seam — ADR-0026's shape for `Session`, for the same reason —
and the suite asserts the options actually handed over. TLS rejecting a real bad certificate, a
real timeout expiring, a real redirect not being followed: that is **T-07**, item 11.4, and this
item does not pretend otherwise.

## The plant that did not plant

Seven defects, seven caught — verification off, the `verify_peer` key deleted, the CRLF guard
disabled, the scheme allowlist opened, `ignore_errors` dropped, redirects defaulted on, the
timeout guard removed.

Except the CRLF one, the first time. My `sed` pattern did not match the file, nothing changed,
the suite went green, and **that looks exactly like a defect the tests caught**. I noticed only
because a header-injection guard failing to matter was implausible. Item 10.4 recorded this for
untracked files (`git checkout` failing silently); the general form is worse: *a plant that
never landed is indistinguishable from a plant that was caught.* Confirm the file changed before
believing the result.

## Lesson

**A default you did not set is not a guarantee you can make.** PHP's TLS default is good, and it
is also mutable process state that any dependency can change before your code runs. The
difference between "verification is on" and "we switch verification on" is invisible in a
passing test suite and total in a hostile deployment.
