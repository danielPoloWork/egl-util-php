# 2026-08-13 — The deferral that was waiting for itself

Issue **#91**, reopened today by the maintainer; roadmap item **14.6** (filed and closed in this
PR, the item 7.5 precedent); deliverable **ADR-0061**. Route `frontier-reasoning / extra`
(security + adr, both protected floors); session model Fable 5 — matched, and for once the match
was the user's own doing before the item was even named.

## The reopening was a decision, and it was put as one

"Procedi con issue #91" arrived four days after RFC-0003 — approved by the same maintainer —
deferred exactly that issue, with the deferral recorded in four places. Proceeding silently would
have meant either quietly reversing a maintainer decision on an inferred intention, or quietly
substituting different work for the asked-for issue. Neither is this project's shape. The
conflict went back as a question with the two facts that had changed since the deferral:

1. **The revisit condition was unreachable.** *"Reconsider when a storage seam exists"* — but no
   backlog item creates a storage seam, and issue #90's third-party-picks page routes caching to
   `symfony/cache`, so an in-library storage abstraction was never going to arrive by another
   road. The seam the deferral waited for is #91's own deliverable. Deferred on that condition,
   the issue was deferred forever — a wait state with no waker.
2. **The objection was answerable by design.** The deferral never said "out of scope"; it said "a
   single-node limiter that presents as protection is a lie." That is a requirement wearing a
   refusal's clothes: make the enforcement scope explicit, put the honesty statement where a
   consumer cannot miss it, and the objection is satisfied rather than overridden.

The maintainer chose design-first. The deferral is annotated in RFC-0003 (Status note + inline
marker, the ADR-0059 style), not erased — its reasoning is exactly why the design carries an
honesty statement at all.

## The design's load-bearing choice is the seam, not the algorithm

The algorithm question (token bucket) mostly answers itself once the field is laid out — fixed
window *is* the estate's "resettable windows" defect with better ergonomics, a sliding log hands
the throttled attacker a lever on the defender's memory, a sliding counter is an estimate sold as
a limit, GCRA is the same control with less legible arithmetic. The decision that needed frontier
effort was the **store interface**, and it produced the finding worth carrying:

**A `get()`/`set()` store cannot be composed race-free by any caller.** Two nodes read one
remaining token, both approve, both write zero — the limiter exceeds its own limit at exactly the
concurrency a brute-force attack produces. The race lives in the *caller*, so no library code can
close it, and every consumer-written backend inherits it as the default outcome. The seam is
therefore **compare-and-swap** (`read` → `writeIfVersion`), which makes atomicity the interface's
stated contract instead of each implementor's luck. Every serious backend has a native CAS to
build it on; a locked file makes the version check trivially true, which is how the shipped file
store gets to reuse `File::update()` — ADR-0038's locked read-modify-write, already proven
multi-process by T-14. The library's own prior work supplied the local case of the primitive the
multi-node case needs.

Second finding, smaller but the kind that becomes a CVE when skipped: the library's own file
store means **user input must never become a filename**. Keys are hashed at the boundary
(length-prefixed, domain-separated, fixed alphabet), which kills path traversal, store-syntax
injection, unbounded per-key storage and content-shaped timing in one construction — the issue's
"constant-time-safe key handling" satisfied once, centrally, instead of audited across N stores
this library will never see.

Third: **clock skew degrades toward strictness.** Elapsed time clamps at zero, so a node behind
the writer's clock refills nothing — it can deny early, never mint tokens. For a security control
that is the correct direction to fail, and it is a decided rule with a test named for it (14.7),
not a hope.

## The lint and the vocabulary disagree, and the row that found it

The catalogue's status vocabulary defines **Planned** — *"decided in an ADR, not yet landed"* —
which is precisely ADR-0061's state. Adding the row failed `consistency_lint.py`: its patterns
check requires a real source location for every table row. The vocabulary says Planned rows may
exist; the lint says they may not. Nothing hit this before because no pattern has been in the
decided-but-unbuilt state since the lint arrived — every prior adoption landed ADR and code in
one PR.

Bending the gate to admit my own row is the move this repository never makes (item 11.2's rule:
when a guard false-positives, reword your code, not the guard). Rate Limiting therefore sits in
the *Candidate patterns* section as "decided, awaiting code — Planned-in-substance," with the
contradiction recorded in place and the resolution left where it belongs: either the lint learns
the Planned case or the vocabulary drops it, and that is the maintainer's call, not something to
settle as a side effect of an unrelated PR.

## Left for 14.7, deliberately

Exact signatures (FR-50, spec r17 at landing), the CAS retry bound, the suite number, whether a
benchmark subject is even warranted (ADR-0040 — the guarded path costs ~100 ms of Argon2id by
design; the limiter's job is to be invisible next to it), and the mechanism assertions ADR-0027
demands for everything behaviour cannot see: the hash-then-lookup key path, the zero clamp, the
bounded loop. Implementation waits on 14.1 — the clock is the one dependency the design consumes
that does not exist yet, and building around it would be the three-private-clocks mistake
RFC-0003 exists to prevent.
