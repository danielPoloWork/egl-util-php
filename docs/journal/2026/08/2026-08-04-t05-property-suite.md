# 2026-08-04 — Making spec §7's T-05 suite a mechanical fact

Roadmap item **2.6**. No signals, floor route (`fast / low`); session model matched.

## What this item actually was

By the time this item came up, all three cases spec §7 names for T-05 already existed:
`Str::slug()` idempotence (item 2.2), `Json` round-trips and the `Env` boolean-coercion table
(both item 2.4). Writing new tests would have duplicated coverage that already exists and
already passes.

The real, non-duplicative work: spec §7 names T-05 as **one suite**, and nothing in the repo let
anyone run or count it as one — the three cases live in three different test classes, tied
together only by a comment in each docblock saying so. A docblock is not traceability; it is a
claim nobody checks.

## The fix

`#[Group('T-05')]` on the three specific test methods
(`StrSlugTest::testSlugIsIdempotent`, `JsonTest::testRoundTrip`,
`EnvTest::testBooleanCoercionTable`), so `vendor/bin/phpunit --group T-05` runs exactly spec
§7's named suite as a runnable, countable unit.

**Verified the mechanism discriminates, not just that it runs**: confirmed the filter selects
exactly 38 tests (the three tagged methods across their data providers) — then added a fourth
`#[Group('T-05')]` tag to an unrelated method as a probe, watched the count become 39, removed
it, watched it return to 38. The tag is doing real, bidirectional selection work, not
decorating tests that would show up in the filtered run regardless.

## State

138 tests, 284 assertions unchanged (no new tests, only tags). PHPStan max clean, PHP-CS-Fixer
clean, both mandatory gates green.

## Next

**Milestone 2 has one item left: 2.7**, the coverage floor stated in `AGENTS.md` §10 and spec
NFR-07 but not gated anywhere — filed at item 2.1 and carried since. Closing it finishes
Milestone 2 (`v0.2.0`) in full, opening Milestone 3 (DTO & data mapping) — the first component
group that actually consumes the reflection cache from item 2.5.
