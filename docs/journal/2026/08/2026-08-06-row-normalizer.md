# 2026-08-06 — Seventeen copies, and the question none of them had to answer

Roadmap item **10.2**. Route `standard / medium` — matched. The `Persistence` group opens here,
with one class and one deptrac layer.

## What "explicit" turned out to demand

FR-36 asks for the estate's row-cleanup pipeline as *"one explicit, testable policy object"*.
Collapsing seventeen copies into one class is the easy half. The word **explicit** is the hard
half, because it forces a question every one of those copies got to ignore: *which of these four
steps happens when the caller says nothing?*

Each copy did all four, unconditionally, and each was right to — each was written for one
schema. A library has no schema. And three of the four steps **change data**, which puts them
squarely against spec §1's rejection of blanket input sanitization — the same wall ADR-0037 hit
with the CSV formula guard and ADR-0019 with invalid-UTF-8 substitution.

So: `trim` on, everything else off. `trim` earns the exception because it is the only step whose
*absence* is the surprise — trailing spaces out of a fixed-width `CHAR` are an artifact of
storage, not content.

The closest call was defaulting **everything** off, so `new RowNormalizer()` does nothing. It is
maximally honest and it is useless: the reason this class exists at all is `CHAR` padding, and a
normalizer that normalizes nothing until configured invites all seventeen consumers to re-derive
the identical one-line configuration. That is the copy-paste this item is undoing.

## A latent bug I inherited by not inheriting it

The estate's helper read:

```php
iconv('ISO-8859-15', 'UTF-8//IGNORE', trim($value))
```

Trim, **then** convert. For a single-byte source encoding that is fine: every ASCII whitespace
byte means whitespace there too. For any multibyte source it is destructive — `trim()` strips
bytes, and a `0x20` inside a UTF-16 sequence is not a space. The estate is not exposed to this,
because its source encoding is single-byte and always will be. A library shipped at unknown
schemas is.

So the order is fixed rather than configurable — transcode, then trim/collapse, then blank→`null`
last, because a `CHAR(20)` of spaces is blank *after* trimming and not before. Three of the
possible orderings are simply wrong, and offering them would be offering a way to corrupt data
that nothing wants.

## The two rows of T-15 worth reading

Twenty-six rows, most of them mechanical. Two are there because a hand-rolled version of this
pipeline gets them wrong:

- **`'0'` is not blank.** `empty('0')` is `true` in PHP, so the obvious
  `empty($v) ? null : $v` nulls a legitimate zero — and a flag column is exactly where that
  lands. `Str::nullIfBlank()` judges by `trim() === ''`, which is right, and now there is a test
  saying so.
- **Non-string values pass through by identity.** A BLOB comes back from the driver as a
  *resource*. Feeding one to `iconv()` destroys it. Ints, floats, bools and `null` have nothing
  any of these steps could mean.

## The layer, and the edge I did not grant

The `Persistence` deptrac layer arrives with **Support only**. RFC-0002's P-1 anticipates two
cross-group edges — `Persistence → Database` and `Persistence → Dto` — that `Repository` will
need to execute a statement and hydrate a DTO. They are **not** in this file yet, and that is
deliberate: granting them now would mean this config permitting an import that no code makes and
no ADR has argued for. They arrive with item 10.3, alongside the argument.

Proved rather than assumed, and it took the lesson item 8.1 learned about deptrac the hard way —
it resolves **type dependencies**, not `use` statements, so an unused import proves nothing. A
real return type does: planting `RowNormalizer::plantedViolation(): ?SqlStatement` gets
`RowNormalizer must not depend on SqlStatement`, which is precisely the edge 10.3 will have to
open.

## Lesson

When a legacy pipeline has been copied N times, every copy has quietly answered a design
question by not asking it — and the library version has to ask all of them out loud.
