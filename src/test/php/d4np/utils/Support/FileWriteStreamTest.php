<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\File;
use D4np\Utils\Support\FileException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `File::writeStream()` — the streaming counterpart of `File::write()`, added for item 9.4
 * because a CSV export must not buffer its table (spec NFR-12).
 *
 * The promises under test are ADR-0005's, restated for a caller that writes incrementally:
 * the replacement is atomic, a failure anywhere leaves the previous file untouched, and no
 * temporary file survives either outcome.
 */
final class FileWriteStreamTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/egl-utils-stream-' . bin2hex(random_bytes(8));
        if (!mkdir($this->dir) && !is_dir($this->dir)) {
            self::fail('could not create the test directory');
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $entry) {
            @unlink($entry);
        }
        @rmdir($this->dir);
    }

    private function path(): string
    {
        return $this->dir . '/target.txt';
    }

    /**
     * Files in the directory that are neither the target nor its sidecar lock.
     *
     * @return list<string>
     */
    private function strayFiles(): array
    {
        return array_values(array_filter(
            glob($this->dir . '/*') ?: [],
            fn (string $p): bool => $p !== $this->path() && $p !== $this->path() . '.lock',
        ));
    }

    public function testWritesWhatTheCallbackStreams(): void
    {
        File::writeStream($this->path(), static function ($handle): void {
            fwrite($handle, 'one ');
            fwrite($handle, 'two');
        });

        self::assertSame('one two', file_get_contents($this->path()));
    }

    public function testReplacesAnExistingFileCompletely(): void
    {
        file_put_contents($this->path(), 'a much longer previous content');

        File::writeStream($this->path(), static function ($handle): void {
            fwrite($handle, 'short');
        });

        self::assertSame('short', file_get_contents($this->path()));
    }

    public function testAThrowingWriterLeavesThePreviousContentIntact(): void
    {
        file_put_contents($this->path(), 'previous');

        try {
            File::writeStream($this->path(), static function ($handle): void {
                fwrite($handle, 'partial');

                throw new RuntimeException('writer failed');
            });
            self::fail('the writer should have thrown');
        } catch (RuntimeException $e) {
            self::assertSame('writer failed', $e->getMessage());
        }

        self::assertSame('previous', file_get_contents($this->path()));
    }

    public function testAThrowingWriterLeavesNoTemporaryFileBehind(): void
    {
        try {
            File::writeStream($this->path(), static function ($handle): void {
                fwrite($handle, 'partial');

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame([], $this->strayFiles());
    }

    public function testTheCallersExceptionTypeIsPreservedNotWrapped(): void
    {
        // Throwable, not FileException, is caught internally so the temp file is cleaned up
        // for any failure — but the original must propagate unchanged.
        $this->expectException(RuntimeException::class);

        File::writeStream($this->path(), static function (): void {
            throw new RuntimeException('mine');
        });
    }

    public function testASuccessfulWriteLeavesNoTemporaryFileBehind(): void
    {
        File::writeStream($this->path(), static function ($handle): void {
            fwrite($handle, 'ok');
        });

        self::assertSame([], $this->strayFiles());
    }

    public function testAMissingDirectoryThrowsFileException(): void
    {
        $this->expectException(FileException::class);
        $this->expectExceptionMessage('does not exist');

        File::writeStream($this->dir . '/absent/target.txt', static function (): void {
        });
    }

    public function testAWriterThatWritesNothingProducesAnEmptyFile(): void
    {
        File::writeStream($this->path(), static function (): void {
        });

        self::assertSame('', file_get_contents($this->path()));
    }

    public function testAnExistingFilesModeIsPreserved(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX permission bits are not meaningful on Windows.');
        }

        file_put_contents($this->path(), 'x');
        chmod($this->path(), 0640);

        File::writeStream($this->path(), static function ($handle): void {
            fwrite($handle, 'y');
        });

        self::assertSame(0640, fileperms($this->path()) & 0777);
    }
}
