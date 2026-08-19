# 2026-08-13 — Two classes, seventeen tests, and Support's first outward edge

Roadmap item **14.1** (spec r17 **FR-45**, **ADR-0062**, closes issue #97) — M14's keystone,
shipped first and alone because 14.2, 14.4, 14.5 and 14.7 all consume the seam it creates. Route
`frontier-reasoning / extra` (adr, protected floor); session model Fable 5 — matched. Same
session as the 14.6 design pass; separate PR, per the one-item-one-PR contract.

## The item was XS; the finding was not in the item

`SystemClock` and `FrozenClock` are together under thirty lines of executable code. The
implementation surfaced the one thing RFC-0003 had decided without noticing: `deptrac.yaml`
carried *"Support depends on nothing, and that must not change to accommodate one class
(ADR-0028)"* — a principle with sixty ADRs of history — and FR-45 places PSR-20 implementors
**in Support**, which makes `Support → Psr` an edge for the first time.

The resolution is in ADR-0062 §3, and the reasoning matters more than the grant: this is **not**
the move ADR-0028 forbade. That rule stopped classes from being *relocated into* Support so a
grant could dodge its rightful home. Nothing moved here — RFC-0003 placed the clocks in Support
on their merits, and placed two of their consumers (FR-46's `Str` additions, FR-49's
`RetryPolicy`) there too, so the edge exists under the accepted design **no matter where the
clocks live**. The blanket sentence was true for sixty ADRs and stopped being true the day
RFC-0003 was accepted; nobody noticed then, because nobody ran the RFC's placements against the
deptrac comments. The comment is retired in place with a pointer, not silently deleted.

Proof over assertion, both directions: the granted edge runs clean (deptrac **344 allowed / 0
violations / 0 uncovered**, up from 342 — the two clock classes), and a planted `Support → Dto`
**property type** (a type reference, not an import — deptrac resolves types, item 8.1's lesson)
was refused by name: `FrozenClock must not depend on D4np\Utils\Dto\Collection`. Restored,
verified clean.

## Design calls worth their lines

- **Timezones are objects, optional, defaulting to PHP's default.** Objects because a
  `DateTimeZone` is already valid — construction cannot fail, the type is the validation
  (`SqlStatement`'s stance applied to time). Default-not-UTC because the seam must change *where*
  time is read, never *what*: a UTC-pinning clock would format differently from every
  `new DateTimeImmutable('now')` in the consuming application, surprise without benefit — the
  benefit UTC chases (comparable instants) is already free, since instant arithmetic is
  timezone-independent and arithmetic is every in-library use.
- **`FrozenClock` is deliberately mutable** — the one class in this library whose job is
  controlled mutation. The injected reference is the mechanism: the holder advances the clock
  while the code under test keeps the same object. An immutable `withAdvanced()` would return a
  clock nothing under test holds.
- **`advance()` honours inverted intervals.** Backward time is not an edge case to refuse — it is
  the clock-skew scenario ADR-0061 §5's refill clamp will be tested against. The double's
  semantics were fixed by its first consumer's needs, which is the right direction for a test
  double to be designed from.
- **No exception type, stated out loud** because an absence leaves no trace: construction,
  `now()` and `advance()` cannot fail, and `ExceptionHierarchyTest`'s pinned lists are untouched.

## Six plants, six caught, and the one that pays rent

Five test plants (cached instant; ignored timezone; a frozen clock that ticks; ignored invert
flag; non-cumulative advance) plus the deptrac violation — each verified **landed** by absence
of the original line before its result was believed (the standing rule from items 11.1/11.2,
where a `sed` plant that matched nothing produced a green suite indistinguishable from a pass).

The plant that earns its place is the non-cumulative one: an `advance()` that re-applies each
interval to the *original* instant passes the single-advance test and the backward test — only
the two-advances-stack assertion sees it. A suite without that case would have been green over a
double that lies exactly when a test simulates the passage of more than one interval.

## Numbers

2 848 tests (+17), 6 046 assertions; CS-Fixer 0/254; PHPStan max clean; deptrac 344/0/0;
`composer.json` still normalized after `psr/clock ^1.0` (placed alphabetically, `composer
normalize --dry-run` confirms). Coverage certified by CI as always on this box (no local
driver, the 9 standing skips). No benchmark subject — ADR-0040 declines the number, and the
RFC's NFR-14 evidence (a control subject at 57% of its own subject) says what it would measure:
PHP's method dispatch, not this library.
