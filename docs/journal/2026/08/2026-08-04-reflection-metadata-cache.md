# 2026-08-04 — The shared reflection cache: designing for consumers that do not exist yet

Roadmap item **2.5**, the last Support-layer item with new design surface, and the second
`sets-pattern` of the milestone. Route resolved to `frontier-reasoning / high`;
`route_advice.py --check` reported the session (`opus-5`, standard tier) below it — surfaced to
the maintainer, who holds model authority (ADR-0017), and work proceeded on their standing
decision.

## The actual difficulty

Imported ADR-001 commits to **one** metadata cache shared by the DTO hydrator and the Container.
Neither consumer exists yet — the hydrator is Milestone 3, the Container Milestone 6. So every
decision risked one of two failures: under-building, and blocking a consumer later; or
speculating, and shipping fields and abstractions nobody ever uses.

The discipline that resolved it: **every metadata field must cite a stated requirement**.
[ADR-0006](../../../adr/0006-shared-reflection-metadata-cache.md) carries the table — `name` from
FR-01, `allowsNull`/`hasDefault`/`default` from RFC-0001 R-4's "neither nullable nor defaulted"
rule, `declaredType` from imported ADR-001's *fail loudly* requirement, `isInstantiable` from
FR-04. A field that cannot name its requirement does not belong.

`isVariadic` is the one that needed a different justification, and it is worth recording: it is
not a feature request from anywhere. Without it the metadata would silently describe `...$args`
as an ordinary parameter — and describing the class *truthfully* is the cache's entire job, so
the field earns its place on accuracy grounds rather than on demand.

## The interface question, and why ADR-0004 does not transfer

ADR-0004 (item 2.1) rooted the exception hierarchy on an interface, and the argument was
one-way-door avoidance: an exception later forced to extend a different base could never rejoin
the family, and every consumer `catch` would break.

The reflex would be to mirror that here. **It does not transfer, and noticing why mattered.**
Extracting an interface from a concrete collaborator is a *non-breaking, additive* refactor —
existing consumers keep compiling unchanged. There is no door closing, so there is nothing to
buy insurance against, and no consumer exists yet to say what the interface should declare. So:
concrete class, no interface, reasoning recorded rather than left as an inconsistency someone
would otherwise have to re-derive.

The same reasoning deferred a `shared()` singleton accessor. The hydrator's entry point is
expected to be static (`DataTransferObject::fromArray()`), which cannot receive an injected
instance — a real constraint, but one whose reset semantics and testability I would be guessing
at today. Milestone 3 will have it in hand.

## Three PHP facts I had wrong, all caught by tests

Each assumed, then falsified by a red test, then verified directly against reflection:

1. **`mixed` is a named builtin type, not the absence of one.** The fixture called
   `UntypedService` declared `mixed $anything` — so it was testing the `mixed` case while
   claiming to test the untyped one. Split into two fixtures: a genuinely untyped parameter
   (`__construct($anything)`, `getType()` returns `null`) and a `mixed`-typed one. Both are
   un-autowirable, for reasons a diagnostic message should not conflate.
2. **PHP canonicalises a union's arms.** The fixture declares `int|string`; reflection reports
   `string|int`. The test now asserts membership rather than an exact string — otherwise it
   would be a test of PHP's ordering rule, not of the metadata.
3. **`class_exists()` returns false for interfaces and traits.** Verified across enums, abstract
   classes, interfaces and traits before writing the existence guard, which is why it checks all
   three functions. Narrowing it to `class_exists()` alone makes the interface tests error —
   confirmed by probe.

## PHPStan max, and a genuine tool disagreement

PHPStan first rejected `catch (ReflectionException)` as **dead**: `class-string` asserts the
name resolves, so it proved the catch unreachable. At runtime it is reachable — nothing stops a
caller passing a name read from configuration. Replaced with an explicit `class_exists() ||
interface_exists() || trait_exists()` precondition, which keeps both truths intact and produces
a better message than reflection's own.

Then a real conflict between the two quality tools, worth recording because suppressing either
would have been the easy wrong answer:

- **PHP-CS-Fixer** wanted to delete `@param mixed $anything` as superfluous.
- **PHPStan at max** requires it — on a parameter with no native type, that annotation is the
  only thing supplying a type.

Settled in the formatter config (`no_superfluous_phpdoc_tags` → `allow_mixed: true`) with the
reasoning inline, rather than by silencing whichever tool complained second.

## Proved non-vacuous

- **Removed the memoisation** (reflect on every call) → the identity assertion and the
  cache-count assertions both failed. Identity is the discriminator on purpose: a non-memoising
  implementation returns an equal-but-*distinct* object, so `===` fails where `==` would pass.
- **Narrowed the existence guard** to `class_exists()` alone → both interface tests errored.

138 tests, 284 assertions, 3 skipped (the Windows-only `File` tests; they run on CI). PHPStan max
clean, PHP-CS-Fixer clean.

## Next

**Milestone 2 is one item from complete.** Only **2.6** (T-05 property tests) and **2.7** (the
ungated coverage floor) remain — and as flagged on PR #15, all three property tests 2.6 names
already exist, landed with the items they belong to. Whether 2.6 is ticked as done or rewritten
as a pointer is the maintainer's call, not the agent's.
