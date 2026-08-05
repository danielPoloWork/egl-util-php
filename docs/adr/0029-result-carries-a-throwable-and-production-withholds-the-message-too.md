# ADR-0029: A `Result` failure carries a throwable, `map()` does not catch, and production withholds the message as well as the trace

- **Status:** Accepted
- **Date:** 2026-08-05
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 6.5 · spec FR-16, FR-17, FR-18, FR-24 ·
  [RFC-0001](../rfc/0001-egl-utils-library.md) (the error model) ·
  [ADR-0004](0004-root-the-exception-hierarchy-on-an-interface.md) (the hierarchy a failure carries) ·
  [ADR-0026 §1](0026-session-hardening-as-a-value-and-csrf-through-a-seam.md) and
  [ADR-0022](0022-argon2id-by-default-with-a-fallback-decided-at-construction.md) (policy as a value,
  applied again) · [ADR-0028](0028-container-exceptions-live-in-the-container-group-and-get-carries-a-type.md)
  (the `Psr` layer grant, extended here to `Errors`)

## Context

Milestone 6's last item builds three small classes, and each has one decision in it that is not
obvious. Two of them were settled by probing PHP rather than by reasoning, and both would have
produced silent data loss.

## Decision

### 1. A `Result` failure carries a `Throwable`, not an arbitrary error value

`orElseThrow()` has to throw *something*. With a generic error type, the exception must be
manufactured at the moment someone unwraps — which puts the stack trace in the accessor rather than
at the point the operation failed, discarding the only part of a trace anybody reads.

Constructing the throwable at the failure site keeps it, and the library already has a hierarchy for
exactly this (ADR-0004). `orElseThrow()` rethrows the **same instance**, which a test pins by
asserting the caught object's identity *and* its original line number.

### 2. `map()` does not catch; `Result::try()` is the opt-in

A `Result` models an **expected** failure. A mapper that throws has a defect, and silently turning a
`TypeError` into a business failure would hide it at the point it is cheapest to find — the caller
would see "the operation failed" and go looking in the domain logic.

`Result::try()` exists for the boundary where a throwing API meets code that wants outcomes. Naming
it means the catching is visible at the call site instead of being an ambient property of every
`map()` in the codebase.

A consequence worth noting for anyone writing tests against this: `failure()` returns
`Result<never>`, so PHPStan correctly proves every later step in a chain unreachable. That is true
and useful, and it means a chain built for a test needs its failing step declared on a *method* —
where PHPStan honours the `@return` — rather than inline.

### 3. There is no `instanceof` guard in `flatMap()`

A callable that forgets to return a `Result` is already refused by the native `: self` return type,
with `Return value must be of type Result, string returned` — verified. A hand-written check would
restate that less clearly and, being unreachable from any analysed caller, would sit uncovered
forever. PHP's own type system is the better guard here.

### 4. `Logger` locks real files and must **not** lock streams

`file_put_contents()` with `LOCK_EX` on a `php://` stream returns **`false` and writes nothing** —
probed, not assumed. A logger that locked unconditionally would silently discard every record sent
to the console, which is precisely the destination a developer uses when they want to watch what is
happening.

There is a functional test for this rather than a comment: writing to `php://output` under an output
buffer, which comes back empty the moment the lock is applied unconditionally.

### 5. A `Throwable` in the log context is converted explicitly

`json_encode()` serialises an object's *public* properties. An exception has none, so a throwable
passed through untouched encodes to **`{}`** — verified. No error, no warning: every detail gone,
from the one record anybody would want to read. Worse than a failure, because it looks like success.

So the context is walked and throwables are converted to arrays carrying class, message, code,
`file:line`, trace, and the same treatment applied recursively to `getPrevious()`.

The trace **is** included here. A log is server-side, which is the one place a trace belongs — and
the deliberate contrast with §6 is the point of having both classes.

### 6. `Logger` validates its destination at construction and never throws when writing

PSR-3 permits a throw only for an invalid level, and the reason is concrete: a logger that throws
while an exception handler is using it converts a handled failure into a fatal one. So an unwritable
destination is refused at **wiring** time, where the stack trace points at the misconfiguration
instead of at the incident the logger was meant to record. After that, writes are best-effort.

An unknown level does throw `Psr\Log\InvalidArgumentException`, as PSR-3 requires — logging it at
some guessed severity would make it subject to a filtering rule nobody wrote.

### 7. `ExceptionHandler`'s problem document is a pure value

`problem()` is a function of the throwable and the debug flag, with no I/O. That is what makes the
security property testable at all: `http_response_code()` warns that headers are already sent when
called inside PHPUnit — the suite runs `failOnWarning`, so a test that emitted a response would fail
for reasons unrelated to the code. Same split as ADR-0026 §1 and ADR-0022, for the same reason.

The fatal-error predicate is extracted for the same motive: the shutdown closure cannot be reached
from a test, because a real fatal error takes the process with it, so `isFatal()` is public and
tested directly. Verified that a fatal `require` does surface through `error_get_last()` as
`E_ERROR`.

### 8. Production withholds the **message** as well as the trace

FR-18 says *"never leaks traces in production mode"*. A message leaks just as effectively:

- `SQLSTATE[42S02]: Base table or view not found: 'users_backup'` names a schema.
- `failed to open stream: /srv/app/config/secrets.php` names a path.

Nothing in a throwable says whether its message was written for an end user, so the safe reading of
"never leaks" withholds both and emits a **reference** — a random identifier that also goes into the
log record, so an operator can join response to detail with one `grep`.

This is stricter than the specification's letter, and deliberately so; it is recorded here rather
than assumed. An application that wants a particular message shown should catch that exception and
say so itself, which is a judgement it can make and this class cannot.

Debug mode is **off** unless the environment says otherwise, via a separate `fromEnvironment()`
constructor: a missing `APP_DEBUG` yields production behaviour, so forgetting to set it cannot be
what exposes a trace. `Env::get()` already coerces `'false'` correctly (FR-24), and a test pins that
the string `'false'` does not enable debug.

### 9. HTTP status mapping is explicit and starts empty

Nothing in a library can know that an application's `UserNotFound` means 404. Guessing from
`getCode()` would be worse, since a code is as likely to be `0` or a driver's `SQLSTATE` as an HTTP
status. So the map is a constructor argument, and anything unlisted is a 500.

## Alternatives Considered

- **A generic error type `Result<T, E>`** — rejected in §1: `orElseThrow()` would have to manufacture
  an exception at unwrap time, losing the failure site.
- **`map()` catching throws** — rejected in §2: it hides defects as outcomes. `try()` is the honest
  form.
- **An `instanceof` check in `flatMap()`** — rejected in §3; PHP's return type already does it.
- **`LOCK_EX` unconditionally** — rejected in §4 on probe evidence: it silently discards stream
  records.
- **Passing the log context straight to `json_encode()`** — rejected in §5 on probe evidence: `{}`.
- **A logger that throws on write failure** — rejected in §6: it escalates the incident it is
  reporting.
- **Multi-line log records with a raw trace** — rejected: a log that sometimes spans lines cannot be
  read with `grep` or `tail`, and that stops being true exactly when someone is in a hurry. JSON
  escapes the newlines, so the trace survives on one line.
- **Showing the exception message in production** (the specification's literal reading) — rejected in
  §8, and flagged to the maintainer as stricter than the letter of FR-18.
- **Deriving the HTTP status from `getCode()`** — rejected in §9.
- **`ExceptionHandler` reusing `Http\Response`** — not available: `Errors` may depend only on
  `Support` (and now `Psr`), and reaching into `Http` is the cross-group import RFC-0001 forbids. It
  writes its own two headers.

## Consequences

- 64 tests across the three classes: 17 `Result`, 19 `Logger`, 28 `ExceptionHandler`.
- **Verified non-vacuous:** inverting the production branch fails 5 tests; `LOCK_EX` applied
  unconditionally fails 1 — the one written for it; dropping the throwable conversion fails 2;
  making `map()` catch fails 1.
- `Errors` gains the `Psr` layer grant, for the same reason `Container` did in ADR-0028.
- Coverage measured at **90.96%** overall (1047/1151). `Result` and `Logger` are both at **100%**;
  `ExceptionHandler` is at **69.12%** (47/68).
- Those **21 uncovered lines** are `handle()`, `register()`, and the two closures `register()`
  installs. An earlier draft of this section called it "four and six statements", which understated
  it by half — the shutdown closure that builds an `ErrorException` from `error_get_last()` is itself
  most of the total. The reason it cannot be reached is unchanged and is the reason `isFatal()` was
  extracted: a real fatal error takes the process with it, and `http_response_code()` warns inside
  PHPUnit under `failOnWarning`. Every *decision* those lines act on — the status, the document, the
  reference, the fatal classification — is covered. But the honest figure is 21, not 10, and a
  handler's own emit path is a fair thing for a future item to want under an integration suite the
  way T-03 covered `Session`.

## References

- Spec FR-16, FR-17, FR-18; FR-24 for the boolean coercion `fromEnvironment()` relies on
- RFC 7807 for the problem-document shape
- Probed on PHP 8.3.1: `LOCK_EX` on `php://` returns `false`; `json_encode(new RuntimeException)`
  yields `{}`; a fatal `require` surfaces as `E_ERROR` via `error_get_last()`; a native `: self`
  return type refuses a wrong return with a clear `TypeError`
