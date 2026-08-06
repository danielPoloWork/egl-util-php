<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

/**
 * Filesystem reads and writes that fail loudly and replace atomically (spec §2 items 22–23).
 *
 * Every operation throws {@see FileException} instead of returning `false`. PHP's native
 * filesystem functions report failure by return value, which is exactly how unchecked code
 * silently loses data — a `file_put_contents()` whose result nobody inspects is a write that
 * may never have happened.
 *
 * The concurrency and atomicity model, and why the lock is a sidecar rather than the target
 * itself, are recorded in **ADR-0005**.
 */
final class File
{
    /** Mode applied to a file this class creates, when no existing file's mode can be preserved. */
    private const DEFAULT_MODE = 0644;

    /** Suffix of the sidecar lock file (ADR-0005). */
    private const LOCK_SUFFIX = '.lock';

    private function __construct()
    {
        // Static-only: no instances.
    }

    /**
     * Replace `$path`'s contents atomically.
     *
     * A concurrent reader sees either the complete previous contents or the complete new
     * contents — never a mix, and never a truncated file — because the bytes are written to a
     * temporary file in the **same directory** and then `rename()`d over the target. Same
     * directory is not incidental: `rename()` is only atomic within one filesystem.
     *
     * Cooperating writers are serialised through an exclusive `flock()` on a **sidecar** lock
     * file (`<path>.lock`), held across the whole prepare-and-rename sequence. The lock cannot
     * be taken on the target itself — see ADR-0005 for the evidence; briefly, a handle held on
     * the target makes the `rename()` fail on Windows, and one released before the `rename()`
     * serialises nothing that matters.
     *
     * The target's existing permissions are preserved; a newly created file gets
     * {@see self::DEFAULT_MODE}.
     *
     * @throws FileException if the directory is missing or unwritable, if the temporary file
     *                        cannot be created in it, if the write is short, or if the rename
     *                        fails.
     */
    public static function write(string $path, string $contents): void
    {
        $dir = self::writableDirectoryOf($path);
        $mode = self::currentModeOf($path);
        $lock = self::acquireExclusiveLock($path);

        try {
            self::replaceAtomically($path, $dir, $contents, $mode);
        } finally {
            self::releaseLock($lock);
        }
    }

    /**
     * Read `$path`, hand its contents to `$mutator`, and write back what it returns —
     * **all under one exclusive lock**.
     *
     * The lock spanning both halves is the entire point, and the reason this cannot be
     * assembled from {@see self::read()} plus {@see self::write()}: between two separately
     * locked calls, two processes both read `5`, both compute `6`, and one increment is
     * lost. A counter built that way issues duplicate identifiers under exactly the load
     * that makes duplicates expensive.
     *
     * `$mutator` receives the current contents, or `''` when the file does not exist yet, and
     * returns the new contents. It is called **before** anything is written, so a mutator
     * that throws — refusing an increment past a cap, say — leaves the file untouched and its
     * exception propagates unchanged.
     *
     * @param callable(string): string $mutator
     *
     * @throws FileException if the directory is missing or unwritable, if the current
     *                        contents cannot be read, or if the replacement fails.
     * @throws \Throwable    whatever `$mutator` threw, unchanged, with the file unmodified
     */
    public static function update(string $path, callable $mutator): void
    {
        $dir = self::writableDirectoryOf($path);
        $mode = self::currentModeOf($path);
        $lock = self::acquireExclusiveLock($path);

        try {
            $current = '';
            if (is_file($path)) {
                $read = @file_get_contents($path);
                if ($read === false) {
                    throw new FileException(sprintf('Cannot update "%s": reading the current contents failed.', $path));
                }
                $current = $read;
            }

            // Computed before the write, so a throwing mutator cannot leave a partial state.
            $updated = $mutator($current);

            self::replaceAtomically($path, $dir, $updated, $mode);
        } finally {
            self::releaseLock($lock);
        }
    }

    /**
     * Replace `$path` atomically with whatever `$writer` streams, without buffering it.
     *
     * The same discipline as {@see self::write()} — sidecar lock, temporary file in the same
     * directory, mode applied before the rename, ADR-0005 throughout — but the caller writes
     * to an open handle instead of handing over a finished string. That is the difference
     * between a memory cost proportional to the output and one proportional to a single row,
     * which is what a streaming producer (spec NFR-12) needs.
     *
     * `$writer` receives the temporary file's handle. It must not close it; anything it
     * throws propagates unchanged after the temporary file is removed, so a failed write
     * leaves the target exactly as it was.
     *
     * @param callable(resource): void $writer
     *
     * @throws FileException if the directory is missing or unwritable, if the temporary file
     *                        cannot be created in it, or if the flush or rename fails.
     * @throws \Throwable    whatever `$writer` threw, unchanged, after the temporary file has
     *                        been removed (the same propagation contract as
     *                        {@see \D4np\Utils\Database\Transaction::run()})
     */
    public static function writeStream(string $path, callable $writer): void
    {
        $dir = self::writableDirectoryOf($path);
        $mode = self::currentModeOf($path);
        $lock = self::acquireExclusiveLock($path);

        try {
            $tmp = self::createTempFileIn($dir);

            try {
                $handle = @fopen($tmp, 'wb');
                if ($handle === false) {
                    throw new FileException(sprintf('Cannot write "%s": failed to open the temporary file.', $path));
                }

                try {
                    $writer($handle);

                    // fflush() before the rename: buffered bytes still in userland when the
                    // rename happens would make the "complete or previous" promise a lie.
                    if (!fflush($handle)) {
                        throw new FileException(sprintf('Cannot write "%s": flushing the temporary file failed.', $path));
                    }
                } finally {
                    @fclose($handle);
                }

                if (!@chmod($tmp, $mode)) {
                    throw new FileException(sprintf('Cannot write "%s": failed to set mode on the temporary file.', $path));
                }

                if (!@rename($tmp, $path)) {
                    throw new FileException(sprintf('Cannot write "%s": atomic rename from the temporary file failed.', $path));
                }
            } catch (\Throwable $e) {
                // Catch Throwable, not FileException: the caller's writer may throw anything,
                // and the temporary file must not survive either way.
                if (is_file($tmp)) {
                    @unlink($tmp);
                }

                throw $e;
            }
        } finally {
            self::releaseLock($lock);
        }
    }

    /**
     * Read `$path` in full, under a shared `flock()`.
     *
     * The shared lock is what makes this cooperate with a *third-party* writer that modifies
     * files in place rather than atomically; against {@see self::write()} it is redundant by
     * construction, since an atomic replacement is never observable half-done (ADR-0005).
     *
     * @throws FileException if the path is not a readable file, or if reading fails.
     */
    public static function read(string $path): string
    {
        if (!is_file($path)) {
            throw new FileException(sprintf('Cannot read "%s": not a file.', $path));
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new FileException(sprintf('Cannot read "%s": failed to open for reading.', $path));
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                throw new FileException(sprintf('Cannot read "%s": failed to acquire a shared lock.', $path));
            }

            $contents = stream_get_contents($handle);
            if ($contents === false) {
                throw new FileException(sprintf('Cannot read "%s": read failed after locking.', $path));
            }

            return $contents;
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * The MIME type of `$path`, detected from its **contents**.
     *
     * Never infers from the filename: an extension is caller-supplied data, and trusting it is
     * how an uploaded `.jpg` turns out to be a PHP script. Uses `ext-fileinfo`, which
     * `composer.json` requires, so the detection path is always available.
     *
     * @throws FileException if the path is not a readable file, or if detection fails.
     */
    public static function mime(string $path): string
    {
        if (!is_file($path)) {
            throw new FileException(sprintf('Cannot detect the MIME type of "%s": not a file.', $path));
        }

        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new FileException('Cannot detect a MIME type: failed to open the fileinfo database.');
        }

        try {
            $mime = @finfo_file($finfo, $path);
            if ($mime === false) {
                throw new FileException(sprintf('Cannot detect the MIME type of "%s": detection failed.', $path));
            }

            return $mime;
        } finally {
            finfo_close($finfo);
        }
    }

    /**
     * The directory `$path` lives in, having confirmed it exists and is writable.
     *
     * @throws FileException
     */
    private static function writableDirectoryOf(string $path): string
    {
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            throw new FileException(sprintf('Cannot write "%s": directory "%s" does not exist.', $path, $dir));
        }
        if (!is_writable($dir)) {
            throw new FileException(sprintf('Cannot write "%s": directory "%s" is not writable.', $path, $dir));
        }

        return $dir;
    }

    /**
     * Put `$contents` in place of `$path` atomically. **The caller must already hold the
     * exclusive lock** — this is the shared body of {@see self::write()} and
     * {@see self::update()}, not an entry point.
     *
     * @throws FileException
     */
    private static function replaceAtomically(string $path, string $dir, string $contents, int $mode): void
    {
        $tmp = self::createTempFileIn($dir);

        try {
            self::putAll($tmp, $contents, $path);

            // chmod BEFORE the rename: tempnam() creates 0600, and a reader must never see
            // the target briefly carrying the temp file's restrictive mode.
            if (!@chmod($tmp, $mode)) {
                throw new FileException(sprintf('Cannot write "%s": failed to set mode on the temporary file.', $path));
            }

            if (!@rename($tmp, $path)) {
                throw new FileException(sprintf('Cannot write "%s": atomic rename from the temporary file failed.', $path));
            }
        } catch (FileException $e) {
            // The temp file is this method's litter, not the caller's problem.
            if (is_file($tmp)) {
                @unlink($tmp);
            }

            throw $e;
        }
    }

    /**
     * The mode to give the file after writing: the target's own, when it already exists, so a
     * rewrite never silently changes its permissions.
     */
    private static function currentModeOf(string $path): int
    {
        if (!is_file($path)) {
            return self::DEFAULT_MODE;
        }

        $perms = @fileperms($path);

        return $perms === false ? self::DEFAULT_MODE : ($perms & 0777);
    }

    /**
     * @return resource the held lock handle, for {@see self::releaseLock()}
     * @throws FileException
     */
    private static function acquireExclusiveLock(string $path)
    {
        $lockPath = $path . self::LOCK_SUFFIX;

        // 'c' creates the file when absent and does NOT truncate it — the sidecar carries no
        // content, only the lock, so truncation would be harmless but pointless.
        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            throw new FileException(sprintf('Cannot write "%s": failed to open the lock file "%s".', $path, $lockPath));
        }

        if (!flock($handle, LOCK_EX)) {
            @fclose($handle);

            throw new FileException(sprintf('Cannot write "%s": failed to acquire an exclusive lock.', $path));
        }

        return $handle;
    }

    /**
     * @param resource $handle
     */
    private static function releaseLock($handle): void
    {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    /**
     * A temporary file guaranteed to live in `$dir`.
     *
     * `tempnam()` silently falls back to the system temp directory when it cannot use the one
     * it was given — which would put the temp file on a different filesystem and quietly make
     * the subsequent `rename()` non-atomic. The returned path is therefore verified to be in
     * `$dir`, and the fallback is treated as a failure rather than accepted.
     *
     * @throws FileException
     */
    private static function createTempFileIn(string $dir): string
    {
        $tmp = @tempnam($dir, '.egl-utils-');
        if ($tmp === false) {
            throw new FileException(sprintf('Failed to create a temporary file in "%s".', $dir));
        }

        $tmpDir = realpath(\dirname($tmp));
        $wanted = realpath($dir);
        if ($tmpDir === false || $wanted === false || $tmpDir !== $wanted) {
            @unlink($tmp);

            throw new FileException(sprintf(
                'Refusing to write: the temporary file landed in "%s" instead of "%s", so the '
                . 'replacement would cross filesystems and lose atomicity.',
                $tmpDir === false ? 'an unresolvable directory' : $tmpDir,
                $dir,
            ));
        }

        return $tmp;
    }

    /**
     * Write every byte of `$contents` to `$tmp`, treating a short write as a failure.
     *
     * @throws FileException
     */
    private static function putAll(string $tmp, string $contents, string $target): void
    {
        $written = @file_put_contents($tmp, $contents);
        if ($written === false) {
            throw new FileException(sprintf('Cannot write "%s": writing the temporary file failed.', $target));
        }

        $expected = \strlen($contents);
        if ($written !== $expected) {
            throw new FileException(sprintf(
                'Cannot write "%s": short write to the temporary file (%d of %d bytes).',
                $target,
                $written,
                $expected,
            ));
        }
    }
}
