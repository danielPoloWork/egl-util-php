<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\File;
use D4np\Utils\Support\FileException;
use PHPUnit\Framework\TestCase;

/**
 * `File`'s failure paths — the ones ADR-0005's design is about, and the ones that had never
 * been executed until the coverage floor (item 2.7) made their absence visible.
 *
 * These are not coverage theatre. Each asserts the contract that makes `File` worth using over
 * the native functions: **it fails loudly**. A filesystem error that returns `false` and is not
 * checked is how unchecked code silently loses data, so every one of these paths must produce a
 * `FileException` with a message that says what went wrong — and until now, none of them had
 * been shown to.
 *
 * POSIX permission bits are the lever for most of them, so those tests skip on Windows, and
 * skip again when the process can write regardless of mode (running as root, where permission
 * checks do not apply) — detected empirically rather than by assuming the CI user.
 */
final class FileFailureModesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/egl-utils-failtest-' . bin2hex(random_bytes(8));
        if (!mkdir($base) && !is_dir($base)) {
            self::fail(sprintf('could not create the test directory "%s"', $base));
        }
        $this->dir = $base;
    }

    protected function tearDown(): void
    {
        // Restore write permission before cleanup: a test that made the directory read-only
        // would otherwise leave an undeletable tree behind.
        @chmod($this->dir, 0777);
        foreach (glob($this->dir . '/*') ?: [] as $entry) {
            @chmod($entry, 0666);
            if (is_file($entry)) {
                @unlink($entry);
            }
        }
        if (is_dir($this->dir)) {
            @rmdir($this->dir);
        }
    }

    private function path(string $name): string
    {
        return $this->dir . '/' . $name;
    }

    /** Skip when POSIX modes cannot actually restrict this process. */
    private function requireEnforceablePermissions(string $probe): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX permission bits are not enforced on Windows');
        }

        clearstatcache(true, $probe);
        if (is_writable($probe)) {
            self::markTestSkipped('permissions are not enforced for this process (running as root?)');
        }
    }

    public function testWritingIntoAnUnwritableDirectoryFailsLoudly(): void
    {
        chmod($this->dir, 0555);
        $this->requireEnforceablePermissions($this->dir);

        $this->expectException(FileException::class);
        $this->expectExceptionMessage('is not writable');

        File::write($this->path('nope.txt'), 'content');
    }

    public function testWritingFailsLoudlyWhenTheLockFileCannotBeOpened(): void
    {
        // The sidecar lock is opened before any temp file is created (ADR-0005). An existing
        // lock file the process cannot open stops the write with a clear message rather than
        // proceeding unserialised.
        $target = $this->path('locked.txt');
        $lock = $target . '.lock';
        touch($lock);
        chmod($lock, 0000);
        $this->requireEnforceablePermissions($lock);

        $this->expectException(FileException::class);
        $this->expectExceptionMessage('lock file');

        File::write($target, 'content');
    }

    /**
     * The rename is the one step that cannot be retried halfway: if it fails, the temporary file
     * must not be left behind as litter, and the caller must be told.
     *
     * A directory standing where the target should be makes `rename()` fail on any platform —
     * no permission games needed.
     */
    public function testAFailedRenameThrowsAndLeavesNoTemporaryFileBehind(): void
    {
        $target = $this->path('target');
        mkdir($target);
        // A non-empty directory cannot be replaced by rename() on any supported platform.
        file_put_contents($target . '/occupant', 'x');

        try {
            File::write($target, 'content');
            self::fail('expected a FileException');
        } catch (FileException $e) {
            self::assertStringContainsString('rename', $e->getMessage());
        }

        $leftovers = array_values(array_filter(
            glob($this->dir . '/*') ?: [],
            static fn (string $entry): bool => str_contains(basename($entry), '.egl-utils-'),
        ));
        self::assertSame([], $leftovers, 'a failed write must clean up its own temporary file');

        unlink($target . '/occupant');
        rmdir($target);
    }

    public function testReadingAnUnreadableFileFailsLoudly(): void
    {
        $path = $this->path('unreadable.txt');
        file_put_contents($path, 'secret');
        chmod($path, 0000);

        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX permission bits are not enforced on Windows');
        }
        clearstatcache(true, $path);
        if (is_readable($path)) {
            self::markTestSkipped('permissions are not enforced for this process (running as root?)');
        }

        $this->expectException(FileException::class);
        $this->expectExceptionMessage('failed to open for reading');

        File::read($path);
    }

    public function testMimeDetectionOnAnUnreadableFileFailsLoudly(): void
    {
        $path = $this->path('unreadable.bin');
        file_put_contents($path, 'GIF89a');
        chmod($path, 0000);

        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX permission bits are not enforced on Windows');
        }
        clearstatcache(true, $path);
        if (is_readable($path)) {
            self::markTestSkipped('permissions are not enforced for this process (running as root?)');
        }

        $this->expectException(FileException::class);
        $this->expectExceptionMessage('detection failed');

        File::mime($path);
    }

    public function testWritingToAPathWhoseParentIsAFileFailsLoudly(): void
    {
        // dirname() of "<file>/child.txt" is a regular file, so is_dir() is false — the first
        // guard in write(), reached here through a realistic mistake rather than a contrived one.
        $file = $this->path('a-file.txt');
        file_put_contents($file, 'x');

        $this->expectException(FileException::class);
        $this->expectExceptionMessage('does not exist');

        File::write($file . '/child.txt', 'content');
    }
}
