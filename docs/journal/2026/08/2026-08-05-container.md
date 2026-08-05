# 2026-08-05 — Two assumptions, and a benchmark that measured the harness

Roadmap item **6.4**. Route `standard / medium` → Opus 5, which is the session model: **no mismatch
for the first time since 5.2.** Nothing to record.

## The layering decided a placement I'd have got wrong by habit

Every exception in this library lives in `Support`. PSR-11 requires the container's to implement
`ContainerExceptionInterface`, and `deptrac.yaml` says `Support: ~` — depends on **nothing**.

So the habit was wrong, and the config was right. Putting them in `Support` means the bottom layer
takes a dependency to host one class; putting them in `Container` costs nothing, since that is where
they are thrown. Probed rather than reasoned: dropping the `Psr` grant produces five deptrac
violations naming each class, and `Support` — which has no grants at all — would fail identically.

`ContainerException extends UtilsException` still, so ADR-0004 holds: catch `UtilsThrowable` and you
catch these, catch PSR-11's interface and you get PSR behaviour.

One extra class: `CircularDependencyException`. I first wrote the fallback logic as
`str_contains($e->getMessage(), 'Circular dependency')` — a branch on prose, which breaks silently
the day someone rewords a message. The container genuinely acts on the distinction (a default may
answer an absent dependency; it must not answer a cycle), so the distinction gets a type.

## Two things I assumed, and both were wrong

Caught by tests failing, not by review.

**`class_exists()` is false for an interface.** So `get(Greeter::class)` on an unbound interface
came back "no entry is registered … not a loadable class" — sending the reader to hunt for a typo
instead of telling them to `bind()` it. `ReflectionCache`'s docblock had already written this down
for me, in item 2.5: *"the Container in particular must be able to reflect an interface in order to
refuse it as non-instantiable."* I read that class, used it, and still assumed the wrong thing about
`class_exists`.

**`mixed` is a named built-in type, not an absent one.** My "untyped parameter" fixture used
`mixed $anything` and quietly exercised the built-in branch instead. Worse, a promoted readonly
property *must* carry a type, so the fixture could not have been written the obvious way — the
fixture is the only one in the set that doesn't use promotion, and now says why.

Both are the same shape as the T-03 probe that reported `ABSENT`: the code did something reasonable,
and I read the result as confirming what I expected.

## The benchmark was measuring the benchmark

NFR-02: ≤ 2 µs warm, ≤ 30 µs first autowired. First run said **103 µs**.

Before calling that an NFR violation, I timed the same resolve directly: **17.8 µs**. A 6× gap
between two measurements of identical work means at least one of them is not measuring what it
claims.

Two errors, found in order:

1. phpbench runs `beforeMethods` **once per iteration**, then loops the subject over every
   revolution — read out of `remote.template`, since guessing this wrong is exactly how a cold
   benchmark quietly becomes a warm one. A cold container set up in a hook would have been cold for
   revolution 1 and warm for the other 999.
2. Fixing that still gave 93 µs — and the tell was that **the number moved with the revolution
   count**: 93 µs at 200 revs, 26 µs at 2000, same work. The subject's first revolution was paying
   Composer's autoloading, and phpbench divides the total by the revolution count, so a one-time
   cost got smeared across every measurement.

Warm the class loader, leave the metadata cache cold — which is what NFR-02's "first" actually
means, since class loading happens once per process whatever the container does:

```
revs  200 -> 18.368 us
revs 1000 -> 18.276 us
revs 4000 -> 18.089 us
```

A number that stops moving with the harness is a number about the subject. **18.6 µs against a 30 µs
budget**, and 0.173 µs warm against 2 µs.

This is the second benchmark of mine to be wrong in the same direction — ADR-0020 records the first,
on NFR-03. Both times the total looked plausible and the *workload* was the thing that wasn't.

## PHPStan turned an annoyance into an API decision

`ContainerInterface::get()` has no return type in psr/container 2.0.2 (checked the vendored file,
because I would have bet on `: mixed`). Implemented plainly, PHPStan max flagged **12** errors in my
own tests — every `get()` result dereferenced.

The lazy fix is to narrow in the tests. But the tests are the first consumer, and what they were
telling me is that every future consumer at max level pays the same tax. So `get()` declares a
conditional return type: a class name returns that class, any other identifier stays `mixed`.

Twelve errors became two, and those two were honest — string-keyed entries really are untyped.

No test asserts the annotation, and it doesn't need one: removing it fails **11** existing PHPStan
checks, because the tests dereference what `get()` returns. Probed to confirm that, rather than
assuming the gate covers it.

## Bar

1233 tests / 2780 assertions green (up from 1196). PHPStan max clean, deptrac 0/0, PHP-CS-Fixer
clean, consistency lint OK.

## Next

**6.5** — `Result`, PSR-3 `Logger`, `ExceptionHandler` with an env-gated trace policy. Last item of
Milestone 6.
