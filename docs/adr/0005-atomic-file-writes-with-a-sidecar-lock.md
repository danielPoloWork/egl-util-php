# ADR-0005: Atomic file writes, with the lock on a sidecar rather than the target

- **Status:** Accepted
- **Date:** 2026-08-03
- **Deciders:** maintainer (`@danielPoloWork`), agent acting as tech-lead
- **Related:** ROADMAP item 2.3 · [RFC-0001](../rfc/0001-egl-utils-library.md) ·
  [spec §2 items 22–23](../specs/01_spec_utils.md) · [ADR-0004](0004-root-the-exception-hierarchy-on-an-interface.md)

## Context

RFC-0001 and spec §2 item 22 ask for `File::write()`/`File::read()` to be *"flock-guarded,
atomic write via temp+rename"*. Implementing that literally turns out to be impossible, and
discovering **why** is what this ADR exists to record — the failure is silent, platform-specific,
and would have shipped as a write path that simply does not work on Windows.

Two distinct properties hide inside that one sentence:

- **Atomicity** — a reader never observes a half-written file. Delivered by writing to a
  temporary file in the *same directory* and `rename()`ing it over the target. Same directory is
  load-bearing: `rename()` is only atomic within a single filesystem.
- **Writer serialisation** — two concurrent writers do not lose each other's updates. Delivered
  by an exclusive `flock()`.

The natural implementation — open the target, `flock(LOCK_EX)`, write the temp file, rename,
release — fails, and it fails in two different ways depending on where the release goes:

1. **Lock held across the `rename()`: broken on Windows.** Verified directly while writing this
   ADR, on PHP 8.3.1 / Windows 11:

   | Scenario | Result |
   |---|---|
   | `rename()` over an existing target, no handles open | **OK** |
   | `rename()` over an existing target while a handle holds it open | **FAILS** |

   POSIX unlinks the old directory entry and lets existing handles keep reading the old inode;
   Windows refuses the replacement outright. So a lock handle held on the target — which is
   exactly what serialisation requires — makes every atomic write fail on Windows.

2. **Lock released before the `rename()`: serialises nothing that matters.** The `rename()` *is*
   the mutation. If the critical section ends before it, two writers can each prepare a temp
   file under the lock, release, and then rename in either order — the identical outcome to
   having no lock at all.

A third subtlety, unrelated to locking but equally silent: **`tempnam()` falls back to the
system temp directory** when it cannot use the directory it was given. That would place the
temporary file on a different filesystem, at which point `rename()` stops being atomic and
degrades to copy-then-delete — the guarantee evaporates with no error anywhere.

## Decision

**`File::write()` replaces the target atomically via a temporary file in the same directory,
and takes its exclusive `flock()` on a sidecar lock file — `<path>.lock` — held across the whole
prepare-and-rename sequence.**

The sidecar is never the object of a `rename()`, so holding it is compatible with replacing the
target on every platform, and holding it *across* the rename is what makes the serialisation
real rather than decorative.

Supporting commitments:

- The path `tempnam()` returns is **verified to be in the intended directory**; the silent
  system-temp fallback is treated as a failure, not accepted. Losing atomicity quietly is worse
  than refusing to write.
- The temporary file is `chmod`ed to the target's existing mode — or `0644` for a new file —
  **before** the rename, so the target never briefly carries `tempnam()`'s `0600`.
- A short write (bytes written ≠ bytes given) is a failure. So is every other error: `File`
  throws {@see FileException} rather than returning `false`, per ADR-0004's hierarchy.
- `File::read()` takes a shared `flock()`. Against this library's own writer that is redundant
  by construction — an atomic replacement is never observable half-done — and it is kept because
  it is what makes reads cooperate with a *third-party* writer that modifies files in place.

## Alternatives Considered

- **Lock the target, hold across the rename** — the literal reading of the RFC. Rejected: it
  does not work on Windows (evidence above). This is the alternative the ADR exists to close.
- **Lock the target, release before the rename** — rejected: provably equivalent to no lock,
  because the rename is the mutation. It would have satisfied "flock-guarded" as a word while
  delivering nothing the word implies.
- **No write lock at all; rely on atomic replacement alone** — the mainstream choice (Symfony's
  `Filesystem::dumpFile()` does exactly this, leaving locking to a separate component). Genuinely
  defensible, and it leaves no `.lock` files behind. Rejected because it silently drops the
  serialisation half of an approved requirement; if the litter proves worse than the guarantee is
  worth, that is a decision to revisit **with this evidence in hand**, not one to make by
  omission.
- **Keep lock files in the system temp directory**, keyed by a hash of the target path, to avoid
  litter next to user data. Rejected: two processes running as different users see different
  temp directories, so serialisation would break *silently* — the failure mode this ADR is
  written to avoid.
- **Write in place under an exclusive lock** (`truncate` + write, no temp file) — rejected: it is
  the one design where a concurrent reader genuinely can observe a truncated file, and `flock()`
  is advisory, so a reader that does not cooperate observes it.

## Consequences

- **Callers get a real atomicity guarantee**, on every supported platform: a rewrite is a
  replacement, and a reader sees one complete version or the other.
- **Writer serialisation is real but advisory** — it holds among processes that go through
  `File::write()`. `flock()` cannot bind a process that does not ask for the lock, and it is
  unreliable on NFS. Concurrent full replacements are **last-writer-wins**: each write is
  complete, and lock-acquisition order decides which survives. This library does **not** ship a
  read-modify-write primitive; a caller needing one needs a lock manager, not this method.
- **The cost is visible: a `<path>.lock` file appears beside every written file, and stays.**
  Unlinking a lock file is itself racy — the classic delete-while-another-process-waits bug — so
  it is deliberately left in place. Callers that write into a directory they also list should
  expect it.
- **Testing atomicity needed a real discriminator.** The obvious test — write, then read back,
  and check the content is complete — passes against a naive truncating implementation, because
  a single-process sequential test contains no concurrency to observe. It was written, caught
  passing against a deliberately planted `file_put_contents()` implementation, and replaced with
  two tests that do discriminate: the target's **inode must change** across a rewrite (a replaced
  file is a different file; an in-place write keeps the inode), and — on POSIX — an
  already-open reader must go on seeing the **complete previous** contents. The first is
  portable and fails against the naive implementation; the second cannot run on Windows for the
  reason in Context 1, and is skipped there.

## References

- Spec §2 items 22–23; RFC-0001 *Decision → API contract*
- POSIX `rename(2)` — atomic replacement within a filesystem; Windows `MoveFileEx` sharing
  semantics (the behavioural difference measured in Context 1)
- PHP manual: `flock()` (advisory locking, NFS caveat), `tempnam()` (directory fallback),
  `rename()`
- Symfony `Filesystem::dumpFile()` — prior art for the no-write-lock alternative
