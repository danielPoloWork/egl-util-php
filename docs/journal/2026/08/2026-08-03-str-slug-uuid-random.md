# 2026-08-03 — `Str::slug()` / `uuid()` / `random()`

Roadmap item **2.2**. First real algorithms in the repository — the exception hierarchy (2.1)
was structure; this is the first behavior PHPStan max and property tests had something to
earn their keep against.

## What landed

`D4np\Utils\Support\Str`, static-only (same no-instances idiom as `Version`):

- **`slug()`** — three transliteration tiers of decreasing fidelity, each its own pure private
  method: ICU's transliterator (`ext-intl`) when loaded, `iconv`'s `//TRANSLIT` when it is not,
  and — with no extension at all — dropping whatever falls outside printable ASCII. Never
  throws: a slug generator that errors on an emoji is worse than one that produces a shorter
  slug. **Idempotent** (`slug(slug($x)) === slug($x)`), asserted as the property test spec T-05
  names explicitly.
- **`uuid()`** — RFC 4122 v4 over 16 bytes of `random_bytes()`, with the version/variant nibbles
  fixed per spec.
- **`random()`** — `random_int()`-backed CSPRNG tokens, custom length and alphabet, rejecting a
  negative length or a sub-2-character alphabet.

## Testing the fallback tiers without an unplugged environment

The obvious problem: this environment has `ext-intl` loaded, so a test suite that only calls
the public `slug()` would never exercise the `iconv` or ascii-filter tiers — a fallback nobody
has run is a fallback nobody has verified. Rather than trying to fake `ext-intl` absent (not
practical without a second build), the three `via*` methods are invoked directly through
`ReflectionMethod`, each as an independently testable pure function. All three are proven with
real non-ASCII input in this run.

That surfaced two wrong assumptions in the tests, not the code:

- `iconv`'s `//TRANSLIT` approximation is **libc-dependent** — this glibc renders "café" as
  `caf'e`, not `cafe`. Not a bug; a real, valid approximation. The test now asserts the
  *contract* (no multi-byte UTF-8 survives) instead of one exact rendering that would be
  brittle across platforms.
- The ascii-filter tier drops "café" to `caf`, not `cafe`: "é" is two UTF-8 bytes, both outside
  `0x20-0x7E`, so the filter removes the pair entirely rather than leaving a mangled remainder.
  Correct behavior — no partial character ever survives — the test's expected value was simply
  wrong.

## Proved non-vacuous, three ways

Each a real defect planted, confirmed caught with the right assertion, reverted (this file was
still untracked, so `git checkout --` could not restore it — rewrote it from the known-good
content instead, then re-ran the full suite to confirm nothing else regressed):

1. Disabled the separator-trimming branch → 4 `StrSlug` tests failed, including idempotence.
2. Removed the UUID version/variant bit fixup → the format-regex test failed on the literal
   `4` and `[89ab]` positions.
3. Off-by-one on the alphabet index bound (`random_int(0, $alphabetLength)`, no `- 1`) → length
   assertion failed and PHP raised an undefined-array-key warning, both caught.

## PHPStan max, clean on the first pass

No findings this time — a contrast worth noting after item 2.1's four. Held down the
`transliterateToAscii()` orchestration to a two-line null-coalescing chain over three
independently-typed `?string` helpers specifically so PHPStan's flow analysis had no ambiguity
to complain about.

## No ADR

No new mechanic to decide: `Str` follows the no-instances static-utility idiom `Version`
already established at scaffold, and the fallback-tier design is an implementation choice
inside one function, not a MAJOR-surface or cross-cutting decision RFC-0001 left open. Filed
nothing new either — item 2.7 (the coverage floor) already covers what this item could not
verify (line coverage numerically), and there is nothing else out of scope here.

## Next

- **2.3 `File`** — flock-guarded I/O, atomic writes, `Fileinfo` MIME detection. First place
  concurrency and filesystem-failure semantics matter; `FileException` (already built in 2.1)
  gets its first real caller.
