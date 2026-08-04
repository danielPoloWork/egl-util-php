# 2026-08-04 — Two sanitizers, and a wrong answer that only shows up on one driver

Roadmap item **5.2**. Route `frontier-reasoning / extra`; session is Fable 5 — match.

Two deliverables that share a class and almost nothing else: rich-HTML sanitization (FR-09b) and
`LIKE`-wildcard escaping (FR-10). The second closes spec §7's **T-02** suite, whose third leg item
4.4 deliberately left open and named this item as owner.

## The bug that only appears on one driver

Three probes before designing. Two changed the design.

**Binding doesn't neutralise wildcards.** Item 4.4 already proved a `LIKE` value can't inject SQL.
It can still *scan*: `%` and `_` are pattern syntax, not SQL syntax, so they survive binding
intact. Confirmed against a seeded table — bound `LIKE '100%'` matched `100%`, `100X` and `1000`.

**Then the one worth the whole item.** An escaped pattern **without** an `ESCAPE` clause matches
**nothing** on SQLite. Silently. Meanwhile MySQL and PostgreSQL treat backslash as a default escape,
so the identical code appears to work there.

That's the nastiest shape a bug can have: written and tested against MySQL, correct; deployed
against SQLite, quietly returns no rows. No error, no warning.

So `QueryBuilder::whereLike()` emits the clause unconditionally. It changes nothing for an
unescaped pattern and makes an escaped one correct everywhere. And the silent failure is itself
now a test — `testWithoutTheEscapeClauseAnEscapedPatternSilentlyMatchesNothing()` pins that
`where()` + `Operator::Like` returns `[]` for an escaped pattern, which is the reason `whereLike()`
had to exist.

**Third probe: the escape character.** The obvious choice is `\`. It's wrong for a portable
library — a backslash is special inside string literals on several drivers, and on SQLite
`ESCAPE '\'` isn't merely awkward, it's a **parse error** (`unrecognized token`). `!` has no
meaning in a string literal anywhere, so one spelling works everywhere.

## Ordering that looks arbitrary and isn't

The escape character has to be escaped **before** the wildcards. Reversed, the `%` pass inserts `!`
characters that the `!` pass then doubles — `100%` becomes `100!!%`, a literal `!` followed by a
*live* wildcard. Exactly the thing being defended against, reintroduced by getting two lines in the
wrong order. Pinned with `'!%'` → `'!!!%'`; the reversed order fails five tests.

## A duplication I couldn't avoid, so I checked it instead

`QueryBuilder` (Database) needs the escape character. `Sanitizer` (Security) defines it. RFC-0001's
layering rule forbids `Database → Security`, and deptrac enforces it.

Options were: put a SQL-dialect concern in `Support` (wrong layer), or duplicate the character and
verify. Chose duplication plus a reflection test asserting the two constants agree. Same treatment
ADR-0010 gave the docblock/attribute duplication it also couldn't avoid — if you can't remove a
duplication, make it impossible for it to drift unnoticed.

## The optional dependency, and where "optional" actually bites

`symfony/html-sanitizer` is the library's **first third-party dependency in production code**.

**What happens when it's absent is a security question, not a convenience one.** Returning the
input unchanged hands a caller who asked for sanitization their attacker's markup. Returning `''`
silently destroys content. Neither announces itself. So it throws — verified behaviourally by
pointing the guard at a class that genuinely doesn't exist and confirming `UtilsException` rather
than a passthrough.

**No Symfony type in the public signature.** `richText(string): string`. A type-hint naming a class
from an optional package makes that package effectively required for anyone reflecting over the
class — the opposite of optional. Cost: the profile isn't caller-configurable, which is FR-09b's
"curated allowlist profile" read literally.

## The layering gate earning its keep, again

Adding the dependency took deptrac from `Uncovered: 0` to `Uncovered: 6` — precisely the signal
item 3.6 built it to give.

The lazy fix is a blanket "ignore vendor" rule, which would have silenced this finding and every
future one. Instead `HtmlSanitizer` gets its **own layer that only `Security` may reach**. Planted
`Http → HtmlSanitizer` and confirmed the violation, so a future group quietly coupling itself to an
optional package is now a build failure.

My first attempt at the layer regex was wrong in a way worth noting: I wrote single backslashes in
the YAML, so PCRE read `\C` and `\H` as escape sequences rather than namespace separators. It
matched nothing and `Uncovered` stayed at 6 — which is how I found it, rather than by reading it.

## Non-vacuity

| planted | failures |
|---|---|
| escape character processed last (ordering bug) | 5 |
| `javascript:`/`data:` schemes allowed | 1 |
| forced `rel="noopener noreferrer"` removed | 1 |
| `Http → HtmlSanitizer` import | deptrac violation |
| guard pointed at a non-existent class | throws, does not passthrough |

## The prefer-lowest job earning its keep

CI went green everywhere except `quality / prefer-lowest install + test`. Tests passed there too —
685 of them — but with **1 deprecation**, which the warnings-as-errors bar turns into a failure.

`symfony/html-sanitizer` pulls in `masterminds/html5`. Its 2.7.2 release calls
`DOMImplementation::createDocument(null, null, $dt)`, passing `null` to a `string` parameter —
deprecated on PHP 8.1+. The normal resolution picks 2.10.1 and never sees it. Only the
lowest-allowed resolution does, which is exactly the failure mode item 1.7 added that job for.

Fixed by naming `masterminds/html5: ^2.7.5` in `require-dev` — a transitive dependency declared
directly only to raise its floor.

Worth noting *how* I picked 2.7.5: by fetching `DOMTreeBuilder.php` from each upstream tag and
bisecting.

```
2.7.3 -> createDocument(null, null, $dt)
2.7.4 -> createDocument(null, null, $dt)
2.7.5 -> createDocument(null, '',   $dt)   <- fixed here
```

The comfortable move is `^2.9` — safely past, obviously works, no thought required. But that
excludes 2.7.5–2.8.x for no reason, and a floor that isn't the true minimum is a constraint nobody
can later justify. Verified afterwards that prefer-lowest resolves exactly 2.7.5 and the
deprecation is gone.

## Honest gap

The missing-dependency path is **probe-verified but has no permanent test** — the package is a dev
dependency and cannot be absent during the suite. A CI job without dev extras would cover it; that
is build infrastructure rather than a test, and I didn't build it here. Named in the ADR and the
roadmap rather than left as an assumption.

## Bar

685 tests / 1483 assertions green (up from 662). `--group T-02` runs 343 — **T-02 is complete**.
PHPStan max clean, deptrac 0 violations / 0 uncovered, `composer validate --strict` clean.

## Next

**5.3** `Hash` — Argon2id default, `bcryptFallback` policy, `needsRehash()`. Same route.
