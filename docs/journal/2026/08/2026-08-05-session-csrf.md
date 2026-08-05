# 2026-08-05 — A probe that passed, and this time it was the code

Roadmap item **6.2**. Route `frontier-reasoning / extra`, session Opus 5 (standard) — the same
mismatch the maintainer accepted at 5.3 and which 5.4/5.5 ran under. Recorded, proceeded on that
precedent rather than asking a fourth time.

## One probe shaped the whole item

```
session_start()             -> false
session_set_cookie_params() -> false
session_regenerate_id(true) -> false
```

PHP will not run a session in CLI. **Nothing about a live session is testable in the unit suite.**

Left alone that means FR-15's three cookie flags and the entirety of CSRF validation have no unit
assertion — all of it resting on item 6.3's `php -S` integration suite. For CSRF validation
especially, that's the wrong place for the only coverage to live.

So, two structural moves, both borrowed from decisions this project already made:

- **The cookie policy is a value.** `cookieParams()` returns exactly what `start()` will apply and
  is pure. FR-15's flags become assertable without a session. Same move as ADR-0022's
  `selectAlgorithm()` when `defined()` put a branch out of reach: separate the *decision* from the
  *I/O*, test the decision, keep the I/O thin.
- **`CsrfToken` takes a `SessionStore`**, not `$_SESSION`. ADR-0006 refused an interface for the
  reflection cache because no consumer needed one. Here one does.

## The finding

Four planted defects:

| planted | failures |
|---|---|
| predictable token (constant) | 5 |
| scope validation removed | 14 |
| `httponly` turned off | 2 |
| **`hash_equals()` → `===`** | **0 — passed** |

The last one is the single most important line in CSRF validation, and the whole suite passed
without it.

Not a probe that failed to apply this time — I checked, the marker was present and the edit landed.
It passed because **`hash_equals($a, $b)` and `$a === $b` return identical values for every input.**
They differ only in *how long they take*. No functional assertion can distinguish them, and a
PHP-level timing assertion is too noisy to be anything but flaky.

I nearly wrote this up as an accepted gap. Item 5.3 is why I didn't: there I documented an
untestable branch as acceptable, moved on, and the coverage gate dragged me back to actually solve
it. A gap written up as acceptable is a gap nobody closes.

So the mechanism is asserted directly — reflection over `validate()`'s own source, checking it
calls `hash_equals` and doesn't compare the tokens with `===`. Crude, and deterministic. It's
item **4.6**'s pattern restated: *when a property can't be observed in behaviour, assert the thing
that produces it.* There I counted driver lookups because the timing was under the noise floor;
here the timing difference is the entire point and still unobservable. Re-ran the probe: it fails
now.

Three occurrences of this shape in three items — 4.6 (sub-noise saving), 5.3 (unreachable branch),
6.2 (timing-only property) — is enough that it's a pattern rather than three coincidences.

*(It turned out to be four. See the last section: the same class had a second one, and I walked
straight past it while writing this paragraph.)*

## PHPStan pushed me to a decision I'd already made elsewhere

`SameSite` started as a validated `string`. PHPStan max rejected it: `session_set_cookie_params()`
is itself typed against a literal union.

The right fix wasn't a narrower docblock — it was an **enum**, which is precisely what ADR-0015
decided for `Sort` and `Operator`: a closed keyword set reaching a security-relevant output should
be a closed *type*, not a runtime check. Reaching the same conclusion from a static-analysis
complaint rather than from first principles was a good sign the earlier decision generalises.

It also deleted a test. The "illegal SameSite is refused" cases became compile-time
impossibilities, replaced by one assertion that the set is exactly the three the spec defines — so
widening it stays deliberate.

The one constraint the type system *can't* express spans two arguments: browsers **drop** a
`SameSite=None` cookie that isn't `Secure`. That stays a constructor check, so it surfaces at
wiring time instead of as "sessions don't work".

## Defaults, and which ones have no opt-out

- **`secure: true`**, with an explicit named opt-out for local `http://` development — *not*
  auto-detection from `$_SERVER['HTTPS']`, which would silently disable the flag on exactly the
  misconfigured-proxy deployment that needs it most. Same shape as `Hash`'s `bcryptFallback`.
- **`httponly` has no opt-out at all.** No legitimate caller needs the session id readable from
  JavaScript; offering the switch would be offering the vulnerability.
- **`SameSite=Lax`, not `Strict`.** `Strict` also withholds the cookie from ordinary inbound links,
  so a logged-in user following one from an email arrives logged out — the kind of breakage that
  gets a security control switched off entirely.
- **A token is issued once per scope and reused.** Regenerating per render invalidates the token in
  another open tab, and users retrying until it works is exactly how people learn to ignore this
  failure. `rotate()` is the explicit call for a privilege transition.
- **Scope names are validated** — a scope becomes a session-storage key, so a scope from user input
  would let a client grow the session record one key per request.

## The gap I wrote up as honest, and the gate that disagreed

I finished the item with this in the ADR, the class docblock and the test file:

> `start()` and `regenerate()` have no unit coverage and cannot.

Then CI failed the coverage floor at **89.59%**, `Session` sitting at 61.54%.

Two exits were available and both were familiar: lower the floor, or `@codeCoverageIgnore`. A third
had turned up while probing — `start()`'s cookie-params failure *is* reachable in CLI, because
`session_set_cookie_params()` returns `false` and the guard throws. One small test, +4 lines,
90.03%. Clears the gate by 0.03%.

That third option is the one worth naming, because it was the tempting one. It is a coverage fix
wearing a test's clothes: the number goes green, nothing anyone cares about gets asserted, and I
ship it as though the problem were solved.

Re-reading the gap instead of routing around it turned up something I had written down myself and
not followed through on. From the docblock:

> The order is not optional: `session_set_cookie_params()` has no effect once the session has
> started.

Get that order wrong and **everything still works**. Session created, values round-trip, all 1157
tests green — and the cookie went out with none of `httponly`, `secure`, `samesite`. Both orderings
produce a working session. Nothing about the outcome distinguishes them.

Which is §7 again, in a second place in the same class. `hash_equals` vs `===` is invisible because
the values match and only the timing differs; params-before-start is invisible because the session
works either way and only the sequence differs. I found that shape once this item, wrote an ADR
section about it, and still walked past the second instance — because the first announced itself as
a failed probe, and this one was sitting quietly in a comment I had written myself.

So: `SessionApi`, five methods, no behaviour. `NativeSessionApi` delegates and does nothing else.
`Session` keeps the guards, the ordering and the error mapping, and a fake records the call order.
Same justification as `CsrfToken`'s `SessionStore` two sections up — and it satisfies ADR-0006's
rule that an interface waits for a consumer to need one, because the consumer showed up.

Probes: swapping the order in `start()` fails 3 tests where it previously failed **none**. Weakening
`session_regenerate_id(true)` to `session_regenerate_id()` — which renames the session while leaving
the old identifier valid, the half of fixation that matters — fails 1.

The 5.3 journal said *"a gap written up as acceptable is a gap nobody closes."* I quoted that line
in this item's own ADR, in the section explaining why I would not accept the `hash_equals` gap, and
then accepted a different one four paragraphs later. The gate caught it. That is twice the coverage
floor has done work no reviewer asked it to.

## Bar

1175 tests / 2576 assertions green (up from 1109). `--group T-03` runs 66. PHPStan max clean,
deptrac 0/0, consistency lint OK.

## Next

**6.3** — T-03 session/CSRF integration against a real `php -S` process, which is where everything
this item couldn't reach gets exercised.
