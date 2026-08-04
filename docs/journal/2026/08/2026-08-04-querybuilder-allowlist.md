# 2026-08-04 — The spec's own regex was the vulnerability

Roadmap item **4.2**. Route: `frontier-reasoning / extra`; session is Opus 5 (standard). I flagged
the mismatch when closing 4.1; the maintainer said proceed, so it is accepted and recorded —
`record_run.py --route-mismatch "frontier-reasoning/extra=standard"` — rather than left as a silent
divergence.

## Why this class is the whole defence

RFC-0001's security model has four mechanisms. ADR-0014 did the first. This is the second, and it
exists because of one fact:

> prepared statements bind **values**, never table or column names.

There is no `?` for a column name. An identifier arriving from user input has nothing to hide
behind, so the allowlist isn't one layer among several — it *is* the layer.

## The finding

Spec FR-07 specifies the allowlist as `^[A-Za-z_][A-Za-z0-9_]*$`. I transcribed it literally.
Then, before trusting it, I checked what PCRE actually does with `$`:

```
"id"      => 1
"id\n"    => 1     <-- PCRE's `$` matches before a trailing newline
"id\r\n"  => 0
```

Against the built class:

```
SELECT "id\n" FROM "users"
>>> ALLOWLIST BYPASSED
```

**The spec's own regex, implemented faithfully, is a bypass.** Anchored with `\z` — which admits
nothing after the final character — it refuses. That's FR-07's *intent* implemented rather than its
*notation* copied, and it's recorded in ADR-0015 and in a comment on the constant itself, because
the obvious future "fix" is someone restoring `$` for fidelity to the spec text.

Two things worth being honest about:

**My own suite missed it.** The hostile-identifier matrix already had a newline payload —
`"id\nDROP TABLE users"` — which fails the pattern, but for an unrelated reason (the content after
the newline). A trailing newline is the case that slips through, and I only found it by checking
the regex engine's semantics rather than by testing the payloads I'd thought of. Both cases are in
the matrix now.

**Practical severity was low, and that is the second layer's doing, not the first's.** The smuggled
newline landed *inside* a quoted identifier, so it produced an unresolvable column rather than an
injection. Which forced me to correct something I'd written an hour earlier.

## Correcting my own docblock

The first draft of `QueryBuilder` said the quote-escaping was *"by construction unreachable"* —
an allowlisted identifier holds no quote character, so there is nothing to escape. That reads well
and it was wrong in a specific way: it's a claim that a regex is perfect. For as long as the
allowlist had a hole, the quoting is what contained it.

Corrected in place rather than deleted, with the incident as the reason. The second layer costs
nothing and it earned its keep within a single item.

## An enum the spec doesn't ask for

FR-07 makes the `ORDER BY` direction an enum for a stated reason: it's concatenated into the SQL
text and can't be bound. A comparison operator is concatenated into the SQL text for *exactly the
same reason*, and FR-07 says nothing about it.

A `where(string $column, string $operator, mixed $value)` would bind the value safely, allowlist
the column safely, and leave an unchecked string spliced between the two — more dangerous for
looking harmless next to two carefully-handled parameters. So `Operator` exists. Applying the
spec's own pattern to the case it missed is a smaller decision than inventing an operator
allowlist, and it's recorded as an extension rather than slipped in.

## Things checked instead of assumed

- **`PDO::quote()` is useless for identifiers** — it returns `'id'`, a string *literal*. Quoting an
  identifier with it yields a constant instead of a column reference: a silent wrong answer.
- **SQLite is a bad witness for quoting.** It accepts double quotes, backticks *and* brackets, so a
  query built in entirely the wrong style still runs. Executing one proves nothing about whether
  the right style was chosen — so driver quoting is asserted on the rendered SQL, via
  `PretendDriverPdo` (a real SQLite connection reporting a chosen driver name).
- **A variadic is not a `list`.** PHPStan max caught it: PHP 8.1 allows named arguments into a
  variadic (`select(first: 'id')`), producing string keys. `array_values()`.

## Closing half of 4.1's declared gap

Item 4.1 shipped `SET NAMES utf8mb4` unexecuted by any test and said so. `PretendDriverPdo` closes
the closeable half: it's now asserted that the statement is issued for MySQL and *not* issued for
other drivers. It still doesn't prove a real MySQL server accepts it — that stays with T-02's
driver matrix (item 4.4).

## Non-vacuity

Four planted defects, each caught and reverted, blast radius in brackets:

| planted | failures |
|---|---|
| allowlist weakened to `/.*/` | 76 |
| value interpolated instead of bound | 3 |
| negative `LIMIT`/`OFFSET` allowed | 2 |
| MySQL's backtick arm removed | 1 |

## Bar

374 tests / 618 assertions green (up from 274). `--group T-02` runs 116 as a unit. PHPStan max
clean, deptrac 0/0 with 68 allowed edges, consistency lint OK.

## Next

**4.3** `Transaction` — closure scope, rollback + rethrow, savepoint nesting. Routed
`standard / medium`.
