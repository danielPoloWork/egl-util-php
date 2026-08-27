# ADR-0079: Three minor edges, one probe, and a defence that would have been unreachable

- **Status:** Accepted
- **Date:** 2026-08-26
- **Deciders:** project architect (agent), maintainer
- **Related:** issue [#102](https://github.com/danielPoloWork/egl-util-php/issues/102) ·
  [ADR-0022](0022-argon2id-by-default-with-a-fallback-decided-at-construction.md) (the fallback
  policy this refines, and the "no inert defences" precedent) ·
  [ADR-0037](0037-disable-phps-escape-character-and-keep-the-formula-guard-opt-in.md) (the opt-in
  formula guard) ·
  [ADR-0049](0049-state-the-transport-policy-explicitly-and-bound-the-whole-request.md) /
  [ADR-0052](0052-a-followed-redirect-reports-the-last-hop-not-the-first.md) (the redirect policy) ·
  [ADR-0059](0059-freeze-the-api-at-1-0-0-with-internal-symbols-outside-the-frozen-surface.md)
  (why none of this may change a signature) · spec **FR-11**, **FR-28**, **FR-37**, revision **r27**

## Context

Issue #102 is the Senior Security Engineer's **minor** findings from the 2026-08-09 release review
board — three documented trade-offs, none exploitable as shipped, all constrained by the same rule:
*the 1.0 surface is frozen, so every fix must be additive.*

1. **Per-hop scheme re-check on opted-in redirects.** `HttpClient::guardScheme()` validates the URL
   the caller passed against an http/https allowlist. With `followRedirects: true` the hops belong to
   PHP's stream wrapper, which exposes no per-hop callback. Does the allowlist still hold mid-chain?
2. **bcrypt-downgrade signal in `Security\Hash`.** A `Hash` built with no logger degrades to bcrypt
   on an Argon2-less build with `algorithm()` as the only signal. The issue suggests making the
   silent path harder to reach.
3. **CSV formula-guard visibility.** `$guardFormulas = false` is ADR-0037's deliberate call, but the
   issue asks that its visibility be raised so an export call site cannot plausibly claim ignorance.

## Decision

**Item 1 is answered by measurement and pinned by tests rather than guarded; items 2 and 3 get one
additive named constructor and a documentation pass. No signature changes.**

### 1. The per-hop scheme check is not added, because it could never fire

The reflex is to add the check. Before writing it, PHP's actual redirect behaviour was probed with a
throwaway origin emitting arbitrary `Location` headers:

| `Location` emitted | what PHP's http wrapper did |
|---|---|
| `http://other-origin/…` | **followed** — a second origin was reached and logged the request |
| `//other-origin/…` (protocol-relative) | *not* treated as absolute; requested as a same-host path |
| `file:///…/index.php` | requested as a **same-host path** over http; no file was opened |
| `php://filter/read=convert.base64-encode/resource=…` | same — a path, not a wrapper |
| `data://text/plain,…` | same — a path, not a wrapper |
| `ftp://127.0.0.1/x` | **refused**; the call returned `false` and nothing was fetched |

**PHP's http wrapper never leaves http/https on a redirect.** It either refuses the hop or degrades
the `Location` to a path on the current host. A per-hop scheme re-check would therefore be
**unreachable code**, and ADR-0022 with item 12.1 already set this project's precedent: *defensive
code a probe proves inert is removed.* The same reasoning applies to not adding it in the first
place — with more force, since unreachable code cannot be tested and would read as though the
surrounding code needed it.

**What ships instead is the measurement, as tests.** The claim is about *PHP's* behaviour, not ours,
so the risk is not that our code regresses — it is that a future PHP changes underneath a decision
made on today's behaviour, silently. `HttpClientLiveTest::testAnOffAllowlistRedirectNeverLeavesHttp`
drives all six shapes above through a live origin and asserts the payload never arrives, with a
companion test proving those payloads *are* readable by the process so their absence means something.

**The real residual is a change of origin, and it is now stated on the class.** An absolute
`http(s)://` `Location` to any other host or port *is* followed, bounded only by `maxRedirects`. The
allowlist is about schemes and never claimed otherwise, so enabling redirects towards anything the
caller does not control is accepting an SSRF-shaped pivot. The mitigation is the default — redirects
are off — and that trade-off is now written where someone turning the flag on will read it, rather
than left implicit.

### 2. `Hash::strict()`, and an honest statement of what it does not fix

The issue's own suggestion — *a named constructor for the logger-less form* — **cannot be
implemented as written.** `new Hash()` is frozen and permissive; no addition can make an existing
constructor harder to call. Pretending otherwise would have been the wrong deliverable.

What is available, and is taken: `Hash::strict()`, returning `new self(bcryptFallback: false)`.
Argon2id or refuse to construct. The behaviour already existed as a boolean argument a caller had to
know about; this makes the fail-closed posture a discoverable entry point sitting in the class's API
listing beside `make()` and `verify()`, which is the difference between a policy being *available*
and being *reachable*.

The class docblock now names all three constructions and marks the third as the hazard it is —
`new Hash()`, which on an Argon2-less build hashes with bcrypt and says so nowhere. **The residual
is recorded rather than closed:** inverting the default is a breaking change and belongs to the next
MAJOR. Until then the guidance is explicit — call `strict()`, or assert `algorithm()` in a health
check; a deployment doing neither has chosen bcrypt without deciding to.

### 3. The CSV guard is documented where the decision is made

No code change: ADR-0037's default stands, and its reasoning (the guard *alters exported data*, and
only the caller knows the file's destination) has not weakened. What was missing was reach. The class
docblock gains a section naming the attack concretely — `=WEBSERVICE(...)` in a user-supplied field
becomes a request from the machine of whoever opens the export — and the README gains a worked
example passing `guardFormulas: true`, in a repository whose README had no CSV example at all.

The framing matters more than the words: **the safe choice is the one you have to type.** Saying so
plainly is what closes the "plausible ignorance" gap the issue names, and it is the honest way to
keep an opt-in default.

## Alternatives Considered

- **Add the per-hop scheme check anyway, as belt and braces.** Rejected in §1: measured unreachable,
  and ADR-0022's precedent is explicit about inert defences. A check that cannot fire cannot be
  tested, and its presence would misinform the next reader about what the wrapper does.
- **Stop delegating redirects to the wrapper and follow them manually**, validating each hop. This is
  the only way to get a genuine per-hop check, and it would also permit a host allowlist. Rejected
  for this issue: it replaces a well-understood wrapper behaviour with our own redirect state machine
  — method rewriting on 303, body replay, cookie and `Authorization` stripping across origins — which
  is a substantial new attack surface to fix findings the issue itself classifies as *minor*, on a
  frozen surface. Recorded here as the shape a future host-allowlist feature would take.
- **An opt-in host allowlist on `HttpClient`.** Additive and genuinely useful against the residual in
  §1. Rejected as out of scope: it is a new feature, not a hardening of an existing edge, and it
  deserves its own item rather than arriving inside a minor-findings sweep.
- **Invert `bcryptFallback` to `false` by default.** The strongest fix for item 2 and the one a
  security reviewer wants. Rejected: it is a breaking change under ADR-0059 — every
  `new Hash()` on an Argon2-less build would begin throwing. Named as a 2.0 candidate instead.
- **`trigger_error()` on a logger-less downgrade.** Rejected on two grounds: a library escalating to
  a PHP warning takes a decision that belongs to the caller (ADR-0029's stance, why `Mail` does not
  reach `Errors`), and this repository runs PHPUnit with `failOnWarning="true"`, so it would convert
  a deployment concern into a test failure for every consumer.
- **Turn the CSV guard on by default.** Rejected: it is ADR-0037's decision, it silently rewrites
  caller data, and reversing it is breaking. The gap was visibility, and visibility is what changed.
- **Fill in `docs/security/threat-model.md` with the redirect residual.** Considered, and
  deliberately not done: that document is a scaffold owned by the audit phase (`/eados security`),
  and half-filling one STRIDE row from a minor-findings PR would make it look surveyed when it has
  not been. The residual is recorded on the class and here.

## Consequences

- **One added public method** (`Hash::strict()`), additive under ADR-0059 — `^1.0` still resolves and
  the BC checker sees no break. Everything else is documentation and tests.
- **`HttpClient` is unchanged in behaviour.** What changed is that its redirect trade-off is now
  stated on the class, with the residual named as cross-origin rather than cross-scheme.
- **T-07 grows seven tests** (six payload shapes plus the readability control), each of which fails
  loudly if a future PHP starts honouring an off-allowlist `Location`. This is the deliverable for
  item 1: the decision not to guard is only sound while the measurement holds, so the measurement
  runs on every PR.
- **Known residual, item 1:** cross-origin redirect following, bounded by `maxRedirects`, mitigated
  by the off-by-default flag. A host allowlist is the fix and is deliberately not in this PR.
- **Known residual, item 2:** `new Hash()` still degrades quietly with no logger. Not closable
  additively; a 2.0 candidate.
- **Known residual, item 3:** `guardFormulas` is still off by default, by ADR-0037's standing
  decision. No doc change makes a call site safe — it makes an unsafe one indefensible, which is what
  the issue asked for.
- **No spec requirement changed.** FR-11, FR-28 and FR-37 were all implementable as written; r27
  records what was measured about FR-37's redirect clause and the two documentation positions.

## References

- Issue [#102](https://github.com/danielPoloWork/egl-util-php/issues/102) — the 2026-08-09 release
  review board, Senior Security Engineer seat (minor findings).
- Spec **r27** — the recorded measurement and the two visibility positions.
- `HttpClientLiveTest::testAnOffAllowlistRedirectNeverLeavesHttp` and
  `::testTheOffAllowlistPayloadsAreReadableSoTheirAbsenceMeansSomething` — the pinned probe.
- OWASP: *CSV Injection*; *Server-Side Request Forgery Prevention Cheat Sheet* (the residual in §1).
