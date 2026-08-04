# 2026-08-04 — Checking what T-01 actually still needed, before writing anything

Roadmap item **3.4**. Route: no signals, floor `fast / low`; session model matched.

## Checked before assuming

Item 2.6 taught the shape: a roadmap line naming a suite spec §7 defines does not automatically
mean new tests. Spec T-01's matrix names *"nested, collections, nullables, enums, strict/lenient,
withers, missing-key cases"*. Checked each against what `#[Group('T-01')]` already covered
(items 3.1–3.3): nested — yes. Nullables — yes. Strict/lenient — yes. Withers — yes. Missing-key
— yes. Collections — yes. **Enums — no fixture anywhere uses one.**

That is a real gap, not bookkeeping, so this item did real work rather than only tagging
existing tests.

## The gap, and the distinction that matters

Reflecting an enum-typed parameter: `isBuiltin()` is `false`, so today's hydrator routes it to
`coerceObject()` — which passes through an already-constructed instance, tries nested-DTO
hydration (fails, an enum is not a `DataTransferObject`), and otherwise throws. So a payload
carrying the enum's own **scalar backing value** (`'active'` for `Status::Active`) was rejected,
even though `Status::tryFrom('active')` could construct it.

The fix has one edge worth being careful about, verified before writing code:

```php
enum Status: string { case Active = 'active'; }   // BackedEnum: has ::tryFrom()
enum Direction { case Up; case Down; }             // UnitEnum only: no scalar mapping at all
```

Only `BackedEnum` gets scalar coercion. A pure enum has no backing value to key from —
`Direction::cases()` gives names, not a lookup — so it stays instance-only, which the existing
pass-through already handles correctly.

**`tryFrom()`, not `from()`.** `from()` throws a bare `\ValueError` on no match, which is not
part of ADR-0004's exception family and would let an uncaught error escape a hydration call.
`tryFrom()` returns `null`, converted here into `TypeMismatchException` naming every valid
backing value — more useful than "expected Status, got string" on its own.

## Proved non-vacuous

Two probes, each reverted and the implementation restored byte-identical:

1. **Widened the check from `BackedEnum` to `UnitEnum`** (both share the interface) → 3 errors
   and 2 failures. The errors are the interesting part: calling `tryFrom()` on `Direction`, which
   does not have that method, is a fatal error, not a graceful rejection — the narrower check
   exists precisely to avoid it.
2. **Swapped `tryFrom()` for `from()`** → a bare `\ValueError` propagated instead of a caught
   `TypeMismatchException`, exactly as predicted.

243 tests, 464 assertions (7 skipped, Windows-only). PHPStan max clean on the first pass.

## State of Milestone 3

3.1–3.4 done. Remaining: **3.5** (phpbench benchmarks against NFR-01/NFR-04) and **3.6**, filed
at item 3.1 — the `deptrac.yaml` layering gate, now covering two real component groups
(`Support`, `Dto`) instead of the vacuous single-group case it would have been before 3.1.
