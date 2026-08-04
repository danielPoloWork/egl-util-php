# ADR-0021: Delegate rich-HTML sanitization, and escape LIKE wildcards with a portable character

- **Status:** Accepted
- **Date:** 2026-08-04
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 5.2 · spec FR-09b, FR-10, §7 T-02, NFR-08 · item 4.4 (which left T-02's
  third leg open, closed here) · [RFC-0001](../rfc/0001-egl-utils-library.md) §Context (security
  mechanisms 1 and 4) · [ADR-0019](0019-four-escaping-contexts-and-the-unquoted-attribute-assumption.md)
  (mechanism 3) · [ADR-0012](0012-enforce-the-layering-rule-by-directory-over-src-main.md) (the
  layering rule this item first tested against an external package)

## Context

Two deliverables, addressing the two places output escaping does not reach.

**FR-09b — rich HTML.** When rendering user-authored markup *is* the feature, escaping it defeats
the feature. RFC-0001 requires delegation and says so in as many words: *"no hand-rolled tag
stripper"*. HTML sanitization is a parsing problem, and a regex stripper is defeated by the same
mutation-XSS corpus every time one is written.

**FR-10 — `LIKE` wildcards.** Item 4.4's injection suite proved a `LIKE` value binds safely and
cannot inject SQL. It also documented what binding does *not* fix: `%` and `_` are **pattern**
syntax, not SQL syntax, so they survive binding intact. A search box forwarding `%` turns an
indexed lookup into a full scan, and a per-user search into one matching every row the query's
other conditions allow. Item 4.4 left this open, named FR-10 as the owner, and pinned the gap with
a test saying which assertion should change when this item landed.

Three things were probed before designing, and two changed the design:

| probe | result |
|---|---|
| bound `LIKE '100%'` against a seeded table | matches `100%`, `100X`, `1000` — the wildcard is live |
| escaped pattern **without** an `ESCAPE` clause, on SQLite | matches **nothing** — silently |
| `ESCAPE '\'` written as a SQL literal, on SQLite | **parse error**: `unrecognized token` |

## Decision

### 1. The escape character is `!`, not `\`

The obvious choice is a backslash, and it is wrong for a portable library. A backslash is itself
special inside a string literal on several drivers, so the `ESCAPE` clause needs a different
spelling per driver — and on SQLite `ESCAPE '\'` does not merely misbehave, it **fails to parse**.
`!` has no meaning inside a string literal anywhere, so one spelling works on every driver.

Exposed as `Sanitizer::LIKE_ESCAPE` because an escaped pattern is useless without the matching
clause, and a caller writing that clause by hand needs to know which character to name.

### 2. The escape character is escaped **first**

`str_replace` is applied with the escape character ahead of the wildcards it introduces. Reversed,
the pass over `%` would insert `!` characters that the later pass over `!` would then double —
turning `100%` into `100!!%`, a literal `!` followed by a live wildcard. Pinned by a test
(`'!%'` → `'!!!%'`), and probed: the reversed ordering fails five tests.

### 3. `QueryBuilder::whereLike()` emits the `ESCAPE` clause, so the common path cannot fail silently

This is the part that matters most, because the failure it prevents is invisible. `where()` with
`Operator::Like` emits no `ESCAPE` clause. Without one:

- **MySQL and PostgreSQL** treat backslash as an escape by default, so a backslash-escaped pattern
  appears to work
- **SQLite** has no default escape at all, and the pattern **silently matches nothing**

So code written and tested against MySQL returns quietly wrong results on SQLite. `whereLike()`
emits `LIKE ? ESCAPE '!'` unconditionally, which changes nothing for an unescaped pattern and makes
an escaped one correct everywhere.

**It does not escape the pattern for you**, deliberately: it cannot know which wildcards the caller
meant. A prefix search is `Sanitizer::sqlLikePattern($term) . '%'`, where the user's portion is
literal and the trailing `%` is the caller's. Escaping the whole pattern would turn every `LIKE`
into an equality test.

**The escape character is duplicated across the two groups rather than shared**, because RFC-0001's
layering rule forbids `Database` → `Security` (ADR-0012, enforced by deptrac). `SanitizerTest`
asserts the two constants agree via reflection, so the copy is checked rather than trusted — the
same treatment ADR-0010 gave the docblock/attribute duplication it could not avoid.

### 4. `richText()` delegates, throws when the delegate is absent, and keeps Symfony out of its signature

`symfony/html-sanitizer` is **optional** (NFR-08 keeps the core free of third-party implementation
code), declared in `suggest` and installed only as a dev dependency here.

**A missing optional dependency is a security question, not a convenience one, and it throws.**
Returning the input unchanged would hand a caller who asked for sanitization their attacker's
markup; returning `''` would silently destroy content. Neither announces itself. Verified
behaviourally by pointing the guard at a class that genuinely does not exist and confirming
`UtilsException` is raised rather than the input returned.

**No Symfony type appears in the public signature** — `richText(string): string`. A type-hint
naming a class from an optional package makes that package effectively required for anyone
reflecting over this class, which is the opposite of optional. The cost is that the profile is not
caller-configurable; that is FR-09b's *"curated allowlist profile"* read literally, and a consumer
needing a different one can use the component directly.

The profile starts from Symfony's own `allowSafeElements()` — delegation applied one level down,
rather than re-deriving a list of safe elements here — and adds what that alone does not cover:
link schemes limited to `https`/`http`/`mailto` (this is what refuses `javascript:` and `data:`),
relative links and media refused, and `rel="noopener noreferrer"` forced onto every link.

### 5. The optional dependency gets its own deptrac layer

This is the library's **first third-party dependency in production code**, and it took the layering
gate from `Uncovered: 0` to `Uncovered: 6` — exactly the signal ADR-0012 built it to give.

Rather than waving vendor code through, `HtmlSanitizer` is declared as its own layer that **only
`Security` may reach**. An import of it from `Dto` or `Http` is now a build failure rather than a
silent new coupling to an optional package. Verified by planting `Http → HtmlSanitizer` and
confirming the violation.

## Alternatives Considered

- **A backslash escape character**, matching most tutorials — rejected on the probe: `ESCAPE '\'`
  is a parse error on SQLite, and the clause would need per-driver spelling.
- **Escaping the whole `LIKE` pattern inside `whereLike()`** — rejected: it cannot distinguish the
  caller's own wildcards from the user's, and would silently degrade every `LIKE` to equality.
- **Returning the input unchanged when `symfony/html-sanitizer` is absent** — rejected, and it is
  the tempting one because it "keeps working". It keeps working by handing back exactly what the
  caller asked to be made safe.
- **Sharing `LIKE_ESCAPE` through a `Support` constant** so the two groups could not drift —
  rejected as putting a SQL-dialect concern in the layer that is supposed to know nothing about
  SQL. A reflection-based agreement test is cheaper and keeps the concern where it belongs.
- **Accepting an `HtmlSanitizerConfig` parameter** for a caller-supplied profile — rejected: it
  puts an optional package's type in a public signature (see §4).
- **A blanket deptrac rule ignoring `vendor/`** — rejected: it would have silenced this finding and
  every future one, which is the opposite of what item 3.6 built the gate for.

## Consequences

- **Spec §7's T-02 suite is complete.** Its third leg, open since item 4.4 and explicitly deferred
  there, is closed; the item-4.4 test that asserted the gap now asserts the fix, and
  `--group T-02` runs 343 tests.
- `sqlLikePattern()` is asserted **against a real driver**, not on strings alone. Asserting that
  `%` became `!%` would only prove the method does what it was written to do; whether the driver
  then treats it as a literal is a question only the driver can answer.
- The silent-failure mode is itself a test:
  `testWithoutTheEscapeClauseAnEscapedPatternSilentlyMatchesNothing()` pins that `where()` +
  `Operator::Like` returns nothing for an escaped pattern, which is why `whereLike()` exists.
- **The suite is verified non-vacuous**: the escape-ordering bug (5 failures), allowing
  `javascript:`/`data:` schemes (1), and dropping forced `rel="noopener"` (1) are each caught.
- **The `prefer-lowest` job caught a deprecation the normal resolution hides.** `symfony/html-sanitizer`
  depends on `masterminds/html5`, whose 2.7.2 release calls
  `DOMImplementation::createDocument(null, null, $dt)` — passing `null` to a `string` parameter,
  deprecated on PHP 8.1+ and therefore a failure under this project's warnings-as-errors bar. The
  normal resolution picks 2.10.1 and never sees it; only the lowest-allowed resolution does, which
  is precisely why item 1.7 added that job.
  Fixed by declaring `masterminds/html5: ^2.7.5` in `require-dev` — a transitive dependency named
  directly only to raise its floor. **2.7.5 is the exact version that fixes it**, established by
  bisecting the upstream source across 2.7.3–2.9.0 rather than picking a comfortable-looking
  number, so the floor is the true minimum and does not exclude working versions.
- **A gap named rather than hidden:** the missing-dependency path is proven by probe but has **no
  permanent test**, because the package is installed as a dev dependency and cannot be absent
  during the suite. A CI job running without dev extras would cover it; that is build
  infrastructure rather than a test, and is not built here.
- `richText()` is not caller-configurable. Stated in the class rather than left to be discovered.

## References

- Spec FR-09b (delegation, "no hand-rolled tag stripper"), FR-10 (wildcard escaping), §7 T-02,
  NFR-08 (dependency policy)
- ADR-0012 — the layering gate that surfaced the new external dependency
- ADR-0019 — output escaping, the mechanism this one complements
- Verified directly on PHP 8.3.1 / `pdo_sqlite`: live wildcards under binding, the missing-`ESCAPE`
  silent failure, and `ESCAPE '\'` as a parse error
