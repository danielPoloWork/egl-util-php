# 2026-08-26 — The defence I was asked for would never have run

Issue **#102**, all three items. Route `frontier-reasoning / high`; session model Opus 5.
**ADR-0079** annotated, spec **r27**.

Three minor findings from the release board's Senior Security Engineer seat, all under the same
constraint: the 1.0 surface is frozen, so every fix has to be additive. Two of the three turned out
to be documentation problems wearing code-shaped clothes, and the first one turned out to be a
defence that could not have executed.

## Probing before writing the guard was the whole job

Item 1 reads like a to-do: `guardScheme()` validates the caller's URL against an http/https
allowlist, `followRedirects: true` hands the hops to PHP's stream wrapper, the wrapper has no
per-hop callback — therefore re-apply the check per hop. I nearly wrote it.

What stopped me is that writing it requires knowing what PHP does with a hostile `Location`, and I
did not. So: a throwaway `php -S` origin that echoes back whatever `Location` it is handed, and six
hostile targets through it.

| `Location` | what PHP's wrapper did |
|---|---|
| `http://other-origin/…` | **followed** — the second origin logged the request |
| `//other-origin/…` | not absolute; requested as a same-host path |
| `file:///…/index.php` | requested as a **same-host path** over http; no file opened |
| `php://filter/read=convert.base64-encode/resource=…` | same — a path, not a wrapper |
| `data://text/plain,…` | same — a path, not a wrapper |
| `ftp://127.0.0.1/x` | **refused**; returned `false`, nothing fetched |

**PHP's http wrapper never leaves http/https on a redirect.** It refuses the hop or degrades the
`Location` to a path on the current host. A per-hop scheme check would have been unreachable code —
and ADR-0022 with item 12.1 already settled that this project does not ship defences a probe proves
inert. The stronger version of that argument applies to not writing one: unreachable code cannot be
tested, so it would have been an assertion about the codebase that no run could ever check, sitting
there implying the surrounding code needed it.

So the deliverable inverted. The claim is about *PHP's* behaviour, not ours, which means the risk is
not regression — it is a future PHP changing underneath a decision made on today's behaviour,
silently. That is what a pinning test is for, and T-07 now drives all six shapes on every PR.

Getting the assertion right took three attempts, each wrong in an instructive way. First I asserted
a 404, because my scratch probe's docroot had no such path — but the t07 origin is a router, so
status was the fixture's business and not the property. Then I asserted the origin's default `plain`
body, which fails because `php -S` serves its *own* 404 page for a path it cannot map rather than
routing it. What survived is the security property stated directly: the response must not contain PHP
source, the data-URI payload, or the base64 of a local file. Plus the half that stops it being
vacuous — a companion test proving those payloads *are* readable by this process, because
`assertStringNotContainsString` passes happily against a body that could never have held them.

**The residual is real and is not the one the issue named.** A cross-*origin* hop is followed,
bounded only by `maxRedirects`. The allowlist is about schemes and never claimed otherwise, so
enabling redirects towards anything you do not control is accepting an SSRF-shaped pivot. That is now
written on `HttpClient` where someone turning the flag on will read it, and a host allowlist is named
in the ADR as the shape of the fix rather than smuggled into a minor-findings sweep.

## An item that could not be done as written, and the honest version of it

Item 2 asks for "a named constructor for the logger-less form" to make the silent bcrypt downgrade
harder to reach. That cannot be built: `new Hash()` is frozen and permissive, and no addition makes
an existing constructor harder to call. The suggestion assumes a lever the freeze removed.

What is available is the mirror image — make the *safe* form reachable rather than the unsafe form
awkward. `Hash::strict()` is `new self(bcryptFallback: false)`: Argon2id or refuse to construct. The
behaviour already existed as a boolean a caller had to know about; the addition is that it now
appears in the class's API listing beside `make()` and `verify()`, which is the difference between a
policy being available and being found.

I deliberately did not add a second named constructor for the logging form. `new Hash(logger: $l)` is
already clear with named arguments, and adding API to a frozen surface has its own cost. What the
class gained instead is a docblock that lists all three constructions and marks the third as the
hazard: `new Hash()` on an Argon2-less build hashes with bcrypt and says so nowhere. Inverting that
default is a MAJOR change, so it is recorded as a 2.0 candidate rather than dressed up as fixed.

## The item where the fix was admitting what the default costs

Item 3 needed no code at all. ADR-0037's `guardFormulas: false` is right — the guard rewrites
exported data, and only the caller knows whether the file is going to a spreadsheet or to another
program. The gap was reach: the phpdoc explained the *decision* without ever naming the *attack*, and
the README had no CSV example whatsoever.

Both now do, with the framing that seems to me the honest way to keep an opt-in security default:
**the safe choice is the one you have to type.** A doc change cannot make a call site safe. It can
make an unsafe one indefensible, which is exactly what "cannot plausibly claim ignorance" asks for.

## Where this leaves the project

One added public method, no signature changes, no behaviour changes. Seven new T-07 tests, two new
`Hash` tests, and documentation in four places. The threat model was deliberately left alone — it is
an audit-phase scaffold, and half-filling one STRIDE row from a minor-findings PR would make it look
surveyed when it has not been.

The thing worth carrying forward is the shape of item 1: **the reflex to add a control is the thing
to check, not the thing to trust.** Three of this repository's ADRs now record a defence measured
inert (ADR-0022's fallback guards, item 12.1's, and this one), and in every case the measurement was
cheaper than the code would have been to maintain.
