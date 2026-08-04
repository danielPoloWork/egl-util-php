# 2026-08-04 — Four grammars, and the assumption an escaper cannot check

Roadmap item **5.1**, opening Milestone 5. Route `frontier-reasoning / extra`; session switched to
Fable 5 — the frontier tier. First time in three items the route and the session actually agree.

## Three probes, three design changes

Before writing anything:

| probe | result | what it changed |
|---|---|---|
| `htmlspecialchars()` on invalid UTF-8 **without** `ENT_SUBSTITUTE` | returns **`''`** | made UTF-8 handling the class's central concern, not a footnote |
| `htmlspecialchars()` on `/` and `` ` `` | never escaped, any flags | `attr()` and `js()` cannot be built on it |
| `mb_convert_encoding($s,'UTF-8','UTF-8')` on bad input | substitutes **`?`**, not U+FFFD | ruled out mbstring on correctness, not just dependency grounds |

The first one is the one I'd have gotten wrong by reading the spec alone. FR-09 pins
`ENT_SUBSTITUTE` and it would be easy to treat that as a tidiness flag. It isn't: without it a
value containing one malformed byte renders as **nothing at all**. A template that silently emits
an empty string where a username should be is a bug that hides indefinitely.

That finding then propagated. `attr()` and `js()` do their own escaping, so they had to reproduce
the same U+FFFD substitution or the library would answer differently for the same input depending
on which context a template happened to use. Hence a UTF-8 sanitizer — built on PCRE, because
mbstring is not a declared extension (NFR-08) *and* because it substitutes `?`, which would have
disagreed with `html()`. Verified the pattern rejects overlong encodings, surrogate halves, and
codepoints above U+10FFFF, because "the regex looks right" isn't a property a security boundary
should rest on.

## The decision I want on record

**`attr()` assumes the attribute is unquoted.**

`html()` is sufficient for `x="…"` and `x='…'`. It is useless for `x=…`, where a space ends the
attribute and the next token starts a new one — `onmouseover=alert(1)` needs no quote character at
all.

An escaper **cannot see its own call site.** It cannot know whether the caller quoted. And the two
possible assumptions are not symmetric:

- assume quoted → wrong in the direction of an XSS hole
- assume unquoted → wrong in the direction of verbose output

So: every non-alphanumeric ASCII becomes `&#xHH;`. Every space in every attribute becomes
`&#x20;`, forever, on every page — paid on the path where the caller *did* quote and didn't need
it, in exchange for correctness on the path where they didn't and it was exploitable.

This is the same shape as ADR-0015's identifier reasoning: prefer the total rule over one that's
correct only if the caller did something the callee can't verify. Nice to notice the project has
developed a consistent instinct here rather than deciding it fresh each time.

## Where the literal spec reading would have been wrong

OWASP's rule for attributes says "escape all characters with ASCII values less than 256". Read
literally in 2026 that means escaping bytes `0x80`–`0xFF` — which in UTF-8 are *continuation
bytes*, not characters. Doing it would emit one `&#xHH;` per byte and turn `héllo漢🙂` into
mojibake, while defending against nothing: no multibyte byte can terminate an attribute.

The rule was written when single-byte encodings were the default. I implemented its intent
(neutralise every ASCII delimiter) rather than its letter, and pinned that with a test so nobody
"corrects" it toward the literal wording later — the same move item 4.2 made when FR-07's own
regex turned out to be a bypass.

## `js()` is not "escape quotes and backslashes"

Three break-outs a denylist misses:

- **`/`** — inside `<script>`, the HTML parser runs first and knows nothing about JS string
  literals. `</script>` ends the element wherever it appears, quoted or not.
- **U+2028 / U+2029** — JavaScript line terminators before ES2019. Unescaped, one ends the
  statement and everything after it is code. These are why `js()` can't pass non-ASCII through the
  way `attr()` safely can.
- **everything else non-alphanumeric** — a denylist in an escaper is a list of the attacks its
  author had heard of.

Output is pure ASCII (`\xHH`, `\uXXXX` with surrogate pairs above U+FFFF) so it survives whatever
charset the document is actually served with.

Documented plainly: `js()` makes a value safe **as a string in** JavaScript, not safe **as**
JavaScript. Event-handler attributes, `src`, `eval()` are a different problem.

## A misuse that fails safe, so I pinned it

`url()` escapes a *component*. The likely misuse is passing a whole URL, which encodes its `:` and
`/` into an inert relative path. That's a **visible** failure — a broken link, not a silent hole —
and `javascript:alert(1)` encodes to `javascript%3Aalert%281%29`, which no browser reads as a
scheme. Worth a test precisely because the failure mode is favourable and it's reasonable to rely
on it.

What `url()` is *not* is the defence for `href="…"`. That needs scheme allowlisting — a different
mechanism, outside FR-09, deliberately not invented here. Named as a gap rather than half-built.

## Non-vacuity

Seven planted defects, each caught and reverted:

| planted | failures |
|---|---|
| `attr()` delegates to `html()` | 16 |
| `ENT_COMPAT` instead of `ENT_QUOTES` | 6 |
| `js()` uses a denylist | 6 |
| `js()` passes non-ASCII through | 6 |
| `toValidUtf8()` is a no-op | 3 |
| `html()` drops `ENT_SUBSTITUTE` | 2 |
| `urlencode()` instead of `rawurlencode()` | 2 |

One of these needed redoing: my first denylist probe produced a broken regex and 61 *errors*,
which is not a meaningful signal — an error means the code didn't run, not that the test caught
something. Redid it cleanly to get a real 6 failures.

## Bar

661 tests / 1433 assertions green (up from 597). PHPStan max clean, deptrac 0/0 — `Security` is now
a third real group depending on nothing, which the layering gate confirms.

## Next

**5.2** — `Sanitizer::richText()` over `symfony/html-sanitizer` (an *optional* dependency, a first
for this library) plus `sqlLikePattern()`, which also closes T-02's third leg left open at item
4.4. Routed `frontier-reasoning / extra`, matching this session.
