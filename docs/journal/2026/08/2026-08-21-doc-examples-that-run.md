# 2026-08-21 — Four blocks that were one application, and the fifth defect that proved it

Roadmap item **13.3**, issue **#149**. Route `standard / medium`; session model Opus 5 — matched.

`docs/patterns/endpoint-kernel.md`'s flagship example had shipped since 1.0 unable to run. The item
named three defects, #149 added a fourth. Running it found a fifth, and the fifth is the one that
explains why the other four survived a release.

## The blocks were never four snippets

The page's four ` ```php ` blocks are `public/index.php`, `config/routes.php` and
`src/Http/EnvelopeMapper.php` of one application, plus one quoted piece of the legacy estate. They
name their own paths — `__DIR__ . '/../vendor/autoload.php'`, `'/../config/routes.php'`.

So the verification assembled exactly that layout and wrote each block into its stated place
**byte-for-byte**, on `egl/utils` v1.0.0 installed from Packagist, then drove it over HTTP through
`php -S`. Reproducing the layout means the page's *path* assumptions get checked too, not only its
calls.

The rule that makes a pass mean anything: application symbols the examples declare as external —
`App\Http\OrderController`, `App\Domain\DomainRefusal`, a PSR-11 container, a PSR-3 logger — are
stubbed, and **nothing under `D4np\Utils\` ever is.** A library class or method the docs invented has
to fail; that is the only failure this sweep is looking for.

## The fifth defect

Installed as a pair, the original blocks do not reach the private constructor. They die at
`config/routes.php:8`:

```
Warning: Undefined variable $container in .../config/routes.php on line 8
Fatal error: Uncaught Error: Call to a member function get() on null
```

`routes.php` used `$container` four times and **no block on the page ever created it.** Neither the
2026-08-09 filing nor #149's own re-verification caught that, and the reason is the same in both
cases: we read the blocks one at a time. A per-block review cannot see a variable that crosses
between them.

**A page's examples can be individually wrong and jointly worse.** That is the finding to carry, and
it is an argument for assembling rather than inspecting whenever blocks reference each other.

## The fix is a restructure, because immutability is not a typo

`Response` has no public constructor — five named ones instead — and it is immutable, so
`withHeader()` returns a *new* instance. The example's shape (build one mutable `$response`, poke at
it inside `catch` blocks, serialise at the end) cannot be repaired by substituting three tokens. The
405 branch now records `$allow`, the response is built once, and the result of `withHeader()` is
**assigned**, with a comment saying why: dropping it is how a mandatory header goes missing on one
branch only.

`routes.php` gained the `use App\Http\OrderController;` and `Psr\Container\ContainerInterface`
imports it needed, resolves the controller once instead of four times, and the page now states that a
`require`d file runs in the caller's scope — which is why `index.php` has to build the container
*before* the require. `EnvelopeMapper` gained its missing `Psr\Log\LoggerInterface` import and the
`translate()` method it called and never declared.

## The control is the whole evidence

With the assignment removed and nothing else changed, the wire says:

```
HTTP/1.1 405 Method Not Allowed
{"error":"method not allowed"}
```

Correct status. Correct body. **No `Allow` header.** RFC 9110 §15.5.6 makes it mandatory, and
`MethodNotAllowedException::allowHeader()` exists solely to supply it. Nothing a reader would look at
shows the loss — which is precisely how this survived a 1.0 release.

Fixed, the same request answers `405` with `Allow: GET, POST`.

## The harness lied once, on the branch that mattered

The first 405 driver used `DELETE /orders/1/extra`. That is a route **miss**, not a method mismatch,
so it took the 404 branch — and the case still printed `PASS`. The one branch carrying the header had
never run. `/orders` is registered for GET and POST, so `PUT /orders` is the real mismatch.

A second gap was structural rather than a mistake: in the CLI SAPI `header()` is a no-op and
`headers_list()` returns `[]`, so the CLI leg *could not observe* the `Allow` header at all. It
reported `allow=array ( )` on a passing 405 and looked fine. That is why the verification moved to
`php -S` and `curl -i` — the header only exists on a wire, so it has to be read off one.

Same family as 13.2's three attempts: **ask what your check would print if the thing you care about
were absent, before believing what it prints when it is present.**

## What was deliberately not touched

Of 26 ` ```php ` blocks in 18 tracked files:

- **4** are `README.md`'s, proven under 13.2.
- **1** is `packages/utils-psr7-bridge/README.md`'s, run here against the bridge from a path
  repository plus Nyholm as the PSR-17 vendor — **correct, unchanged**. Noted in passing: the bridge
  has no tag, so it installs only as `@dev`, which is issue #120 rather than a docs defect.
- **5 are fragments** — ADR-0010's annotation shape, ADR-0028's single PHPStan annotation, ADR-0034's
  four signatures, ADR-0055's enum case, spec 02's contract skeleton. They cannot execute, so their
  *claims* were checked against the source instead: all four `Request` readers, the `Container`
  return annotation, and `Psr7Bridge`'s constructor and four methods still exist as written. No rot.
- **11 in `docs/journal/`, plus `.specs/d4np-php.md`** — out of scope **on principle**. A journal
  entry is dated; `.specs/d4np-php.md`'s own banner declares it an imported pre-rename artifact, so
  its `D4np\Php\` namespace is correct *in context*. Repairing either falsifies a record rather than
  fixing rot.

**ADR-0055's block is the sharpest case: it is deliberately invalid PHP**, documenting the enum-case
form 8.1 rejects. A sweep that made every block compile would have destroyed the point of the ADR it
lives in. Worth stating because "make all the examples run" is the obvious reading of this item and
it is wrong.

So #149's estimate of *"11 live blocks in 8 files"* is corrected to **10 in 7**.

## Left standing

**Nothing in CI executes a doc example.** Every one is now verified as of the change that touched it
and not continuously, and the README says so rather than implying otherwise. A CI job that runs the
blocks is the durable half of this problem and stays filed — this item fixed the rot, not the reason
it accumulated.
