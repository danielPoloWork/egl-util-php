<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\File;
use D4np\Utils\Support\FileException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `File::update()` — locked read-modify-write, added for item 9.5.
 *
 * The property that distinguishes it from `read()` + `write()` is invisible in a
 * single-process test: that the lock spans both halves. {@see FileSequenceConcurrencyTest}
 * (T-14) is what proves it, by failing when the two are split. These tests cover the rest of
 * the contract — the mutator's view of a missing file, and that a throwing mutator leaves
 * nothing behind.
 */
final class FileUpdateTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/egl-utils-update-' . \bin2hex(\random_bytes(8));
        if (!\mkdir($this->dir) && !\is_dir($this->dir)) {
            self::fail('could not create the test directory');
        }
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir . '/*') ?: [] as $entry) {
            @\unlink($entry);
        }
        @\rmdir($this->dir);
    }

    private function path(): string
    {
        return $this->dir . '/state.txt';
    }

    public function testTheMutatorReceivesTheCurrentContents(): void
    {
        \file_put_contents($this->path(), 'before');
        $seen = null;

        File::update($this->path(), static function (string $current) use (&$seen): string {
            $seen = $current;

            return 'after';
        });

        self::assertSame('before', $seen);
        self::assertSame('after', \file_get_contents($this->path()));
    }

    public function testAMissingFileIsPresentedAsAnEmptyString(): void
    {
        $seen = null;

        File::update($this->path(), static function (string $current) use (&$seen): string {
            $seen = $current;

            return 'created';
        });

        self::assertSame('', $seen);
        self::assertSame('created', \file_get_contents($this->path()));
    }

    public function testAnEmptyFileIsDistinguishableFromNothingByTheCallerOnly(): void
    {
        // Both arrive as '' — documented, and the reason FileSequence treats a blank state
        // file as a fresh start rather than trying to tell them apart.
        \file_put_contents($this->path(), '');
        $seen = 'unset';

        File::update($this->path(), static function (string $current) use (&$seen): string {
            $seen = $current;

            return 'x';
        });

        self::assertSame('', $seen);
    }

    public function testAThrowingMutatorLeavesTheFileUntouched(): void
    {
        \file_put_contents($this->path(), 'original');

        try {
            File::update($this->path(), static function (): string {
                throw new RuntimeException('refused');
            });
            self::fail('the mutator should have thrown');
        } catch (RuntimeException $e) {
            self::assertSame('refused', $e->getMessage());
        }

        self::assertSame('original', \file_get_contents($this->path()));
    }

    public function testAThrowingMutatorDoesNotCreateAMissingFile(): void
    {
        try {
            File::update($this->path(), static function (): string {
                throw new RuntimeException('refused');
            });
        } catch (RuntimeException) {
            // expected
        }

        self::assertFileDoesNotExist($this->path());
    }

    public function testAThrowingMutatorLeavesNoTemporaryFileBehind(): void
    {
        try {
            File::update($this->path(), static function (): string {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // expected
        }

        $strays = \array_values(\array_filter(
            \glob($this->dir . '/*') ?: [],
            fn (string $p): bool => $p !== $this->path() && $p !== $this->path() . '.lock',
        ));

        self::assertSame([], $strays);
    }

    public function testSequentialUpdatesCompose(): void
    {
        for ($i = 0; $i < 5; $i++) {
            File::update($this->path(), static fn (string $c): string => $c . 'x');
        }

        self::assertSame('xxxxx', \file_get_contents($this->path()));
    }

    public function testAMissingDirectoryThrowsFileException(): void
    {
        $this->expectException(FileException::class);
        $this->expectExceptionMessage('does not exist');

        File::update($this->dir . '/absent/state.txt', static fn (string $c): string => $c);
    }

    public function testAnExistingFilesModeIsPreserved(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX mode bits are not meaningful on Windows.');
        }

        \file_put_contents($this->path(), 'x');
        \chmod($this->path(), 0640);

        File::update($this->path(), static fn (string $c): string => $c . 'y');

        self::assertSame(0640, \fileperms($this->path()) & 0777);
    }
}
