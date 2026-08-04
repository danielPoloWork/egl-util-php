# 2026-08-04 — Withers: meeting a "per-version" requirement by not depending on the version

Roadmap item **3.2**. Route `standard / medium`; session model matched.

## The requirement said "per version". Measurement said otherwise.

Spec FR-02 asks for withers *"absorbing the PHP 8.1→8.3 readonly-clone difference per version"* —
phrasing that invites a `PHP_VERSION_ID` branch. Measured on 8.3 before writing anything:

| Operation | 8.1 / 8.2 | 8.3 |
|---|---|---|
| assign a `readonly` property after `clone`, **outside** `__clone()` | `Error` | **`Error`** |
| assign a `readonly` property **inside** `__clone()` | `Error` | **allowed** |
| build a new instance through the constructor | allowed | allowed |

The first row is the one worth having checked. PHP 8.3's readonly amendment did **not** make
readonly properties writable after cloning in general — it relaxed one narrow case, reassignment
inside `__clone()`. Everywhere else the property stays sealed, on 8.3 as much as on 8.1.

So a clone-based wither needs a `__clone()` hook *and* remains an error on two of the three
supported versions. Rebuilding through the constructor works on all three. **The requirement is
met by not depending on the difference**, and the trait carries no version branch at all.

Writing `if (PHP_VERSION_ID >= 80300)` would have added a branch dead on two supported versions
and live on one — precisely the shape item 3.1 was caught producing a few hours earlier, where
`match` arms existed for types that cannot be declared below the 8.1 floor. Having just paid for
that lesson, spending it here was the point.

## A second argument that has nothing to do with versions

A `clone` copies an object into existence **without running its constructor**. A DTO that
validates in its constructor would have that validation bypassed — a clone-based wither could
produce an object the class itself refuses to build. Rebuilding keeps the guard.

That is asserted directly: a fixture that throws on a malformed email, and a test that expects
`with(email: 'not-an-email')` to be refused. Under a clone implementation it would quietly
succeed.

## Shape

`with(mixed ...$changes)` collects **named arguments** into a string-keyed array (verified), so
the call site names what it changes. It routes through the same hydrator as `fromArray()`, so an
undeclared name is an `UnknownKeyException` and a bad value a `TypeMismatchException` — `with()`
is not a way around the type system, and none of that logic is duplicated.

Two details that needed checking rather than assuming:

- **The trait declares `abstract protected static function hydrator(): Hydrator`.** A trait's
  abstract method *is* satisfied by an inherited one (verified), so `DataTransferObject`'s
  already-shared instance fulfils it — making "this trait belongs on a DataTransferObject" a
  compile-time requirement rather than a comment. It also keeps imported ADR-001's *one* metadata
  cache true: a trait building its own would quietly make two.
- **Current values are read via `ReflectionProperty::getValue()`**, not `$source->{$name}`: a
  promoted property may be `private` and the hydrator is not in its scope. Verified that since
  PHP 8.1 this needs no `setAccessible()`.

## Proved non-vacuous

Two probes, each reverted and the implementation restored byte-identical:

1. **Dropped the current values** (so unnamed properties reset) → 8 errors.
2. **Reversed the merge order** (so changes lose to current values) → 8 failures.

211 tests, 412 assertions (7 skipped, Windows-only). PHPStan max clean; PHP-CS-Fixer clean.

## A test I wrote, then removed

I wrote a test asserting that assigning to a `readonly` property throws — the constraint the
whole design works within. PHPStan rejected it: `property.readOnlyAssignOutOfClass`. I tried
dodging with a dynamic property name; PHPStan resolved that too, correctly.

Both routes amount to disabling a real check in order to assert something it already proves —
for every call site, permanently, which is stronger than one runtime assertion. So the test is
gone and the reasoning is in its place in the file. That is the third time this pattern has come
up (item 2.1's `assertNotInstanceOf`, item 3.1's `class_exists` fixture); the consistent answer
is that a static guarantee outranks a runtime echo of it.

## Next

**3.3 `Collection<T>`** — and with it the docblock generic parser ADR-0006 deferred, which is
what finally lets `Collection<T>` properties hydrate. It closes the one gap item 3.1 named in
its own roadmap entry.
