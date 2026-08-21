# ADR-0028: Container exceptions live in the Container group, and `get()` carries a type PSR-11 does not

- **Status:** Accepted
- **Date:** 2026-08-05
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 6.4 · spec FR-04, FR-05, NFR-02 ·
  imported [ADR-001](../../.specs/d4np_php_adr_001_di_container.md) (why a hand-written container
  at all) · [ADR-0004](0004-root-the-exception-hierarchy-on-an-interface.md) (the exception root) ·
  [ADR-0006](0006-shared-reflection-metadata-cache.md) (the one metadata cache, and the union-type
  refusal this depends on) · [ADR-0012](0012-enforce-the-layering-rule-by-directory-over-src-main.md) (the layering this
  obeys) · [ADR-0018](0018-querybuilder-benchmark-scope-and-the-measured-build-time-gap.md) (the
  benchmark caveat carried forward)

## Context

Imported ADR-001 already decided *whether* to ship a container and what its non-goals are. Three
questions it does not answer came up while building it, and each has a wrong answer that would have
looked reasonable.

## Decision

### 1. The container's exceptions live in `Container/`, not in `Support/` with every other exception

PSR-11 requires them to implement `ContainerExceptionInterface` and `NotFoundExceptionInterface`.
Every other exception in this library lives in `Support`, and `deptrac.yaml` declares
`Support: ~` — depending on **nothing**. That empty list is the load-bearing half of RFC-0001's
layering rule; ADR-0010 had to design around it once already.

So either `Support` gains a dependency on PSR-11 to host one class, or the class moves to the layer
already allowed to see PSR-11. It moves. A layering rule that bends the first time something is
inconvenient is not a rule, and `Container` is where these exceptions are thrown anyway.

**Verified, not assumed:** removing the `Psr` grant from the `Container` layer produces five deptrac
violations naming each class. `Support`, having no grants at all, would have failed the same way.

The hierarchy is unaffected in the way that matters: `ContainerException extends UtilsException`, so
ADR-0004's contract holds — a consumer catching `UtilsThrowable` catches these, and one catching
`ContainerExceptionInterface` gets the PSR behaviour. Neither audience is told about the other.

`CircularDependencyException` is a third class rather than a message on the second, because the
container **acts** on the distinction: an ordinary resolution failure may fall back to a parameter's
default, and a cycle must not. Deciding that by matching on message text would be a decision that
breaks the day someone rewords a string.

### 2. `get()` declares a conditional return type, which PSR-11 does not

`ContainerInterface::get()` is untyped in psr/container 2.0.2 — verified, the interface declares
`get(string $id)` with no return type at all. Implemented plainly as `mixed`, every consumer under
PHPStan at max must narrow at every call site. This library holds *itself* to max, so shipping that
would be exporting the noise.

```php
@return ($id is class-string<T> ? T : mixed)
```

A class name returns that class; any other identifier stays `mixed`, because a string key genuinely
says nothing about its value. That asymmetry is honest rather than incidental, and it shows up in
the tests: `get(Greeter::class)->greet()` type-checks, `get('greeting.suffix')` gets narrowed.

**This needs no dedicated test.** Removing the annotation fails **11** existing PHPStan checks,
because tests like `testDependenciesAreResolvedRecursively()` dereference `get()`'s result. The
property is already gated by CI's static-analysis step, non-vacuously — probed to confirm rather
than assumed.

### 3. Autowired instances are shared by default; `factory()` is the opt-out

NFR-02's warm budget only means something if a second `get()` is a lookup rather than a rebuild, and
a container that silently rebuilt a graph on every call would turn one shared connection into
hundreds. `factory()` states the other intent explicitly.

### 4. `has()` answers for behaviour, not for the registration table

It returns `true` for any instantiable class, because those are exactly the identifiers `get()` will
return a value for, and `false` for an unbound interface or abstract class, because those are what
it refuses. Reporting `false` for a class the container would happily autowire would make `has()` a
statement about bookkeeping rather than about what the container does.

## The NFR-02 benchmark, and a measurement error worth recording

Measured on an idle Windows developer machine, OPcache off:

| subject | measured | NFR-02 |
|---|---|---|
| warm singleton resolve (4-class graph) | **0.173 µs** | ≤ 2 µs |
| warm instance resolve (plain value) | 0.169 µs | — (the floor) |
| first autowired resolve (4-class graph) | **18.593 µs** | ≤ 30 µs |
| first autowired resolve (single class) | 2.646 µs | — (per-class floor) |

Both halves pass. The first version of the benchmark said otherwise, and the reason is instructive.

phpbench runs `beforeMethods` **once per iteration** and then loops the subject over every
revolution — read from `Executor/Benchmark/template/remote.template`, not assumed. A cold subject
therefore has to build its own container *inside* the subject, or 999 of 1000 revolutions measure
the warm path.

Doing that correctly still reported **93 µs** against a 30 µs budget. The tell was that the number
moved with the revolution count: **93 µs at 200 revs, 26 µs at 2000, for identical work.** The
subject's first revolution was paying Composer's autoloading — four `stat`s and four `include`s —
and phpbench divides the total by the revolution count, smearing a one-time cost across every
measurement. Warming the *class loader* (while leaving the *metadata cache* cold, which is what
NFR-02's "first" actually means) gives **18.4 / 18.3 / 18.1 µs at 200 / 1000 / 4000 revs** — a
number that no longer moves with the harness is a number about the subject.

This is the second time a benchmark of mine has been wrong in the same direction; ADR-0020 records
the first, on NFR-03. Both were caught by asking what the workload actually contained rather than by
trusting the total.

Per ADR-0018, these ceilings are **not** asserted as a hard CI gate: they are tied to spec NFR-06's
reference machine, and a slower runner would fail for reasons unrelated to a regression. The
numbers above are from a developer machine, not that reference machine. Nightly baseline tracking is
roadmap item **7.1**.

## Alternatives Considered

- **Container exceptions in `Support/` with the rest** — rejected in §1: it requires the bottom
  layer to depend on PSR-11, which deptrac correctly refuses.
- **One `ContainerException` with a "circular" message** — rejected in §1: the container branches on
  the distinction, and branching on message text breaks on a reword.
- **Plain `mixed` from `get()`** — rejected in §2 as exporting analysis noise to every consumer.
- **`ServiceProvider` with a `boot()` phase** — rejected: two-phase lifecycles exist to order side
  effects across providers, which is application composition, not a utility library's job. The base
  class stays at one method, and a test asserts it has not grown a second.
- **Rebuilding autowired instances per `get()`** — rejected in §3.
- **`has()` true only for registered entries** — rejected in §4.
- **Resolving cycles** (lazy proxies, deferred injection) — rejected by imported ADR-001, which
  answers such requests with "adopt a mature container instead".

## Consequences

- 37 tests across `Container` and `ServiceProvider`. **Verified non-vacuous**: disabling the cycle
  guard exhausts memory and kills the process (which is what an unguarded cycle does); disabling
  instance sharing fails 3; removing the `get()` annotation fails 11 PHPStan checks; removing the
  deptrac `Psr` grant produces 5 violations.
- Two bugs were found by tests failing rather than by review, both from assumptions worth naming:
  `class_exists()` is **false for an interface**, so an unbound interface was reported as "no such
  class" instead of "bind it" — the exact case `ReflectionCache`'s three-way check exists for; and
  `mixed` is a **named built-in type**, not an absent one, so the original "untyped parameter"
  fixture silently exercised the built-in branch. A promoted readonly property cannot be untyped at
  all, which is why that fixture is the only one not using promotion.
- The container accepts and exposes a `ReflectionCache`, honouring imported ADR-001's commitment to
  **one** metadata cache shared with the DTO hydrator.

## References

- Imported ADR-001 — the scope limits this implements
- ADR-0006 — the shared cache, and the preserved `declaredType` that makes the union-type refusal
  able to name what it saw
- ADR-0012 — the deptrac layering that decided §1
- ADR-0020 — the previous benchmark-workload error, same shape as the one above
- psr/container **2.0.2**, whose `ContainerInterface::get()` carries no return type — verified
