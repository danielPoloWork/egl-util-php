# ADR-0037: Disable PHP's CSV escape character, and keep the formula guard opt-in

- **Status:** Accepted
- **Date:** 2026-08-06
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 9.4 · spec r5 FR-28, FR-29, FR-22/23, §6 T-08 ·
  [RFC-0002](../rfc/0002-application-layer-groups-from-legacy-intake.md) §Cross-cutting (the
  CSV guard clause) · [ADR-0005](0005-atomic-file-writes-with-a-sidecar-lock.md) (the atomic
  write this streams through) · [ADR-0015](0015-identifier-allowlist-closed-keywords-and-the-anchor-hole.md)
  (an enum over a validated string) ·
  [ADR-0019](0019-four-escaping-contexts-and-the-unquoted-attribute-assumption.md) (do not
  silently substitute what the caller supplied) ·
  [ADR-0036](0036-refuse-the-downgrade-and-the-characters-parse-url-launders.md) (the
  immediately preceding case of a standard-library function that succeeds while changing the
  value)

## Context

FR-28 asks for streaming CSV with typed failures and an **opt-in** formula guard; FR-29 for a
`CsvSerializable` contract. The estate's exporter is the anti-requirement: it resolved a
separator *name* through a `match` with a `default => ';'` arm, returned `false` from four
paths while accumulating the reason into a `$message` variable nothing ever read, and paired
its header and row methods by asking implementors, in prose, to keep them consistent.

Four behaviours were measured against PHP 8.3.1 before designing. **Two changed the
implementation, and one of them is data corruption.**

| probe | result |
|---|---|
| `fputcsv`/`fgetcsv` round trip of a field ending in a backslash, default `$escape` | **corrupted** — two fields in, **one** field out, having swallowed the delimiter and the newline |
| the same round trip with `escape: ''` | exact |
| `fputcsv($h, [''])` | writes a **bare newline**; `fgetcsv` reads it back as `[null]` — the row is lost |
| `fgetcsv` on a blank line | `[null]`, a phantom single-column row |

## Decision

### 1. `escape: ''` on every call — the default corrupts data

PHP's CSV functions default to a backslash escape character that **RFC 4180 does not
define**. It is not a formatting preference. Measured:

```
['ends with \', 'next']  →  "ends with \",next␊   →  ['ends with ",next␊']
```

The backslash escapes the closing quote, the field never terminates, and it consumes the
delimiter and the newline. Any value ending in a backslash — a Windows path, a regex, a
trailing separator in free text — is silently destroyed on the round trip.

Both `fputcsv()` and `fgetcsv()` are therefore called with `escape: ''`, leaving the
quote-doubling of the actual standard as the only mechanism. This also aligns with PHP 8.4,
which deprecates the default for the same reason. A test pins the **native** corruption
alongside our fix, so the workaround cannot later look arbitrary.

### 2. A single empty field is written as `""`, because `fputcsv()` cannot express it

`fputcsv($h, [''])` emits a bare newline — indistinguishable from a blank line, and read
back as nothing. A one-column export of possibly-empty values would lose rows silently.
`Csv` writes the explicit `""` form for that one shape; `fputcsv()` never quotes an empty
field and offers no flag to make it.

The neighbouring case is refused rather than papered over: a **zero-column** row has no CSV
representation at all, so it raises `CsvException` instead of being written as a line that
disappears.

### 3. Blank lines are skipped on read; `""` is not

`fgetcsv()` reports a blank line as `[null]`. Yielding that would hand every consumer a
phantom single-column row to filter. It is skipped — and the distinction is asserted: a line
holding one *quoted empty field* is a real row and is yielded as `['']`.

### 4. The formula guard is **off by default**

Turning `=1+1` into `'=1+1` protects a spreadsheet, and it **changes the exported value** —
the apostrophe is part of the field on any later read, so a guarded file no longer
round-trips to its input. Making that the default would repeat the input-mutilation mistake
spec §1 exists to reject (the `magic_quotes` shape, and ADR-0019's rule against substituting
what the caller supplied).

Whether the guard is wanted is a fact about **where the file is going**, which only the
caller knows: a CSV consumed by another service must not be rewritten; a CSV a human will
open in Excel should be. The flag is that decision, made explicitly.

Both states are tested against the OWASP corpus, including the cost: a test asserts that a
guarded file does **not** round-trip. Leaders are OWASP's `=`, `+`, `-`, `@`, plus tab and
carriage return. Non-string fields are left alone — a negative integer is not an injection
vector, and guarding it would corrupt legitimate numeric exports.

### 5. `Delimiter` is an enum; `CsvSerializable`'s pairing is enforced

The estate's separator `match` had a `default => ';'` arm, so a typo silently produced a
different format than the caller believed. An enum makes the wrong value unrepresentable —
ADR-0015's reasoning, reached from a third direction. Four cases, not the estate's eight:
these are the separators real CSV uses.

`CsvSerializable`'s two methods must agree in order and count, which its predecessor could
only *request* in prose. `Csv::write()` takes the header from the first item and checks every
subsequent row against its width, raising `CsvException` naming both counts. A docblock plea
became a mechanism.

### 6. `File::writeStream()` rather than a second atomic-write implementation

NFR-12 requires memory proportional to a row, and `File::write()` takes a finished string.
Rather than reimplement temp-file-plus-rename inside `Csv` — putting ADR-0005's discipline in
two places, one of which would drift — `File` gains a streaming sibling that shares the lock,
the same-directory temporary file, the mode-before-rename ordering and the cleanup.

It adds `fflush()` before the rename: buffered bytes still in userland when the rename
happens would make the "complete or previous, never a mix" promise a lie. It catches
`Throwable` rather than `FileException`, because the caller's writer may throw anything and
the temporary file must not survive either way — and it propagates the original unchanged,
the same contract `Transaction::run()` documents.

## Alternatives Considered

1. **Keep PHP's default escape** — rejected on the probe: it is not a style choice, it
   destroys any field ending in a backslash.
2. **Write the CSV to a string and use `File::write()`** — rejected: it buffers the whole
   table, which NFR-12 forbids, and the estate's exporter already showed what happens when an
   export outgrows memory.
3. **Duplicate the atomic-write logic inside `Csv`** — rejected: ADR-0005's discipline is
   security- and durability-relevant, and two copies is one copy that will drift.
4. **Formula guard on by default** — rejected: silently altering exported data is the
   `magic_quotes` shape spec §1 rejects, and it breaks the round trip the rest of this class
   works to guarantee.
5. **Quote every field unconditionally** (a common "just be safe" reflex) — rejected: it does
   not stop formula injection at all (a spreadsheet evaluates `=1+1` whether or not the CSV
   quoted it), while making every file larger and noisier. It would have looked like a fix.
6. **A validated separator string instead of an enum** — rejected for the estate's own
   evidence: the `default` arm turned a typo into a silently different format.
7. **Yield blank lines as `[null]` and let callers filter** — rejected: every caller would
   write the same filter, and the one that forgets gets a phantom row into its data.
8. **A separator name (`'semicolon'`) as the public API**, as the estate had — rejected: it
   is the enum's information with none of the enum's guarantees.

## Consequences

**Easier:** an export streams, so table size is bounded by disk rather than memory; a failed
export leaves the previous file byte-for-byte intact; every failure names itself instead of
returning `false`; and a value survives the round trip — including the backslash-terminated
ones PHP's defaults destroy. `File::writeStream()` is available to item 9.5, which needs the
same discipline for its counter file.

**Harder / accepted:** `Csv` writes one shape (`""`) that plain `fputcsv()` would not, which
a byte-comparison against another writer's output will notice; a zero-column row is now an
error rather than a silent no-op; and the formula guard being off by default means a caller
exporting to a spreadsheet **must** know to turn it on — the documentation says so at the
parameter, and the alternative was worse.

**Verification:** 92 tests across four suites, `#[Group('T-08')]` on the two the spec names,
and the suite is **proved non-vacuous by 12 planted defects** — restoring PHP's escape on
write and on read, removing the single-empty-field form, silently writing a zero-column row,
flipping the guard default in both directions, dropping the tab/CR leaders, yielding blank
lines, skipping the `CsvSerializable` width check, suppressing the header, leaving a
temporary file behind on failure, and removing the pre-rename flush. Each was caught.

## References

- ROADMAP item 9.4
- spec r5 FR-28, FR-29, FR-22/23, §6 T-08
- [RFC-0002](../rfc/0002-application-layer-groups-from-legacy-intake.md) §Cross-cutting (the CSV guard clause)
- [ADR-0005](0005-atomic-file-writes-with-a-sidecar-lock.md) (the atomic write this streams through)
- [ADR-0015](0015-identifier-allowlist-closed-keywords-and-the-anchor-hole.md) (an enum over a validated string)
- [ADR-0019](0019-four-escaping-contexts-and-the-unquoted-attribute-assumption.md) (do not silently substitute what the caller supplied)
- [ADR-0036](0036-refuse-the-downgrade-and-the-characters-parse-url-launders.md) (the immediately preceding case of a standard-library function that succeeds while changing the value)
