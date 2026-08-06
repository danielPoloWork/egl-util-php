# 2026-08-06 — The corpus that protected the older builder

Roadmap item **10.5**, spec §7's **T-13**. Route `frontier-reasoning / extra`; session model
Opus 5, so a route mismatch, flagged before starting and accepted.

## The first thing this suite found, it found in its own predecessor

T-13's job is to re-run ADR-0017's payload corpus through the gateway. Before writing a line of
it, I went to reuse the two corpora that already exist — and found there are *three*:
`InjectionTest` holds 29 value payloads, `QueryBuilderTest` holds 19 hostile identifiers, and
`MutationBuilderTest`, which **I wrote one PR earlier**, holds its own list of **ten**.

So the newer of the two SQL builders was being tested against the weaker corpus. Both suites were
green. Nothing was red, and nothing would have been red until a payload the short list omits —
`UNION`, a subquery, a block comment, a unicode lookalike — turned out to matter.

This is the argument ADR-0044 makes about the allowlist ("a copied rule is one edit from two
rules, and the weaker one decides") reproduced one layer up, in the tests that check the
allowlist, by the same author, in the next PR. Writing the rule down did not stop me from
breaking it somewhere the rule did not literally apply.

Both corpora now live in `Fixture\InjectionPayloads`, shared by T-02, T-13 and both builder
suites. Unification alone gave the write builder **21 identifier cases instead of 10**, and gave
the read builder two it did not have (`count(*)`, `id)`), which had arrived with the write
builder. One list, so a payload added for one caller protects every caller.

## What T-13 adds that the existing suites do not

T-02 proved the boundary property for the paths that existed at item 4.4. Item 10.4's suite
asserts what the *builder rendered* — a claim about a string. Between them sits everything a
gateway adds: a projection, an assembled `WHERE`, a `SET` list, and two parameter groups in
sequence.

Three assertions in this suite could not have been written in either of the others:

- **The identifier leg asserts an empty log.** A hostile column name must be refused *before
  anything is prepared*. A gateway that built the statement, handed it to the driver and then
  noticed would satisfy every `expectException` in the file while having already run the
  injection — and no round-trip assertion can tell those apart.
- **`SET` binds before `WHERE`.** Swap the two groups and the statement still runs, still affects
  a plausible number of rows, and writes the *criterion* into the column. Every syntax-level
  assertion in this file passes on that bug. It is caught by using two distinct payloads and
  reading the row back.
- **A tautology in a `DELETE` criterion deletes nothing.** The destructive statement of the
  mechanism, and the one the surveyed estate was actually exposed to.

## Verification

578 tests under `--group T-13`; 2400 in the suite overall. Seven defects planted one at a time,
seven caught: values interpolated on three separate paths, the read builder's `WHERE` interpolated
and reached through the gateway, the allowlist bypassed, the two parameter groups swapped, and one
aimed at the suite itself — emptying the log before asserting, to confirm the vacuity guard fires
rather than passing on nothing.

The restore step worked this time. Item 10.4's campaign silently restored nothing because the new
files were untracked; the fix, applied here from the start, is to `git add` before planting. Same
lesson, one PR later, and cheap to apply because it was written down.

## The benchmark gate failed on a PR with no production code in it

CI came back red on `benchmark / reproducible perf`:
`benchSequenceNext 75.503 → 105.779 µs (+40.10%)` and
`benchVerifyArgon2id 113465 → 129072 µs (+13.75%)`, both against a 10% budget.

This PR's diff touches **no file under `src/main`** — checked with
`git diff origin/master...HEAD --name-only | grep src/main`, which returns nothing, rather than
assumed from "it's a test PR". Neither `FileSequence` nor `Hash` has a line in it. The same commit
then passed on re-run.

So it is noise — but noise worth a number, because ADR-0030 already solved the *stored baseline*
version of this problem and this failure happened **inside** its same-runner A/B design. The two
subjects that fired are the two whose cost is not CPU work: filesystem locking and memory-hard
hashing, where a shared runner's variance lands in the same order as the budget. Filed as item
**10.9** with both measurements and the run id, because the dangerous outcome is not a red build —
it is a team that learns to re-run until green.

## No ADR, deliberately

The decision T-13 rests on — that the PDO boundary is a sufficient place to prove binding, because
real prepares are pinned — is ADR-0017's, made at item 4.4. This item applies it to new paths and
adds no decision of its own. Spec r12 records what the suite actually covers, since the one-line
description in the r3 addendum did not mention the identifier leg.

## Lesson

The rule you just wrote down applies to the code you write next, including the tests. ADR-0044
argued against a duplicated allowlist and was merged the same day I duplicated the corpus that
tests one — the second copy is always somewhere the rule did not literally name.
