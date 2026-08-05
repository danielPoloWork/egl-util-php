# 2026-08-05 — Two silent losses, found by probing

Roadmap item **6.5**, the last of Milestone 6. Route `standard / medium` → Opus 5, the session model.
No mismatch.

Three small classes. The interesting part is that two of the decisions were settled by probing PHP,
and both defaults would have lost data without saying a word.

## `LOCK_EX` on a stream writes nothing

```
php://stdout + FILE_APPEND           -> wrote 9 bytes
php://stdout + FILE_APPEND | LOCK_EX -> false, wrote nothing
```

A logger locking every write — which is the obviously-correct thing to do for a file — would discard
**every console record in silence**. Not an exception, not a warning; `file_put_contents()` returns
`false` and nobody checks a logger's return value, because a logger that made you check would be
useless.

So real files get the lock, `php://` destinations do not. And there is a functional test rather than
a comment: write to `php://output` under an output buffer and assert the text is there. Applying the
lock unconditionally makes that buffer come back empty, which is exactly one test failing for exactly
the right reason.

## A throwable in a log context encodes to `{}`

```php
Json::encode(["e" => new RuntimeException("secret detail here", 42)])
// {"e":{}}
```

`json_encode()` serialises *public* properties. An exception has none. So the one record anybody
would ever want to read — the one with the exception in it — silently becomes an empty object.

I had half-expected this to *throw*, which would have been fine; something that fails loudly gets
fixed. What it actually does is succeed and lose everything, which is the failure mode this project
keeps running into: the code did something reasonable and the result read as confirmation.

Throwables now get walked into arrays explicitly, recursively through `getPrevious()`. The trace is
included, because a log is server-side and that is the one place a trace belongs.

## Which sets up the decision I want on the record

`ExceptionHandler` does the opposite, and the contrast is the point of having both classes.

FR-18 says *"never leaks traces in production"*. It says nothing about messages — but

```
SQLSTATE[42S02]: Base table or view not found: 'users_backup'
failed to open stream: /srv/app/config/secrets.php
```

leak a schema and a path respectively, and nothing in a throwable tells you whether its message was
written for an end user. So production withholds **both**, and emits a random reference that also
goes into the log line, so an operator joins the two with one `grep`.

That is stricter than the spec's letter. I've flagged it rather than quietly implementing it — an
application that wants a specific message shown can catch that exception and say so, which is a
judgement it can make and this class cannot.

The security assertion works at all because `problem()` is a **pure value**. It has to be:
`http_response_code()` inside PHPUnit warns that headers are already sent, and the suite runs
`failOnWarning`, so a test that emitted a response would fail for reasons unrelated to the code.
Third item running where that same split — policy as a value, I/O kept thin — was the thing that
made the important property testable.

## PHPStan taught me something about my own generics

`Result::failure()` returns `Result<never>`. That is correct, and it means PHPStan proves every later
step in a chain unreachable — so my "a failure mid-chain skips every later step" test could not be
written with typed closures at all. Three attempts:

1. `@return Result<int>` on a closure — not honoured there.
2. Route the failure through `Result::try()` with a `: int` callable — PHPStan infers `never` from a
   body that only throws, so no better.
3. A private helper method with `@return Result<int>` — honoured, and the runtime behaviour is
   identical.

Worth recording because the first two looked like they should work, and the reason they don't is a
fact about where PHPStan reads generics rather than anything about the code under test.

One smaller thing in the same direction: I had written an `instanceof` guard in `flatMap()` for a
callable that forgets to return a `Result`. PHPStan flagged the branch as unreachable, and it was
right for a better reason than it knew — the native `: self` return type already refuses it, with
`Return value must be of type Result, string returned`. The guard would have restated that less
clearly and sat uncovered forever. Deleted.

## Bar

1299 tests / 2916 assertions green (up from 1235). PHPStan max clean, deptrac 0/0, PHP-CS-Fixer
clean, consistency and action-pin lints OK.

Probes: production branch inverted → 5 failures; unconditional `LOCK_EX` → 1; throwable conversion
dropped → 2; `map()` made to catch → 1.

## Next

**Milestone 6 is complete.** Milestone 7 opens with **7.1** — the phpbench nightly CI harness, which
is where three deferred measurements finally get a reference machine: NFR-01's absolute half, NFR-03's
residual, and NFR-05's range.
