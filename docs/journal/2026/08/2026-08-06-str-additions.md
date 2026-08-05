# 2026-08-06 — The first RFC-0002 item, and three failures that earned their keep

Roadmap item **9.1**, opening Milestone 9 — the first code shipped under RFC-0002. Route
`fast / low`; run at frontier because that is the session the maintainer had open
(mismatch recorded via `record_run.py`, the item 4.6 precedent in the other direction).

## Six methods, one philosophy

`collapseWhitespace()`, `nullIfBlank()`, `transcode()`, `padLeft()`/`padRight()`,
`shortClassName()`, `pascalCase()` — each a generalization of something the surveyed estate
does today with silent failure modes. The recurring inversion: **loss is opt-in, never
default** (`transcode()` throws where the legacy pipeline ran `//IGNORE` unconditionally;
padding refuses invalid UTF-8 rather than miscounting it).

## The three first-run failures, kept as documentation

Writing tests before trusting them paid three times in one run:

1. `str_pad('héllo', 7)` returns **7 bytes that render as 6 characters** — my own pin of
   the native defect had the arithmetic backwards. The test now asserts both counts
   side by side, bytes and code points.
2. `pascalCase()` is **not idempotent**, and cannot be while it normalizes case:
   an already-Pascal input has no separators, so it is one word and `strtolower` flattens
   it. The wrong move would have been forcing idempotence; the right one was asserting the
   non-idempotence as documented behavior, from both sides (provider case + explicit test).
3. An anonymous class's runtime name embeds a NUL byte and **the defining file path** —
   backslash-separated on Windows — so "tail after the last `\`" returns a different
   fragment per platform. `shortClassName()` now answers the literal `class@anonymous`,
   one deterministic value everywhere; found only because this suite ran on Windows.

## Decisions worth one line each

`transcode()` probes the encoding pair on the empty string first, so unknown-encoding and
unconvertible-data failures — which `iconv()` reports identically — stay distinguishable in
the message. `ext-iconv` is `suggest`ed with the ADR-0021/ADR-0022 refusal pattern; the
refusal branch is probe-verified, not permanently tested (5.2's standing precedent — this
environment cannot unload the extension). Padding follows PHP 8.3 `mb_str_pad()` semantics
so a future floor bump migrates without behavior change, counting code points via PCRE and
keeping mbstring out of the dependency graph. No new exception type: spec r3's exception
enumeration is the contract, and strict `transcode()` throws `UtilsException` with precise
messages until a consumer needs a finer catch.

## Gates

Full suite 1373 tests green (61 new; 7 pre-existing environment skips — no coverage driver
locally, so the 90% floor is CI's to certify). PHPStan max clean (after raising the local
128M memory limit — an environment lesson, not a code one). PHP-CS-Fixer clean. deptrac:
0 violations, 0 uncovered. `composer normalize` clean with the new `suggest` entry.
`consistency_lint` green. Leak-gate: zero estate tokens in the staged diff.

## Lesson

A test that fails on first run is doing its job — all three failures here were the tests
teaching me the platform's actual behavior, and each became an assertion the next reader
cannot un-know.
