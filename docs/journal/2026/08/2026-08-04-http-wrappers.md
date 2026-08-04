# 2026-08-04 — The client picks the type, not the application

Roadmap item **6.1**, opening Milestone 6. Route `fast / low`, session Opus 5 (standard) — *above*
the route this time rather than below, which is over-resourced rather than under. Recorded.

## The decision the whole item turns on

`?email=x` gives you a string. `?email[]=x` gives you an **array**.

Same key, different PHP type, and **the client chose which**. That's the whole parameter-pollution
family in one line, and the two obvious ways to handle it are both wrong:

- `(string) $_GET['email']` emits *"Array to string conversion"* and yields the literal `"Array"`
- `implode(',', ...)` invents a value nobody sent

Both convert attacker-controlled *shape* into a value the application then trusts. So a scalar
accessor handed a non-scalar returns its **default** — the honest answer, because a string is not
what arrived.

The same reasoning one level down: `queryInt()` uses `FILTER_VALIDATE_INT`, not a cast, because
`(int) "12abc"` is `12` — again, a value the client never sent.

`queryList()`/`postList()` exist for when a list *is* expected. A caller asking for a list has
decided a list is acceptable here, which is a different decision from being handed one
unexpectedly. They refuse scalars rather than wrapping them, because wrapping erases the
distinction they exist to preserve.

## Probes that changed things

| probe | result | consequence |
|---|---|---|
| `?role[]=admin` | array | the refusal above |
| `getallheaders()` | **not defined** here | headers come from `$_SERVER`, one path not two |
| `?0=zero` | **integer** array key | my type annotation was a lie (below) |
| `filter_var('', BOOLEAN, NULL_ON_FAILURE)` | **`false`**, not `null` | `?flag=` is false, not absent |

The last one caught me writing a test that asserted my *assumption* rather than PHP's behaviour. I
expected `null` for an empty value; PHP says `false`. Fixed the test to match reality and
documented it in the code, because it's surprising and someone will hit it. Following PHP rather
than inventing a third answer also keeps this consistent with `Env::get()`, which uses the same
filter.

## PHPStan caught an annotation that was a lie

I'd declared the superglobals `array<string, mixed>`. PHPStan max refused it, and it was right:
`?0=zero` produces an **integer** key. The honest type is `array<array-key, mixed>`.

Worth noting the shape of the error — not "this code is wrong" but "this *claim about the code* is
wrong". Casting it away would have kept the lie and silenced the messenger.

## A probe that passed for the wrong reason

Four planted defects. Three caught immediately:

| planted | failures |
|---|---|
| scalar accessors flatten arrays | 6 |
| `queryInt` uses a cast | 6 |
| header names stored case-sensitively | 8 |
| **CR/LF check removed** | **0 — passed** |

That fourth one should obviously have failed — the whole `splittingValues` provider exists for it.

I flagged in item 5.4's journal that *"a probe that doesn't fail is evidence about the code or about
the claim"*. This time it was neither: **the string replacement never matched the source**, so the
probe never applied. A passing probe that didn't run is not a weak test, it's no test at all.

Re-ran it by patching the verified line number instead of a fragile string match: 4 failures, as it
should have been. The lesson is narrow and practical — **verify the probe actually landed before
believing what it tells you**, especially when it tells you something reassuring.

That's now twice this session that Python string-matching against PHP has misfired. The Edit tool,
or a line-indexed patch, is the right instrument.

## Smaller decisions, recorded

- **`isSecure()` ignores `X-Forwarded-Proto`.** It's client-supplied unless a trusted proxy rewrote
  it, and this class can't know whether one did — trusting it lets any client claim HTTPS. It *does*
  check for the string `'off'`, because `$_SERVER['HTTPS']` is present-and-`'off'` on some servers,
  where an `isset()` check would report every request as secure.
- **Header values are validated at `withHeader()`, not at `send()`.** PHP's own `header()` rejects
  CR/LF, but validating at set time means a response assembled and inspected in a test fails the
  same way as one sent to a client.
- **Header names are case-insensitive but keep their spelling.** RFC 9110 makes them
  case-insensitive, so `Content-Type` and `content-type` must not become two headers — a duplicated
  `Content-Type` is how a response smuggles a second interpretation past a proxy.
- **`Response::html()` escapes nothing.** Escaping is a render-time decision that depends on where
  each value lands (ADR-0019's four contexts); a blanket pass over an assembled document would
  corrupt the markup it exists to carry. `json()` *does* go through `Json::encode()`, so an
  unencodable value raises rather than putting `false` in the body.
- **`Request` has no withers**, `Response` does. A response is built in stages across layers, where
  the alternative to immutability is an object a helper changes behind its caller's back. A request
  is only read — and withers would edge toward the middleware ambitions RFC-0001 explicitly warned
  these wrappers against.

## Bar

1109 tests / 2475 assertions green (up from 1037). `--group T-07` runs 72. PHPStan max clean,
deptrac 0/0, consistency lint OK.

## Next

**6.2** — `Session` hardening + `regenerate()`, and `CsrfToken`. Routed
`frontier-reasoning / extra`, and genuinely security-critical: CSPRNG generation, `hash_equals`
comparison, per-form scoping.
