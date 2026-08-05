# 2026-08-05 — The contract that could not be implemented

Roadmap item **8.2**. Route `standard / high` → Opus 5, the session model. No mismatch. Isolated
worktree again.

## Blocked at the first line

I wrote spec 02's conversion contract myself, at item 7.4, and it was wrong in a way that only
showed when someone tried to implement it. `requestToPsr7()` needs the whole `$_GET`, `$_POST`,
`$_COOKIE` and `$_FILES` collections. The core exposes **none** of them:

```
queryString($key)  queryInt($key)  queryBool($key)  queryList($key)
postString($key)   postInt($key)   postBool($key)   postList($key)
cookie($key)       file($key)
```

Every collection reader is key-scoped. Only `headers()` returns a whole collection. There is no way
to *enumerate* anything.

Two of the four were partly recoverable — the query string is in `uri()`, cookies are in the
`Cookie` header — and that route was worse than it looked: it would have introduced a **second
parsing path** beside the one PHP already ran, disagreeing with the core's own view the moment a
server rewrote a request. This project delegates rather than hand-rolls for exactly that reason
(ADR-0021). POST and `$_FILES` were not recoverable at all.

So the item stopped and went to the maintainer with four options. They chose to widen the core:
`queryAll()`, `postAll()`, `cookieAll()`, `uploadedFiles()` — **ADR-0034**.

The objection worth answering in that ADR is that a class built on "refuse rather than coerce" now
returns `mixed` values. It does not conflict: ADR-0025 governs **scalar** reads, where
`(string) ['x']` yields the literal `"Array"` — a value nobody sent. A whole-collection reader
promises no conversion, so there is nothing for it to convert wrongly. A test asserts both side by
side, because otherwise the raw reader reads like a loophole in the rule rather than a different
question.

Worth noting what the process got right: writing the contract before the implementation is what
*found* this, three items early, as a design question with alternatives instead of a hack discovered
mid-conversion.

## Five planted defects, all caught, on both vendors

The suite exists for clauses a plausible implementation gets wrong. So each was planted:

| planted | failures |
|---|---|
| the `Set-Cookie` multi-header refusal removed | 2 (nyholm + guzzle) |
| a failed upload's stream opened | 2 errors |
| an object parsed body accepted | 2 |
| a nested upload tree accepted | 2 |
| the body rewind dropped | 2 |

Two of those deserve their own note.

**BFR-18, the `Set-Cookie` join.** PSR-7's `getHeaderLine()` comma-joins, and that is correct for
every header except this one: RFC 6265 cookie strings contain commas themselves
(`Expires=Wed, 21 Oct 2026 …`), so joining two produces a string no client can split back. The core
holds one value per header name and cannot carry the list, so the bridge refuses. Removing the check
breaks exactly one test and nothing else — which is the point: a naive implementation passes
everything else while silently corrupting cookies.

**BFR-11's failed upload.** Both vendors' `UploadedFile::getStream()` *throws* when the error code is
not `UPLOAD_ERR_OK`. So the assertion is inverted and sharp: if the bridge touched that stream the
test would error, and it does — planting the access produced 2 errors rather than 2 failures.

## What the boundary test caught, which was me

Developing needed the package's dependencies, so I ran the PR-mode recipe locally. It rewrote the
committed manifest — `egl/utils: "@dev"` plus a `repositories` block — and the full core suite then
failed on **`BridgePackageBoundaryTest`**, the two assertions item 8.1 wrote for precisely this.

That is the invariant working on its author, one item after it was written, catching the exact
mutation predicted. `git checkout -- packages/utils-psr7-bridge/composer.json` and it was clean
again — but without those tests it would have been committed, and the published package would have
pointed at `../../` for every standalone consumer while everything here stayed green.

## Two corrections to my own spec

**BFR-07's wording.** r1 said the upload stream "lazily wraps" `tmp_name`. Whether a factory opens
eagerly is the *implementation's* business and neither reference vendor defers, so the clause now
states what the bridge actually controls: no stream over `tmp_name` is created at all for a failed
upload.

**No `serverAll()`.** I added exactly the four readers the blocked clauses needed and stopped. PSR-7
server params are not among the core-observable projections BFR-20 round-trips, and everything the
core reads out of `$_SERVER` is already reachable through `method()`, `uri()`, `isSecure()` and
`headers()`. Widening an API by "while I'm here" is how a public surface grows past what anyone
asked for.

## The `\U` tax, fifth occurrence

Python string literals containing `D4np\Utils\…` raise `SyntaxError: truncated \UXXXXXXXX escape`.
This is the fifth time this session, and the second time I hit it *after* recording the lesson. The
rule is simple and I keep reaching past it: **use the Edit tool for PHP, never Python string
matching.** Recording it again, in the item where I finally started following it mid-task rather
than after.

## Bar

Core: 1312 tests / 2973 assertions green. Package: 65 tests / 202 assertions across both PSR-17
implementations. PHPStan max clean in both trees, deptrac 0/0, PHP-CS-Fixer clean, consistency lint
OK, 34 action pins verified upstream.

## Next

**8.3** — the publication pipeline: signed-source-tag verification, release-mode contract run
against the released core, subtree split and translated tag. It needs a core release to exist, so
its real first run waits on `v0.7.0`.
