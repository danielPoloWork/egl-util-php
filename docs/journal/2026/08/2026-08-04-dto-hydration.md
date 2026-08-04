# 2026-08-04 — Milestone 3 opens: DTO hydration, and PHP disagreeing with the requirement

Roadmap item **3.1**, the third `sets-pattern` item and the first component group to consume
`Support`. Route `frontier-reasoning / high`; the session ran on `opus-5` (standard tier) — the
mismatch was surfaced and the maintainer, who holds model authority (ADR-0017), proceeded.

## The finding: PHP does not agree with how R-4 is written

RFC-0001 R-4 says a property "neither nullable nor defaulted" is required, and that a nullable
or defaulted property absent from the payload "hydrates to `null`/its default". Read plainly,
that suggests treating nullable and defaulted the same way — omit the argument and let PHP sort
it out. Probed before writing a line:

| Construct | Result |
|---|---|
| named-argument spread, key omitted | PHP applies the declared default |
| `?string $x` with **no** default, argument omitted | **`ArgumentCountError`** |
| `int` where `float` is declared, under `strict_types=1` | **accepted**, widened |

**Nullable is not optional in PHP.** A `?string $x` with no default is a *required* parameter.
So R-4's "hydrates to null" only holds if `null` is passed **explicitly** — an implementation
that omits both cases raises `ArgumentCountError`, which is neither the documented behaviour nor
a library exception. The three absence cases are now resolved in a deliberate order, and a
parameter that is both nullable *and* defaulted takes its default, which is what a reader of the
declaration expects.

The third row shaped a different decision: `int` where `float` is declared is **accepted**,
because PHP performs that widening itself under `strict_types`. Refusing it would make this
library stricter than the language and reject payloads the constructor would have taken.

## Answering the question ADR-0006 deliberately left open

ADR-0006 declined to build a `shared()` accessor for the reflection cache: *"Milestone 3 will
have the real constraint in hand."* It does — `fromArray()` is static, and a static method has
no instance to inject into.

The resolution turned out cleaner than the hesitation implied. ADR-0006 worried about reset
semantics and testability; both dissolve because the cache memoises **immutable facts** — a
class's constructor cannot change while the process runs — so there is no stale state to
invalidate and no reset hook to design. What would have been a mutable global is a pure memo
table. The *mode* (strict/lenient) is per-call and never stored, and the suite asserts directly
that a lenient call does not leak into a later strict one.

That is the value of the deferral rather than a cost of it: guessing in Milestone 2 would have
produced a `reset()` in the production API for a problem that does not exist.

## Shape

Three classes: `DataTransferObject` (the base, with `fromArray()` and `lenient()`), `Hydrator`
(the engine, holding the shared cache and doing the recursion), `Hydration<T>` (the two-line
bound object `lenient()` returns). The alternative — `fromArray($data, lenient: true)` — costs
nothing to write and something to read: a bare `true` says nothing about which policy it selects.

Paths compose through recursion, which is what `HydrationException::path()` from item 2.1 was
built for and where it finally earns its place: `customer.address.postcode` is actionable in a
way "expected string, got int" is not. All three failure kinds are asserted at depth.

Construction is wrapped in a `catch (TypeError)` converted to `HydrationException` — the
backstop for anything the type checks did not anticipate. Without it a bare `TypeError` would
escape and break ADR-0004's "one thing to catch" contract for exactly the exotic cases nobody
tested.

## Proved non-vacuous

Three probes, each reverted and the implementation restored byte-identical:

1. **Disabled strict-mode rejection** (lenient becomes the effective default) → 5 failures.
2. **Treated an absent nullable as "omit" rather than "pass null"** → 3 errors, the
   `ArgumentCountError` the probe above predicted.
3. **Stopped composing paths in recursion** (leaf name only) → 3 failures, all the depth tests.

199 tests, 389 assertions (7 skipped, Windows-only). PHPStan max clean; `--group T-01` runs the
spec's named hydration matrix as its own unit.

## The coverage gate stopped my own code, and found a design defect

The floor from item 2.7 rejected the first push at **86.87%**, `Hydrator` at 73.75%. Reading
what was uncovered turned up more than a testing gap: `satisfiesBuiltin()` had `match` arms for
`null`, `true` and `false` — types that only exist as standalone declarations **from PHP 8.2**,
below this project's 8.1 floor. They were unreachable on the minimum supported version and would
merely have *looked* like coverage on 8.3. Removed; the `default` arm handles them correctly,
because PHP's own check runs at construction and the `TypeError` conversion carries the path.
`callable` went the same way — it cannot be a property type at all.

One branch stays uncovered **on purpose**, and the code now says so: the `class_exists` guard on
a parameter type. Reaching it needs a DTO whose parameter type does not exist, and **PHPStan at
max rejects exactly that** (`class.notFound`) — so the state cannot occur in this codebase. A
fixture to cover it was written, seen rejected by the linter, and removed rather than suppressed
with `@phpstan-ignore`. The check stays because it is what narrows `string` to `class-string` for
the type system, and a consumer not running PHPStan can still reach it.

Final: **270/293 lines = 92.15%**, gate green.

## Two gaps named rather than left

- **`Collection<T>` hydration is not in this item.** Spec FR-01 names it, but `Collection` is
  item 3.3, and ADR-0006 already placed the docblock generic parser it needs there. The roadmap
  entry for 3.1 says so where someone checking it off would look.
- **Filed item 3.6 — the layering gate.** This is the first PR with two groups, so RFC-0001's
  *"groups depend downward on Support only"* rule is finally a real constraint with a direction.
  Nothing enforces it: the CI `layering` job self-skips for want of a `deptrac.yaml`. Until now
  the rule was vacuous with one group; the next four milestones each add a group that could
  violate it.

## Next

**3.2 `WithersTrait`** — `with(…)` clones of readonly DTOs, absorbing the PHP 8.1→8.3
readonly-clone difference per version. The first item where the version floor is not merely
declared but behaviourally load-bearing.
