---
id: BUG-0001
title: The constant-time comparison registry has been blind to every call since function prefixing landed
status: fixed
severity: medium
reporter: internal
discovered: 2026-08-20
affected-versions: ">=1.0.0,<1.1.0"
fixed-in: v1.1.0
---

# BUG-0001: The constant-time comparison registry has been blind to every call since function prefixing landed

## Summary

`ConstantTimeComparisonTest::testTheRegistryNamesEverySecretComparisonInTheLibrary()` exists to
guarantee that a secret comparison added to the library cannot go unasserted. Since item 10.12
prefixed every internal function call with `\`, its scanner has matched **nothing at all** — it
found 0 of the library's 3 constant-time comparisons and passed on the empty set. No production
behaviour is affected; the safety net was.

## Environment

- **Affected versions:** `>=1.0.0,<1.1.0` — introduced by item 10.12 (ADR-0048,
  `native_function_invocation`), which landed before `v1.0.0`, so every released version has it.
- **Toolchain / platform:** any; the defect is in a source-inspecting test, not in runtime code.
- **Configuration:** `.php-cs-fixer.dist.php`'s `native_function_invocation` rule
  (`@all`, `scope: namespaced`, `strict`).

## Reproduction

Run the scanner's own logic over `src/main` and count what it sees against what is there:

```text
$ php -r '<the scanner's T_STRING match, over src/main>'
  prefixed call: CsrfToken.php:109 => \hash_equals
  prefixed call: Hash.php:181      => \password_verify
  prefixed call: Hmac.php:212      => \hash_equals

the guard sees (T_STRING only): 0
actually present (prefixed):     3
```

The live demonstration is stronger than the count. Item 14.4 added a third secret comparison —
`Hmac::verify()` — **without** registering it in `secretComparisonPaths()`, and the suite stayed
green. That is precisely the event the test was written to make impossible.

The decisive run puts the defect the registry exists to catch in front of it. With the scanner in
its pre-repair state, `Hmac::verify()` unregistered, and `hash_equals($expected, $mac)` replaced by
`$expected !== $mac` in `src/main`:

```text
$ vendor/bin/phpunit --filter ConstantTimeComparisonTest
.....                                                               5 / 5 (100%)
OK (5 tests, 15 assertions)
```

A timing-unsafe comparison on a secret path, present in the library, and the file whose entire
purpose is to refuse it reports five green tests. Restored to the same state, the repaired scanner
names it: `hash_equals() at Hmac.php:212`.

## Expected vs. actual

- **Expected:** an unregistered constant-time comparison in `src/main` fails the test, naming the
  file and line.
- **Actual:** no comparison anywhere in `src/main` is visible to the test, so the assertion
  `assertSame([], $unregistered)` compares an empty list against an empty list forever.

## Root cause

PHP tokenizes a namespace-qualified call differently from a bare one:

```text
\hash_equals(...)  =>  T_NAME_FULLY_QUALIFIED, value '\hash_equals'
hash_equals(...)   =>  T_STRING,               value 'hash_equals'
```

`constantTimeCallsIn()` matched only `T_STRING` whose value was exactly `hash_equals` or
`password_verify`. When ADR-0048 prefixed the whole tree, every call became
`T_NAME_FULLY_QUALIFIED` and the filter stopped matching. The two *other* tests in the same file
survived, because they search the method's source text for the substring `hash_equals(`, which
`\hash_equals(` still contains — which is why the file kept reporting 3 of 5 tests doing real work
and nobody noticed the other 2 had nothing to do.

**Item 10.12's own audit missed it, and the reason is worth recording.** That item knew it had
broken one source-inspecting test (`NativeSessionApiTest`, which searched for the literal
`return session_start();`) and explicitly checked the other three, concluding they "match patterns,
not spellings, and were fine". `ConstantTimeComparisonTest` matched neither a pattern nor a
spelling but a **token type**, a third category the audit did not have — and the prefixing changed
exactly that. The audit's conclusion was right about the two tests that search text and wrong about
the one that tokenizes.

The asymmetry that let it hide for ten items: the formatter turned this assertion **green**, not
red. A mechanism assertion that goes red gets fixed within the hour; one that goes vacuous is
indistinguishable from one that is satisfied.

## Impact

`medium`. No consumer-visible defect and no weakened comparison — all three call sites do use a
constant-time comparator, verified directly. What was lost is the guarantee that the *next* one
would be caught, and the specific defect it guards against (a `===` on a secret path) is invisible
to every behavioural test in the suite, which is why the guard exists at all. Ten items shipped
under a safety net that was not attached, including two security items (12.1's `Crypto`, this one).

Not raised above `medium`, on the evidence rather than out of modesty: nothing shipped with a
weakened comparator, the window required *both* the blindness and an author forgetting to register
a new path, and only one new secret comparison arrived in the whole window (this item's). Not
lowered to `low` either — the reproduction above shows the exact defect reaching a fully green
dedicated suite, and it was found by accident rather than by anything watching.

**One honesty note about how it was caught here.** In the reverted-state run above, the wider
`HmacTest` did fail one test — but incidentally: `testTheMacIsCheckedBeforeTheExpiryIsRead` locates
`hash_equals(` in order to check what precedes it, so it breaks when the call disappears for an
unrelated reason. That is a coincidence of this item's own new assertions, not the safety net
working. Had item 14.4 not needed an ordering assertion, the plant would have passed everything.

## Fix / workaround

`constantTimeCallsIn()` now matches `T_NAME_FULLY_QUALIFIED` alongside `T_STRING`, comparing on the
name with any leading `\` stripped, so it is indifferent to whether a call is prefixed. Proven to
fail before being trusted (the standing method, items 1.11 / 2.7): with `Hmac::verify()` left out of
`secretComparisonPaths()` the test now reports
`hash_equals() at Hmac.php:212`, and registering the path turns it green.

A guard against the regression recurring is included: the test now also asserts it can see **at
least as many** comparisons as there are registered paths, so a future tokenizer or formatter change
that blinds the scanner again fails loudly instead of passing on an empty set.

## References

- Fixing PR: #141
- `CHANGELOG` entry: `[Unreleased]` → `Fixed`
- Related: ADR-0048 (the prefixing that caused it) · ADR-0027 (why these assertions are mechanisms)
  · ADR-0065 (item 14.4, the item that surfaced it) · item 10.8's standing lesson — *read the job,
  not the checks column* — of which this is the first instance inside the PHPUnit suite rather than
  in CI
