# ADR-0042: Trim is the only default, and the transcode runs first

- **Status:** Accepted
- **Date:** 2026-08-06
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 10.2 · spec r3 **FR-36**, suite **T-15** (RFC-0002) ·
  [RFC-0002](../rfc/0002-application-layer-groups-from-legacy-intake.md) §"Decision" (P-1, the
  layering note this item half-implements) · spec §1 (the rejection of blanket input
  sanitization) ·
  [ADR-0037](0037-disable-phps-escape-character-and-keep-the-formula-guard-opt-in.md) (the CSV
  formula guard, opt-in for this same reason) ·
  [ADR-0019](0019-four-escaping-contexts-and-the-unquoted-attribute-assumption.md) (substitute
  rather than silently drop) · item 9.1 (`Str::transcode`/`collapseWhitespace`/`nullIfBlank`,
  the primitives this composes) · item 10.3 (the two cross-group edges deliberately **not**
  granted here)

## Context

RFC-0002's survey found one row-cleanup pipeline — legacy-charset transcode, trim, collapse
internal whitespace, empty-string to `null` — copied **seventeen times** across the estate's
data-access classes, once per class, each copy free to drift. FR-36 asks for it as *"one
explicit, testable policy object"*.

"Explicit" is the whole requirement, and it forces a question the estate never had to answer:
**which of these four steps should happen when a caller says nothing?** The estate's copies all
did all four, unconditionally, because each was written for one schema. A library cannot inherit
that: three of the four steps *change data*, and a library that quietly rewrites a consumer's
values is the `magic_quotes` shape spec §1 exists to reject.

The estate's own version also settled an ordering question by accident. It read:

```
iconv('ISO-8859-15', 'UTF-8//IGNORE', trim($value))
```

— trimming **before** converting. For a single-byte source encoding that is harmless, because
every ASCII whitespace byte means whitespace there too. For any multibyte source it is
destructive: `trim()` strips bytes, and a byte that looks like `0x20` inside a UTF-16 sequence
is not a space. The estate is not exposed to this; a library shipped to unknown schemas would
be.

## Decision

`RowNormalizer` is an immutable policy object whose four switches default like this, and the
asymmetry is the decision:

| step | default | reason |
|---|---|---|
| transcode (`$fromEncoding`) | **off** (`null`) | most databases are already UTF-8. A guessed source encoding corrupts more values than it repairs, and there is no safe guess to make. |
| `$trim` | **on** | trailing spaces from a fixed-width `CHAR` column are an artifact of *storage*, not content. This is the one step where doing nothing is what surprises people. |
| `$collapseWhitespace` | **off** | `"a  b"` → `"a b"` alters content the caller may have meant. |
| `$blankToNull` | **off** | `''` and `NULL` are different values in SQL, and conflating them silently breaks a `NOT NULL` round trip. |

**Transcoding runs first**, then trim/collapse, then blank-to-`null` — a fixed order, not a
configurable one. Converting before touching bytes is the only ordering that is correct for a
multibyte source encoding, and blank-to-`null` must run last so it judges the value the earlier
steps produced (a `CHAR(20)` of spaces is blank *after* trimming, not before).

**Failure is strict by default**: with an encoding set and `$lossy = false`, a value the target
cannot represent raises `DatabaseException` **naming the column**, rather than dropping bytes.
That is `Str::transcode()`'s own stance inherited, and the direct answer to the estate's
`//IGNORE`, which discarded unconvertible characters silently in all seventeen copies.

## Alternatives Considered

- **All four steps on by default**, matching the estate — rejected: it makes the library's most
  data-destructive behaviour the one a caller gets by writing `new RowNormalizer()`. Three of
  the four would silently rewrite values, and one (`blankToNull`) can turn a legal `NOT NULL`
  column into a hydration failure two layers away.
- **All four off by default**, so the default is a no-op — rejected, but it was the closest
  call here. A no-op default is maximally honest and useless: the overwhelming reason this class
  exists is fixed-width `CHAR` padding, and a normalizer that must be configured before it
  normalizes anything invites every consumer to re-derive the same one-line configuration. Trim
  earns its default by being the step whose *absence* is the surprise.
- **A single `$legacy = true` flag** turning on the estate's whole pipeline — rejected: it
  encodes one schema's needs as a library concept, and the name would mean "whatever that other
  codebase happened to do". Named arguments already make the full pipeline one readable call:
  `new RowNormalizer(fromEncoding: 'ISO-8859-15', collapseWhitespace: true, blankToNull: true)`.
- **Configurable step order** — rejected: three of the orderings are simply wrong (see above),
  and offering them would be offering a way to corrupt data with no use case that wants it.
- **Lossy transcoding by default** (the estate's `//IGNORE`) — rejected on ADR-0019's rule:
  losing data must be opted into, never defaulted into.
- **Normalizing keys as well as values** — rejected: a column name is schema, not data. A
  normalizer that trimmed keys would silently change the array shape the hydrator matches
  against.
- **A dedicated `NormalizationException`** instead of `DatabaseException` — rejected as
  unnecessary surface: RFC-0002's FR-34 already declares that this group's failures are
  `DatabaseException`s, and a new leaf in the hierarchy would have to justify itself against
  that. The column name in the message is what makes the failure actionable, and it is there.

## Consequences

- **The pipeline exists once.** Seventeen copies collapse to one object whose behaviour is
  pinned by T-15's 26-row policy table — including the two cases a hand-rolled version gets
  wrong: `'0'` is **not** blank (PHP's `empty()` disagrees, and a flag column is exactly where
  that lands), and non-string values pass through by identity, so a BLOB resource is not fed to
  `iconv()`.
- **The `Persistence` group exists, with a Support-only edge.** Its deptrac layer is added here
  and **proved closed** in the two directions that matter: the planted
  `Persistence → Database` type dependency is rejected (`RowNormalizer must not depend on
  SqlStatement`), which is precisely the edge item 10.3 will have to argue for. Granting it now
  would mean permitting an import no code makes and no decision justifies.
- Strict transcoding means **one bad byte fails the row**, not just the value. That is the
  intended trade — a silently mangled name is worse than a loud failure naming its column — and
  `$lossy = true` is the documented way to choose otherwise.
- `Str::transcode()` requires `ext-iconv`, a *suggested* dependency. A consumer who sets
  `$fromEncoding` on a build without it gets that method's clear refusal rather than a
  degraded result; consumers who leave transcoding off never touch the extension. The
  transcoding tests carry `#[RequiresPhpExtension('iconv')]` for the same reason.
- **Not settled here:** whether `Repository` (item 10.3) applies a normalizer by default or
  requires one. That is a decision about *that* class's defaults, and it belongs to the item
  that adds it.

## References

- Spec r3 FR-36 and §6's T-15; RFC-0002 Context (the seventeen copies)
- ADR-0037, ADR-0019 — the same "data-changing behaviour is opt-in" rule, twice before
- Item 9.1's `Str::transcode()`, `collapseWhitespace()`, `nullIfBlank()`, whose composition this
  is; `nullIfBlank()`'s docblock names this exact pipeline as its intended use
