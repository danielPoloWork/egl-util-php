# ADR-0022: Argon2id by name, not by `PASSWORD_DEFAULT`, with the fallback decided at construction

- **Status:** Accepted
- **Date:** 2026-08-04
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 5.3 · spec FR-11, NFR-05, NFR-08 · item 5.5 (the fallback matrix and
  NFR-05 timing, which this item deliberately does not pre-empt) ·
  [ADR-0004](0004-root-the-exception-hierarchy-on-an-interface.md) ·
  [ADR-0019](0019-four-escaping-contexts-and-the-unquoted-attribute-assumption.md) /
  [ADR-0021](0021-delegate-rich-html-and-escape-like-wildcards-with-a-portable-character.md) (the
  static `Security` helpers this one deliberately is not) ·
  [ADR-0012](0012-enforce-the-layering-rule-by-directory-over-src-main.md)

## Context

Spec FR-11: *"password_hash wrapper, Argon2id default; availability via
`defined('PASSWORD_ARGON2ID')`; bcryptFallback: true default logged at WARNING or false to fail
fast; self-describing hashes; needsRehash() upgrades on login."*

Probed against PHP 8.3.1 before designing:

| probe | result |
|---|---|
| `PASSWORD_DEFAULT` | **`'2y'` — bcrypt**, even though Argon2id is available |
| `password_hash()` with an unknown algorithm | raises a bare `ValueError` |
| `password_needs_rehash($bcryptHash, PASSWORD_ARGON2ID)` | `true` — upgrade correctly detected |
| `password_verify()` against `''` / `'not-a-hash'` | `false`, no exception |
| `password_needs_rehash('not-a-hash', …)` | `true` |

The first is the one that shapes the class. `PASSWORD_DEFAULT` reads like *"whatever PHP thinks is
strongest"* and is bcrypt on every release to date. Code reaching for it expecting Argon2id gets
the weaker algorithm silently, which is exactly why FR-11 names Argon2id rather than deferring.

## Decision

### 1. `Hash` is an instance, breaking with the rest of the `Security` group

`Escaper` and `Sanitizer` are static, and are pure functions of their input. Hashing is not: it
carries a **policy** (what to do when Argon2id is unavailable) and a **collaborator** (the logger
that policy announces itself through). Threading both through static calls means either global
mutable configuration or repeating them at every call site — and configuration that can change
halfway through a request is the wrong shape for a security decision.

RFC-0001's API contract writes `Hash::make()/verify()/needsRehash()`, but its `::` notation is
loose in the same list (`QueryBuilder::orderBy()` is an instance method), so this is not a
departure from the spec's signatures.

### 2. Argon2id is named explicitly; `PASSWORD_DEFAULT` is never used

Pinned by a test that asserts `PASSWORD_DEFAULT === PASSWORD_BCRYPT`, so the day PHP changes it,
the suite says so rather than the behaviour quietly shifting.

### 3. Availability is `defined('PASSWORD_ARGON2ID')`, checked *before* use

Argon2 support is a compile-time option, so the constant's absence is the honest signal. The check
happens before `password_hash()` rather than as a rescue afterwards, because an unknown algorithm
raises a bare `ValueError` — outside ADR-0004's family, and something a caller catching
`UtilsThrowable` would miss.

### 4. The fallback decision is made **once, at construction**

- `bcryptFallback: false` → an unavailable Argon2id raises immediately. A misconfigured deployment
  fails while it is being *wired*, not the first time a user tries to register. That is what "fail
  fast" has to mean to be worth anything.
- `bcryptFallback: true` (FR-11's default) → one WARNING at construction, not one per `make()`. A
  warning emitted on every password hash is one that gets filtered out, and the signal is lost in
  the volume it generates.

### 5. The fallback is a **value**, not only a log line

`algorithm()` returns what will actually be used. The logger is optional — requiring a PSR-3
implementation to hash a password is a heavy demand for a utility — but that would make the
fallback undetectable in a deployment without one. Exposing it as a value means a health check can
assert on it, and the warning is not the only way to find out.

### 6. Cost parameters are PHP's own defaults

Spec NFR-05 budgets `make()` at 50–200 ms and calls the slowness deliberate. PHP's defaults are
chosen per release by people tracking hardware; overriding them here would mean this library owning
a number that has to keep moving. Item **5.5** owns the NFR-05 timing measurement.

## Alternatives Considered

- **A static `Hash` for consistency with `Escaper`/`Sanitizer`** — rejected in §1. Consistency of
  shape is not worth global mutable security configuration.
- **`PASSWORD_DEFAULT`** — rejected on the probe: it is bcrypt, and using it would silently
  contradict FR-11's central requirement.
- **Catching the `ValueError` from an unknown algorithm** instead of checking `defined()` first —
  rejected: it is FR-11's specified mechanism, and a rescue after the fact would mean the failure
  path runs through an exception type outside this library's family.
- **Failing at first `make()` rather than at construction** — rejected: it moves the discovery of a
  misconfiguration from deployment to the first user registration.
- **Logging the WARNING per `make()`** — rejected; see §4.
- **Requiring a `LoggerInterface`** so the warning can never be lost — rejected as too heavy for a
  utility, and mitigated by §5 instead.
- **Choosing cost parameters here** — rejected in §6.
- **Offering "use bcrypt" as a supported explicit option** (which would have made the fallback
  branch directly testable) — rejected: it turns the weaker algorithm into a first-class choice,
  and the testability gain is not worth advertising it.

## Consequences

- Upgrade-on-login works as FR-11 describes: a bcrypt hash stored before this deployment still
  verifies, reports `needsRehash()`, and is replaced with Argon2id — asserted end to end.
- **Weaker *parameters* under the same algorithm also trigger a rehash**, so moving to a PHP with
  stronger defaults upgrades users on next login with no change here.
- A malformed stored hash returns `false` from `verify()` and `true` from `needsRehash()` — the
  safe direction: it matches nothing, and cannot be left looking current.
- `psr/log` gets a deptrac layer (`Psr`), granted to `Security` only for now. It is a *required*
  interface-only dependency under RFC-0001 R-3, unlike `HtmlSanitizer`'s optional one, and more
  groups will need it (`Errors` implements PSR-3, `Container` PSR-11), so the ruleset grants it per
  group as each arrives. Verified by planting `Dto → LoggerInterface` and confirming the violation.
- **PHPStan at max removed defensive code that could not fire.** A guard on `password_hash()`
  returning `''` was flagged as an always-false comparison — the function is typed
  `non-empty-string` and lost its `false` return in PHP 8.0. It was deleted rather than suppressed:
  dead defensive code is worse than none, because it implies a failure mode that does not exist.
  Likewise an `assertTrue(defined('PASSWORD_ARGON2ID'))` guard became a `markTestSkipped`, since a
  guard that is statically true is not a guard.
- **The bcrypt-fallback branch is unreachable in-process, so the *policy* was extracted to make it
  testable.** `defined('PASSWORD_ARGON2ID')` is a compile-time fact no test can vary, so the
  fallback branch could not be executed at all. That was first documented as an accepted gap —
  and a deliberate probe (logging at `info` instead of `warning`) **passed**, proving the WARNING
  level was unasserted. It then also dropped total line coverage to **89.03%**, under ADR-0007's
  90% floor.

  Neither available shortcut was acceptable: lowering the floor is the exact failure this project's
  discipline exists to prevent, and a `@codeCoverageIgnore` would have hidden the most
  security-relevant branch in the class from measurement entirely. So `selectAlgorithm()` was
  extracted — the policy as a pure function of *"is Argon2id available"*, with the availability
  supplied as an argument. The fail-fast refusal, the bcrypt selection, the WARNING **level**, and
  the fact that refusing does *not* also log are now each asserted. Re-running the probe that had
  passed: it now fails.

  **The seam exposes the decision, not the weak algorithm.** There is no route to hashing with
  bcrypt on a build that supports Argon2id — the difference between making a policy testable and
  making it configurable, and the reason the rejected "offer bcrypt explicitly" alternative above
  is still rejected.
- Four planted defects each fail 1–6 tests: `PASSWORD_DEFAULT`, a `needsRehash()` stuck at `false`,
  a `verify()` doing raw string comparison, and the `info`-instead-of-`warning` probe that only
  became catchable after the extraction.
- Item **5.5** still owns the NFR-05 timing measurement and the wider fallback matrix; what this
  item closes is the policy's own correctness.

## References

- Spec FR-11 (the policy), NFR-05 (the timing budget item 5.5 measures), NFR-08 / RFC-0001 R-3
  (why `psr/log` is a permitted required dependency)
- ADR-0004 — the exception family the `ValueError` avoidance protects
- Verified directly on PHP 8.3.1: `PASSWORD_DEFAULT === PASSWORD_BCRYPT`, the `ValueError` on an
  unknown algorithm, and `password_verify`/`password_needs_rehash` behaviour on malformed input
