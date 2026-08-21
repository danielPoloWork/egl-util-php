# 2026-08-21 — Four examples, and the three attempts it took to believe them

Roadmap item **13.2**, issue **#118**. Route `standard / medium`; session model Opus 5 — matched.

Six independent reviewers, twice over, had found the same thing: there was no path from landing on
this repository to a first working call. The fix is a README with an Install section, a naming map, a
nine-group surface table and four runnable examples. Almost none of this entry is about writing them.

## The examples were verified against the package, not the repository

Item 13.3 exists because `endpoint-kernel.md`'s flagship example does not compile against the API it
documents. Writing four more examples and *reading* them would have been the same bet that lost
there. So: a throwaway project outside the repository, `composer require egl/utils:^1.0`, and every
` ```php ` block extracted from `README.md` byte-for-byte and run as a standalone program against the
installed package.

Against the **installed package**, and that distinction turned out to matter more than expected.
`master` carries Milestone 14 merged but **unreleased** — `VERSION` is still `1.0.0`, `v1.0.0` is the
only tag — so `SystemClock`, `FrozenClock`, `Str::ulid()`, `uuidV7()`, `Hmac`, `RateLimiter`,
`PageRequest`/`Page` and `RetryPolicy` are all in this tree and **absent from what a consumer
installs**. Twenty files' worth. Verifying against the working tree would have produced a green run
certifying a surface nobody can `require` — item 10.11's *measure the candidate in its real home*,
transposed from benchmarks to documentation. The README now warns the reader about the same gap,
because a surface table read as a shopping list is exactly how someone reaches for `Str::ulid()` and
gets a fatal error.

The install itself is the evidence #121 wanted: three packages resolved — `egl/utils v1.0.0` plus the
interface-only `psr/container` and `psr/log` — and Packagist's own metadata points the tag at
`be7f34e`, the commit `v1.0.0` names.

## Two of my four examples did not run

Both of item 13.3's exact class, and both invisible to inspection:

- the **query example** selected from a `users` table it never created. A reader copying it gets
  `no such table: users`. Reading the code does not tell you that; the code is correct, its world is
  missing;
- the **`Result` example** handed an undefined `$requestBody` to `Json::decode()`.

Fixed by making each block a complete program. That is the standard the four now meet: not
"illustrative", but *runs*.

## The harness lied to me twice before it worked

This is the part worth carrying.

**Attempt one** spliced the `<?php` tag to inject an `ob_start()` preamble — `Session::start()`
refuses once headers are sent, which is itself a constraint the CSRF example documents. Splicing left
the file opening in HTML mode, so PHP **printed the entire block as text**, exited 0, and the harness
reported `PASS`. A vacuous green of precisely the shape of items 10.8 (a mutation gate running on
nothing) and 2.7. Nothing had executed.

Fixed two ways at once: the block is now written untouched and `require`d from a two-line wrapper, so
nothing is spliced; and the harness gained a **vacuity guard** — exit 0 with no output at all, or
with its own source in the output, is reported `VACUOUS`, not `PASS`.

**Attempt two:** the guard then fired falsely on all four blocks. PHP's own output carries no
trailing newline, and I was concatenating `stdout + stderr` — so the last real line fused onto the
first noise line of stderr, and the noise filter (`MIB search`, extension-load warnings on this box)
ate both. Twice, in fact: the first occurrence hid behind a startup `Warning:` line, I suppressed
startup errors, and the identical bug reappeared behind an SNMP line. Fixed by filtering the two
streams separately.

**Three attempts before the check could be believed** — and the sequence was only recoverable because
every failure was conservative. A guard that errs toward `VACUOUS` costs an investigation; one that
errs toward `PASS` costs a shipped defect. Worth choosing that direction on purpose.

Also, in passing, the harness rejected a shortcut I had reached for: seeding `$_POST` so the CSRF
block's submission phase would validate. It would have gone green while exercising the **419 branch**,
since an empty `$_POST` fails validation and `exit`s with status 0. The example was rewritten to run
end-to-end on its own instead, with a comment saying where `$_POST` goes in real code.

## One link deliberately not added

The new *More* section points at the source tree and at third-party picks, and **not** at
`docs/patterns/endpoint-kernel.md`. Item 13.3 is open; that page's flagship example does not compile.
Routing a consumer from a brand-new on-ramp straight into a known-broken example would have been the
on-ramp's worst possible first referral. The section says instead that the pattern pages explain
*why* rather than supplying copyable code, and that these four are the only examples in the
repository proven to run — which is true, and is an argument for the CI job filed below.

## Found on the way: master is red, and the link checker did it

`consistency_lint.py` fails on `master` right now — run 32476537522, `consistency / lint`, six
`link target does not exist` findings. PR #146 added that check and merged green, because the six
targets are `.eados-core/orchestrator/…` and `.eados-core/tools/…` files that **exist on the author's
machine and in no clone**: `.gitignore:82-85` excludes `/.eados-core/*` and re-includes only
`learning/runs/`. The lint passes locally for anyone holding the factory bundle and fails in CI for
everyone.

Not rot to repair — those files are *deliberately* uncommitted, so the checker needs them out of
scope the way external URLs already are. That is ADR-0069's decision to amend, not this item's, and
one PR carries one item. Handed to the maintainer with the diagnosis; this PR inherits the red check,
verified pre-existing on `master` rather than asserted (items #65 and #76 precedent).

The shape is familiar enough to name: **a checker's first green run is not evidence it will stay
green somewhere else.** #146 proved its check could fail, which is the standing method — but proved it
on a machine holding files the check depends on and CI never sees.

## Filed, not done

A CI job that executes the README's `php` blocks, so this verification is repeated rather than
remembered. Nothing in CI runs a doc example today, and item 13.3 is the entire argument for why that
matters. Also noted: spec §1 requires the mixed-vendor naming be stated in **`composer.json` and**
the README. The README half now exists; the `composer.json` half does not, and editing a published
package's `description` is a Packagist-visible act rather than a docs edit, so it was left for the
maintainer.
