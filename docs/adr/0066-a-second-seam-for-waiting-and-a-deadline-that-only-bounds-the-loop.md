# ADR-0066: A second seam for waiting, and a deadline that only bounds the loop

- **Status:** Accepted
- **Date:** 2026-08-21
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item **14.5** · issue [#94](https://github.com/danielPoloWork/egl-util-php/issues/94) ·
  spec **r21 FR-49** · [RFC-0003](../rfc/0003-post-1-0-functional-scope.md) (the design this
  realizes) ·
  [ADR-0049](0049-state-the-transport-policy-explicitly-and-bound-the-whole-request.md)
  (the wall-clock finding §3 inherits, and the limit it draws) ·
  [ADR-0062](0062-the-clock-seam-ships-both-halves-and-support-gains-its-first-outward-edge.md)
  (the clock seam, and the ship-both-halves rule §1 follows) ·
  [ADR-0027](0027-constant-time-comparison-is-asserted-by-mechanism-not-by-timing.md)
  (why §2's and §4's assertions are mechanisms) ·
  [ADR-0029](0029-result-carries-a-throwable-and-production-withholds-the-message-too.md)
  (the logging boundary §5's observer respects) ·
  [ADR-0022](0022-argon2id-by-default-with-a-fallback-decided-at-construction.md)
  (the dead-defensive-code precedent §6 follows)

## Context

Retry-with-backoff is ad-hoc per-project code of exactly the class this library exists to replace,
and the 2026-08-09 review board named the three ways it is usually wrong: **no jitter**, so every
client that failed together retries together and the retry storm is the outage; **retrying
non-retryable failures**, because a `400` will not become a `200`; and **unbounded total time**.

FR-49 asks for a policy value object covering all three, with the delay "consumed through the clock
seam so tests never sleep". That last phrase is where the requirement met a fact about PSR-20, and
the fact won.

## Decision

### 1. Waiting is a second seam, and both halves ship

`Psr\Clock\ClockInterface` answers *what time is it*. Nothing in PSR-20 answers *wait a while* —
there is no `sleep()` on the interface, and there was never going to be one. So "delay is consumed
through the clock seam" is not implementable as written: a clock can measure a deadline, and it
cannot spend a backoff.

`Support\Sleeper` is that second seam, with `SystemSleeper` (the one place milliseconds become
`usleep()`'s microseconds) and `FrozenSleeper` (the double). Both ship, for ADR-0062's reason
verbatim: a seam whose production half is the only one published makes every project write its own
double, and they all write it slightly differently.

**`FrozenSleeper` advances the `FrozenClock` it is given, by exactly what it was asked to wait.**
That coupling is the whole design and not a convenience. A double that merely recorded the request
would leave time standing still, so no deadline could ever be reached, and every deadline assertion
in the suite would pass while asserting nothing. Advancing the clock is what makes *"tests never
sleep"* and *"the deadline is exercised"* the same run rather than a trade between them.

**Planting the missing advance is what proved it — and the first attempt at that proof failed, which
is the more useful half of the story.** Every deadline test written for this item moved time from
*inside the operation*, so none of them depended on the sleeper advancing anything: dropping the
advance left the whole `Retrier` suite green and reddened only the sleeper's own file. The coupling
was real and the tests were not resting on it, which meant the case where **the backoff itself
spends the deadline** — a fast-failing dependency behind a generous attempt count, no single attempt
slow — had no coverage at all. That test now exists, asserts elapsed wall clock equals the sum of
recorded waits to the millisecond, and is the one the plant reddens. The plant found a coverage gap,
not a defect; it is recorded because a claim about a seam that no test rests on is the shape
BUG-0001 took one item ago.

Milliseconds throughout the feature's API, with the single conversion to microseconds inside
`SystemSleeper`. A duration crossing two units in two places is how one of them ends up a thousand
times wrong, and the test that catches that particular slip has to measure real elapsed time — the
only assertion in this feature that does.

### 2. The policy decides; it does not act — and jitter is not a flag

`RetryPolicy` is pure: no clock, no sleeping, no infrastructure. `Retrier` executes under it. A
policy stays a configurable *value* a deployment builds once and passes around, rather than a
service carrying two collaborators.

`delayFor()` always randomizes, and **there is no argument that turns it off**. This has to be
structural rather than a well-behaved default, because behaviour cannot see it: an implementation
returning the un-jittered exponential satisfies every assertion of the form *"the delay is between
zero and the ceiling"* — the plain value is inside its own band. What catches its absence is a
distribution test (300 draws must not collapse to one value; a correct implementation doing so has
probability `(1/1601)^299`) plus a **mechanism** assertion per ADR-0027: no constructor parameter
names jitter, `delayFor()` contains the full-jitter draw verbatim, and `delayFor()` contains no
conditional at all — a branch is where a bypass would live.

**Full jitter** — `random_int(0, ceiling)` — over the "equal jitter" variant that keeps half the
delay fixed. Full jitter decorrelates maximally, which is the entire point of having jitter. The cost
is stated rather than hidden: a draw can come back at or near zero, so one retry may follow almost
immediately. Bounding *that* is the attempt count's job and the deadline's, not the delay's.

### 3. The deadline bounds the loop, and cannot bound an attempt

ADR-0049 found that PHP's per-phase stream timeout **re-arms**, and therefore bounds no request: a
dripping origin outlasts it forever. FR-49 exists because an attempt *count* bounds no retry loop for
the same reason — three attempts against a thirty-second hang is a ninety-second call.

The honest limit belongs in the same breath, and this ADR is where it gets written down: **a deadline
cannot end an attempt that is already running.** Control is inside the caller's operation, and
`Retrier` gets it back only when that operation returns or throws. So what the deadline guarantees is
that no *new* attempt begins past it. An operation that hangs forever still hangs forever, and
bounding that is the operation's own business — `HttpClient`'s wall-clock deadline, from ADR-0049, is
exactly the tool. **A loop deadline over an unbounded attempt is the same shape of false comfort
ADR-0049 removed, one level up**, and a consumer who reads only the parameter name would take the
wrong assurance from it.

**A delay that would not fit inside the deadline ends the loop rather than being shortened.** Sleeping
the remainder and attempting anyway leaves the attempt no time to succeed; shortening the backoff to
fit means retrying sooner than the policy says, at precisely the moment the evidence says the
dependency is struggling. Behaviour can see that the loop stopped but not *why* it stopped, so the
absence of a clamp is asserted as a mechanism too: exactly one expression becomes a sleep, and it
comes from `delayFor()`.

### 4. Accepted bounds are typed plainly; returned ones are typed narrowly

Every numeric bound on `RetryPolicy::of()` is `int`, not `positive-int` or `int<0, max>` — and that is
a decision, not an omission. A `positive-int` parameter makes the `< 1` refusal beneath it
unreachable from type-correct code, so the only way to test the guard is an analyser suppression this
project forbids. The narrow types live on what the class *returns* (`maxAttempts(): positive-int`,
`deadlineMs(): positive-int|null`), where they help a consumer and cost nothing.

The same division applies to `$retryable`: `list<class-string>` at the public entry point, with
`is_a(..., Throwable::class, true)` as the enforcement, because an allowlist usually arrives from
configuration where nothing has checked it. `SecretKey` makes this same split for its key length, and
for the same reason — the runtime check is the mechanism, so it has to be reachable.

**The general rule this settles for the project: a bound that carries a runtime refusal must not also
be a static range type, or the refusal becomes untestable.** Where the static type *is* the mechanism
— `SqlStatement`'s `literal-string` under a private constructor, ADR-0041 — the opposite holds, and
there is no runtime check to strand.

### 5. Retry is transparent to the caller's error handling

When the attempts or the deadline run out, the **last failure is rethrown as it was**, not wrapped. A
wrapper would force every caller who already catches `HttpClientException` to catch something else
and unwrap it — a breaking change to their code, arriving disguised as a feature.

What retrying happened is reported through an optional `$onRetry` observer instead, which also keeps
`Support` clear of `Errors`: the caller logs, in the place the decision to log belongs (ADR-0029).
The observer reports waits, so it is not called for the failure that ends the loop — that failure's
report is the exception.

A non-retryable failure propagates immediately, on the attempt it happened, with no delay spent.

### 6. No circuit breaker, stated because its absence is a choice

Issue #94's acceptance criteria ask for the non-goal in writing. A breaker is shared state across
calls, with its own failure window and half-open probing; this is a per-operation policy. Wiring one
in would make every `Retrier` a stateful service and is a separate feature, not a parameter.

`FrozenSleeper` builds its advance by setting `DateInterval::$f` rather than calling
`DateInterval::createFromDateString()` — probed, both are honoured to the microsecond, but the latter
is typed `DateInterval|false` and its `false` branch cannot fire on a string this class builds
itself. That is the dead defensive code ADR-0022 removed from `Hash` and item 12.1 removed from
`Crypto`.

## Alternatives Considered

- **Injecting only a clock and calling `usleep()` directly** — rejected in §1. It satisfies the
  requirement's wording and defeats its purpose: every retry test would run in real time.
- **A `callable` sleep seam instead of an interface** — rejected in §1. There is precedent for
  closures as seams here (`File::update()`, `Transaction`), but ADR-0062's argument is about a
  *sanctioned* seam nobody should re-implement, and a named interface with both halves shipped is
  what makes that true.
- **A test double that records without advancing the clock** — rejected in §1, and the reason is
  worth keeping: it would make the deadline suite vacuous rather than wrong, which is the harder
  failure to notice (BUG-0001, one item ago).
- **Equal jitter**, keeping half the delay fixed — considered in §2 for its guaranteed floor;
  rejected because a floor is what the attempt count and deadline already provide, and it
  decorrelates less.
- **A `jitter: false` switch** — rejected in §2. It is the failure mode the requirement exists to
  prevent, one deployment away.
- **`RetryPolicy::execute()`, folding the loop into the value object** — rejected in §2: a value
  object performing I/O, and every policy would then carry a clock and a sleeper.
- **Leaving the loop to each consumer** — rejected in §2 for the opposite reason: it moves the ad-hoc
  code rather than replacing it, which is the whole complaint in issue #94.
- **Clamping a too-long delay to the remaining budget** — rejected in §3.
- **Wrapping the last failure in a `RetryExhaustedException`** — rejected in §5. It would carry the
  attempt count usefully, and break every existing `catch` to do it.

## Consequences

- `Support\{Sleeper, SystemSleeper, FrozenSleeper, RetryPolicy, Retrier}`. **No new deptrac rule** —
  `Support → Psr` has been granted since ADR-0062, so the clock costs nothing architecturally (391
  allowed, 0 violations, 0 uncovered). Stated because an absence leaves no trace, item 12.3's rule.
- **No new exception type**, so `ExceptionHierarchyTest` is untouched: construction validation raises
  PHP's `InvalidArgumentException`, matching `Str::random()`'s precedent for programmer error, while
  `UtilsException` stays for runtime failures. Worth saying, because RFC-0003 anticipated a
  `UtilsException` descendant here and none was needed.
- **The library still never retries on its own.** `HttpClient` and transaction callers consume this
  opt-in; nothing was wired implicitly, because a non-idempotent operation retried once is a
  duplicate write.
- **16 planted defects, 16 caught** — but only after two of them changed the work. Clamping the delay
  to the remaining budget instead of stopping is caught by **exactly one** test, and it is a
  mechanism assertion: behaviour sees that the loop stopped, never why. Removing the jitter is caught
  by the distribution test and the mechanism assertion and by nothing else, which is ADR-0027's
  premise demonstrated again rather than asserted. And dropping `FrozenSleeper`'s clock advance
  initially reddened only the sleeper's own file — see §1: it exposed a missing test rather than a
  missing guard, and the test it forced is now the only one that rests on the coupling §1 argues for.
- **A process finding, recorded because it cost real time twice in two items.** The plant harness
  timed out mid-campaign and left a defect on disk, because its restore only runs at the end. The
  fix that generalizes: a campaign must restore *before* each plant as well as after the last one,
  and the run belongs in the background where a timeout cannot kill it halfway. Item 14.4 already
  learned that `git checkout --` restores from the index and therefore needs the tree staged first;
  this is the same lesson's other half — **the harness itself is code, and a harness that can fail
  halfway must be idempotent.**
- No NFR budget. RFC-0003's reasoning for the clocks applies unchanged — a number here would bound
  PHP's own method dispatch, as NFR-14's control subject showed — and ADR-0040 reserves spec numbers
  for the maintainer regardless.
