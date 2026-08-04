# ADR-0019: Four escaping contexts, no general `escape()`, and assume the attribute is unquoted

- **Status:** Accepted
- **Date:** 2026-08-04
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 5.1 (opens Milestone 5) · spec FR-09 · item 5.4 (OWASP corpus snapshot
  suite) · [RFC-0001](../rfc/0001-egl-utils-library.md) §Context (security mechanism 3) ·
  [ADR-0015](0015-identifier-allowlist-closed-keywords-and-the-anchor-hole.md) (mechanism 2's
  allowlist-over-escape reasoning, applied again here)

## Context

RFC-0001's third security mechanism: *"Output: context-aware escaping at render time, never input
mutilation."* Spec FR-09 names four operations — `html()` with `ENT_QUOTES|ENT_SUBSTITUTE` and
UTF-8, `attr()`, `js()` in hex, and `url()` via `rawurlencode()`.

The word doing the work is **context-aware**. HTML is not one grammar but several nested ones, and
a value escaped for the wrong one is not partially safe, it is unsafe. Three facts were probed
before writing anything, and each changed the design:

| probe | result |
|---|---|
| `htmlspecialchars()` on invalid UTF-8 **without** `ENT_SUBSTITUTE` | returns **`''`** — the entire value silently vanishes |
| `htmlspecialchars()` on `/` and `` ` `` | neither is escaped, in any flag combination |
| `mb_convert_encoding($s, 'UTF-8', 'UTF-8')` on invalid input | substitutes **`?`**, not U+FFFD — disagreeing with `ENT_SUBSTITUTE` |

## Decision

### 1. Four methods, and deliberately no general-purpose `escape()`

There is no method that does not name its context. A convenience wrapper that guessed, or that
defaulted to "HTML", would be the one that got reached for in a `<script>` block — and the failure
would be invisible in review, because the call would look escaped. The four methods are documented
with what each is **not** safe for, and a test asserts the separation rather than leaving it as
docblock advice: `html()` output still contains the `/` that closes a `<script>` element, and still
contains the space that ends an unquoted attribute.

### 2. `attr()` assumes the attribute is **unquoted**

`html()` is sufficient for `x="…"` and `x='…'`. It is not sufficient for `x=…`, where a space,
tab, newline, `/`, `>` or backtick ends the attribute and starts a new one — `onmouseover=alert(1)`
needs no quote character at all.

**An escaper cannot see its own call site.** It cannot know whether the caller quoted the
attribute, and the two possible assumptions are not symmetric: assuming *quoted* is wrong in the
direction of an XSS hole, assuming *unquoted* is wrong in the direction of verbose output. So
`attr()` escapes **every non-alphanumeric ASCII character** as `&#xHH;` — OWASP's unquoted-context
rule, and inert in the quoted context.

This is the same shape of reasoning ADR-0015 used for SQL identifiers: prefer the strict, total
rule over the one that is correct only if the caller did something the callee cannot verify.

**Non-ASCII passes through unchanged**, and that is a decision rather than an omission. Every
character that can terminate an attribute is ASCII, so a multibyte sequence cannot be a delimiter.
Escaping the individual *bytes* — the literal reading of OWASP's "ASCII values less than 256",
written when single-byte encodings were the default — would emit one entity per byte and turn the
text into mojibake. Asserted, so nobody "fixes" it toward the literal reading later.

### 3. `js()` escapes everything non-alphanumeric, including two non-ASCII characters

An allowlist, not a denylist: a denylist in an escaper is a list of the attacks its author had
heard of. Three specifics that a naive "escape quotes and backslashes" misses, each a documented
break-out:

- **`/` is escaped.** Inside a `<script>` element the HTML parser runs *before* the JavaScript
  parser and knows nothing about string literals: the byte sequence `</script>` ends the element
  wherever it appears, quoted or not.
- **U+2028 and U+2029 are escaped**, despite being non-ASCII. They are JavaScript *line
  terminators* before ES2019 — an unescaped one ends the statement, and what follows is code
  rather than string data. They are the reason `js()` cannot pass non-ASCII through the way
  `attr()` safely can.
- **Output is pure ASCII** (`\xHH`, and `\uXXXX` with surrogate pairs above U+FFFF), so it survives
  whatever charset the surrounding document is actually served with.

`js()` makes a value safe **as a string in JavaScript**, not safe *as* JavaScript. Interpolating
attacker-controlled data where a string literal is not already the grammar — an event-handler
attribute, a `src`, `eval()` — is a different problem and is documented as out of scope rather
than silently implied.

### 4. `url()` escapes a component, and its likely misuse fails safe

`rawurlencode()` per FR-09 — not `urlencode()`, which renders a space as `+` (correct only in an
`x-www-form-urlencoded` body, wrong in a path segment where `+` is a literal plus).

It escapes **one component**, not a URL. Passing a whole URL encodes its `:` and `/`, yielding an
inert relative path. That misuse is stated prominently *because* the failure mode is favourable
and worth relying on: a broken link is visible, and `javascript:alert(1)` encodes to
`javascript%3Aalert%281%29`, which no browser treats as a scheme. Asserted as a test.

It follows that `url()` is **not** the defence for a whole-URL sink like `href="…"`; that needs
scheme allowlisting, which is a different mechanism, outside FR-09, and deliberately not invented
here.

### 5. Invalid UTF-8 becomes U+FFFD in all four, via PCRE rather than `mbstring`

`ENT_SUBSTITUTE` is the single most load-bearing flag in the class: without it a value containing
one bad byte renders as **nothing at all**, which is a bug that hides. `attr()` and `js()` do their
own escaping and so must reproduce that behaviour, or the library would answer differently for the
same input depending on which context a template used.

Implemented with a PCRE UTF-8-sequence pattern, **not `mbstring`**, for two reasons: `mbstring` is
not among this library's declared extensions (`ext-pdo`, `ext-fileinfo` — spec NFR-08), and a
security helper is the wrong place to quietly acquire a hard dependency; and
`mb_convert_encoding()` substitutes `?` rather than U+FFFD, which would have *disagreed* with
`html()`. Overlong encodings, surrogate halves and codepoints above U+10FFFF were each verified
rejected directly, because "the regex looks right" is not a property a security boundary should
rest on.

## Alternatives Considered

- **One `escape($value, $context)` with an enum** — rejected. It reads well, and it makes the
  context a *runtime argument* that can be wrong, defaulted, or passed a variable. Four named
  methods make the context a fact of the call site, visible in review and in a grep.
- **`attr()` delegating to `html()`** (sufficient if attributes are always quoted) — rejected on
  the reasoning in §2; probed, and it fails 16 tests including the unquoted break-out.
- **A denylist for `js()`** (quotes, backslash, angle brackets) — rejected; probed, and it fails
  6 tests including `</script>` break-out and the U+2028 line terminator.
- **Escaping every byte ≥ 0x80 in `attr()`** — the literal OWASP wording, rejected: it corrupts
  valid UTF-8 into mojibake and defends against nothing, since no multibyte byte is a delimiter.
- **Adding `ext-mbstring`** — rejected on NFR-08 and on the `?`-vs-U+FFFD disagreement above.
- **Adding scheme allowlisting to `url()`** — rejected as out of FR-09's scope, and because
  bundling two unrelated defences into one method makes it unclear which one a caller is getting.
  Named as a gap instead.
- **A CSS-context escaper** — not in FR-09; noted in the class docblock as an uncovered context
  rather than half-implemented.

## Consequences

- Four contexts, each with a documented safe-in and not-safe-in list, and a test that asserts the
  separation is real rather than advisory.
- **The suite is verified non-vacuous** against six planted defects, each caught and reverted:
  dropping `ENT_SUBSTITUTE` (2 failures), `ENT_COMPAT` instead of `ENT_QUOTES` (6), `attr()`
  delegating to `html()` (16), `urlencode()` instead of `rawurlencode()` (2), a `js()` denylist
  (6), `js()` passing non-ASCII through (6), and `toValidUtf8()` as a no-op (3).
- `Escaper` joins `StaticUtilityContractTest`, so its private constructor is asserted present and
  inert like every other static helper's.
- **The OWASP cheat-sheet snapshot corpus is item 5.4, not this item.** What ships here is the
  behavioural contract plus break-out payloads that distinguish the contexts; the corpus suite is
  a separate roadmap deliverable and is not pre-empted.
- `attr()` output is verbose — every space becomes `&#x20;`. That cost is paid on the path where
  the caller quoted the attribute and did not need it, in exchange for correctness on the path
  where they did not and it was exploitable.
- No CSS context, and no whole-URL/scheme validation. Both named, neither half-built.

## References

- Spec FR-09 (the four operations and `html()`'s exact flags), §7 (item 5.4's OWASP corpus)
- RFC-0001 §Context, security mechanism 3 — context-aware output escaping, never input mutilation
- ADR-0015 — the same "prefer the total rule over one that depends on the caller" reasoning
- Verified directly on PHP 8.3.1: `htmlspecialchars()` returning `''` without `ENT_SUBSTITUTE`;
  `/` and `` ` `` never escaped; `mb_convert_encoding()` substituting `?`; UTF-16 surrogate-pair
  arithmetic; `rawurlencode()` on a whole URL
