# 2026-08-03 — `File`: atomic writes, and a test that was lying

Roadmap item **2.3**, flagged `severity:medium` for "concurrency/atomicity semantics" — which
turned out to be the right flag for the right reason. Route resolved to `standard / medium` and
the session model matched (first ROUTE-OK of the milestone).

## The finding that shaped the design

RFC-0001 asks for `write()`/`read()` that are *"flock-guarded, atomic write via temp+rename"*.
Implementing that literally is impossible, and the reason is silent and platform-specific.
Measured directly, PHP 8.3.1 on Windows 11:

| Scenario | Result |
|---|---|
| `rename()` over an existing target, no handles open | **OK** |
| `rename()` over an existing target while a handle holds it open | **FAILS** |

POSIX unlinks the old directory entry and lets existing handles keep reading the old inode.
Windows refuses the replacement outright. So the natural implementation — lock the target, write
the temp file, rename, release — **breaks every atomic write on Windows**, because the lock
handle is precisely what makes the rename fail.

And moving the release earlier does not rescue it: the `rename()` *is* the mutation, so a
critical section that ends before it serialises nothing. Two writers can each prepare under the
lock, release, and rename in either order — identical to having no lock.

Hence **ADR-0005**: the lock goes on a `<path>.lock` sidecar, which is never the object of a
rename, so it can be held across the whole prepare-and-replace sequence. Cost, stated rather than
hidden: a `.lock` file appears beside every written file and stays, because unlinking it is
itself racy.

A second silent trap, caught while reading the manual rather than after a bug report:
**`tempnam()` falls back to the system temp directory** when it cannot use the one it was given.
That puts the temp file on another filesystem, at which point `rename()` degrades to
copy-then-delete and atomicity evaporates with no error anywhere. The returned path is now
verified to be in the intended directory, and the fallback is treated as a failure.

## The test that was lying

The first atomicity test was called `testConcurrentReadsNeverObserveAPartialFile`. It wrote,
then read back, then asserted the content was one complete version or the other — forty times.

**It passed against a deliberately planted naive `file_put_contents($path, …)` implementation.**

Of course it did: a single-process sequential test contains no concurrency. `File::write()`
returns, *then* the read happens; a truncating write has finished truncating by then too. The
test asserted that writing works, under a name claiming it asserted atomicity. Had it shipped, it
would have been a green gate over an unverified guarantee — and the name would have kept anyone
from noticing.

Replaced with two tests that actually discriminate, after probing the platform for an observation
that does:

1. **`testWriteReplacesTheFileRatherThanTruncatingItInPlace`** — the target's **inode must change**
   across a rewrite. A replaced file is a different file; an in-place write keeps the inode.
   Deterministic, single-process, no concurrency to make it flaky, and — verified — `fileinode()`
   reports real values on Windows too, so it runs everywhere. **Re-planted the naive
   implementation and watched this one go red**, with a message that explains why it matters.
2. **`testAnAlreadyOpenReaderKeepsSeeingTheCompletePreviousContents`** — the guarantee itself,
   observed directly: a reader that opened before the rewrite goes on reading the complete old
   contents from its own handle. POSIX only; skipped on Windows for the reason in the table above,
   which is the same finding, now load-bearing twice.

A discarded third candidate, worth recording: "write to a read-only file in a writable directory
succeeds" would discriminate on POSIX (a truncating open fails, a rename succeeds) — but on
Windows the rename fails too, so it would have looked like a bug in the atomic implementation.
Probed before adopting, rejected on evidence.

## Also verified rather than assumed

`mime()`'s headline claim is that it reads content, not the filename. The first fixture was the
8-byte PNG signature plus zeros — reported as `application/octet-stream`, because this libmagic
wants the IHDR chunk before committing to `image/png`. Probed four fixtures to find out
(signature-only PNG, a real 1×1 PNG, `GIF89a`, `%PDF-1.4`) and switched to a genuinely valid PNG,
which is also more portable across libmagic versions than relying on one version's leniency.
Two formats are now covered, under deliberately misleading extensions in both directions
(`.txt` holding a PNG, `.png` holding prose).

## State

71 tests, 142 assertions, 3 skipped — all three Windows-only: two POSIX permission-bit tests and
the open-handle atomicity test. They run on CI. PHPStan max clean on the first pass, second item
running.

## Next

- **2.4 `Env::get()` + `Json`** — `Json` is where `JsonException::wrap()` from item 2.1 finally
  gets its caller, and the `Env` boolean-coercion table is the third T-05 property test.
- Still open: **2.7** (the coverage floor is stated in `AGENTS.md` §10 and NFR-07 but ungated) and
  the one-time admin — branch protection and the label import.
