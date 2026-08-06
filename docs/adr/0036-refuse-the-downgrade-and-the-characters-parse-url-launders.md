# ADR-0036: Refuse the scheme downgrade, and refuse the characters `parse_url()` launders

- **Status:** Accepted
- **Date:** 2026-08-06
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 9.3 · spec r3 FR-27, r4 (exception enumeration) ·
  [RFC-0002](../rfc/0002-application-layer-groups-from-legacy-intake.md) §Decision (`Support`
  additions) · [ADR-0019](0019-four-escaping-contexts-and-the-unquoted-attribute-assumption.md)
  (name the assumption an escaper cannot verify) ·
  [ADR-0025](0025-typed-http-wrappers-that-refuse-rather-than-coerce.md) (refuse rather than
  coerce; CR/LF refused at set time; `array-key`, not `string`) ·
  [ADR-0021](0021-delegate-rich-html-and-escape-like-wildcards-with-a-portable-character.md)
  (a portable refusal over a per-driver spelling)

## Context

FR-27 asks for a URL value object with "parse/normalize/build, query composition,
**scheme-downgrade refusal on rebuild**". The clause exists because of a specific estate
helper: it decomposed a URL and rebuilt it as `"http://{$host}{$path}"` — a hardcoded
scheme. Every `https` address it touched came back plaintext, and the query and fragment
were dropped on the way.

Four things were probed against PHP 8.3.1 before the class was designed. **Two changed it.**

| probe | result |
|---|---|
| `parse_url("https://example.com\n/evil")` | **succeeds**, host `example.com_` — the newline is rewritten to `_`, not rejected |
| `parse_url('not a url')` | **succeeds**, `['path' => 'not a url']` |
| `filter_var('https://exämple.com/', FILTER_VALIDATE_URL)` | **false** — rejects valid internationalized hosts |
| `http_build_query(['a' => '1', 'b' => null])` | `a=1` — the null entry vanishes with no signal |

The first is the finding that matters. `parse_url()` does not reject C0 control characters;
it **launders** them, substituting `_` for each. So a CRLF payload produces a parse that
looks clean, with components that differ from the string that was supplied. Any code that
validates the parsed result and then forwards the *original* string is inspecting a value
the caller never sent — and CR/LF is precisely the payload that matters when a URL reaches
a request line or a `Location` header.

The second says that "did `parse_url()` succeed?" cannot answer "is this a URL?".

The third rules out the obvious shortcut: `FILTER_VALIDATE_URL` would have caught the
newline case, but it also rejects legitimate IDN hosts, so adopting it as the gate trades
a real security check for a real correctness bug.

## Decision

### 1. Refuse C0 controls and DEL before `parse_url()` sees them

`Url::parse()` rejects `[\x00-\x1F\x7F]` up front, and every wither applies the same guard
to what it is given. The invariant this buys is worth stating plainly: **the input and the
parsed value are the same string**. Nothing is silently rewritten, so a validation performed
on the components is a validation of what arrived.

The guard is a refusal, not a sanitization, for ADR-0019's reason: a URL containing a
newline is not a URL with a formatting problem, it is a different value than the one the
caller believes they hold. Rewriting it would make this class the second thing on the path
that quietly changes the address.

Spaces (`0x20`) are deliberately **not** refused. A literal space makes a URI invalid, but
it is a validity question rather than an injection one, and whether it should become `%20`
or `+` is a semantic choice belonging to the caller.

### 2. Absolute URLs only

A scheme and a host are both required; a relative reference is refused with a message
saying so. FR-27's purpose is addresses that survive recomposition, and a string with no
scheme has no scheme to protect. This also gives `parse_url('not a url')` — which otherwise
"succeeds" — a clear answer.

### 3. The downgrade rule, and the limit it does not cover

`withScheme()` refuses a transition from an encrypted transport to its plaintext
counterpart: `https`→`http`, `wss`→`ws`, `ftps`→`ftp`, `sftp`→`ftp`. Upgrades and
same-scheme calls pass. The comparison is done on the lowercased scheme, so `HTTP` does not
evade it — asserted by a test, and by a planted defect that reads the raw input instead.

**The honest limit:** a scheme this class does not know is allowed through. `https` →
`myapp` succeeds. The security properties of an unrecognised scheme cannot be asserted, and
refusing every unknown would make the class unusable for custom schemes; the alternative —
an allowlist of known-good schemes — was rejected because it fails closed on legitimate
values a library cannot enumerate in advance. A test pins this so the limit is visible
rather than discovered.

The refusal is the *second* line of defence, and the weaker one. The first is that the
object **carries** its scheme through every recomposition, which makes the estate's actual
defect — losing the scheme and substituting a constant — structurally unreachable rather
than merely discouraged.

### 4. An untouched query is preserved byte-exact

The raw query string is the stored form. It is returned unchanged by `rawQuery()` and
recomposed unchanged by `toString()`; only a `withQuery*` call re-encodes. Normalizing a
query nobody edited would reorder and re-encode parameters, invalidating any signature
computed over the URL — a silent breakage in exactly the integrations that care most.

`query()` decodes on demand via `parse_str()` and is documented as lossy in the way
`parse_str()` is: repeated keys collapse. Its keys are typed `array-key`, not `string`,
because `?0=zero` yields the **integer** key `0` — ADR-0025's superglobal-key lesson,
reached again from a different direction.

Composition encodes with `PHP_QUERY_RFC3986`, not `http_build_query()`'s default: the
default is the HTML-form encoding (`+` for space), which is not what a URL query is.

### 5. A `null` query value is refused, at any depth

`http_build_query()` drops null entries without a signal, so a caller who meant "send this
key empty" gets a URL missing the key entirely. `withQuery()` refuses it and names the
alternative (`''`, or `withoutQueryParam()`).

The **recursion** was found by PHPStan rather than by design. The acceptable value shape is
recursive (scalars, or nested arrays of scalars), which PHPDoc cannot express without lying
at some depth; typing the parameter honestly as `array<array-key, mixed>` removed the
static check and made it obvious that the runtime guard — which at that point walked only
the top level — was the *whole* enforcement, and was incomplete. It now descends and names
the offender's dotted path.

That the enforcement is a runtime walk rather than a type is the right split for this
library regardless: spec §1 names native and legacy applications as the audience, and those
consumers run no static analysis at all. A type-only refusal would protect only the
consumers least likely to need it.

### 6. One new exception, `InvalidUrlException`

All four refusals raise `InvalidUrlException extends UtilsException`, with the cause named
in the message. A consumer needs to separate "this URL is unusable" from every other
library failure — item 11.1's `HttpClient` will have to, since it must distinguish a bad
address from a failed request. A distinct type *per cause* (a `SchemeDowngradeException`
for security logging, say) was considered and deferred under the precedent items 9.1 and
9.2 set: a type is earned by a consumer that needs the distinct `catch`, and adding a
subclass later is additive.

Spec §2's r3 exception enumeration did not list this type, so **the spec is amended to r4**
in the same PR rather than left to drift (AGENTS.md §7).

## Alternatives

1. **`FILTER_VALIDATE_URL` as the gate** — rejected on the probe: it rejects valid IDN
   hosts (`https://exämple.com/`), trading a correctness bug for the control-character
   check that an explicit refusal provides without the collateral damage.
2. **Sanitize control characters instead of refusing** — rejected: it makes this class the
   second component on the path that silently changes the caller's address, which is the
   defect being fixed, not a fix for it.
3. **Remove `withScheme()` entirely** (a scheme fixed at parse time cannot be downgraded) —
   rejected: it also forbids the legitimate `http`→`https` upgrade, which is the operation a
   consumer hardening a stored URL actually wants. Refusing only the downgrade keeps the
   useful direction open.
4. **An allowlist of permitted schemes** — rejected: fails closed on legitimate custom
   schemes a library cannot enumerate, and the failure would surface as an unusable class
   rather than as a security event.
5. **Normalize the query on parse** (sort, re-encode canonically) — rejected: it breaks any
   signature computed over the URL, silently, in the integrations that care most.
6. **Type `null` out of `withQuery()`'s signature** — rejected: it would give a compile-time
   error to PHPStan-max consumers and *nothing* to everyone else, while making the runtime
   guard untestable. An untested guard is worse than a slightly wider type.

## Consequences

**Easier:** a URL can be inspected, edited and recomposed without a component going missing;
credentials are reportable (`userInfo()`) and strippable (`withoutUserInfo()`) before a URL
reaches a log; a downgrade is a caught exception rather than a plaintext request. Item 11.1's
`HttpClient` inherits all of it, including the control-character refusal, before it builds
its first request line.

**Harder / accepted:** the class is stricter than `parse_url()`, so a caller passing a
relative reference or a laundered string now gets an exception where PHP would have returned
something; normalization is visible (`https://example.com` becomes `https://example.com/`),
which a byte-comparison against the input will notice; and the unknown-scheme limit in §3
means the downgrade refusal is not a complete scheme policy — it is the *known* downgrades,
named.

**Verification:** 82 tests across three suites, and the suite is **proved non-vacuous by 11
planted defects** — neutering the control-character guard, dropping DEL from it, removing the
downgrade check, making that check read the raw scheme (case evasion), form-encoding the
query, dropping the null refusal, removing its recursion, dropping the absoluteness check,
keeping the default port, skipping the scheme lowercase, and re-encoding the raw query at
parse. Each was caught.
