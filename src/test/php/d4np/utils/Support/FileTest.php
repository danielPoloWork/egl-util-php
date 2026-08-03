<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\File;
use D4np\Utils\Support\FileException;
use PHPUnit\Framework\TestCase;

/**
 * `File` — spec §2 items 22–23, and the concurrency/atomicity semantics ADR-0005 records.
 *
 * Every test works inside its own temporary directory, created in `setUp()` and removed in
 * `tearDown()`, so nothing here depends on or disturbs the repository tree.
 */
final class FileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/egl-utils-filetest-' . bin2hex(random_bytes(8));
        if (!mkdir($base) && !is_dir($base)) {
            self::fail(sprintf('could not create the test directory "%s"', $base));
        }
        $this->dir = $base;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $entry) {
            if (is_file($entry)) {
                unlink($entry);
            }
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function path(string $name): string
    {
        return $this->dir . '/' . $name;
    }

    // ---------------------------------------------------------------- write

    public function testWriteCreatesTheFileWithExactContents(): void
    {
        $path = $this->path('created.txt');

        File::write($path, "line one\nline two\n");

        self::assertFileExists($path);
        self::assertSame("line one\nline two\n", file_get_contents($path));
    }

    public function testWriteReplacesExistingContentsEntirely(): void
    {
        $path = $this->path('replaced.txt');
        file_put_contents($path, 'a much longer previous content that must not survive');

        File::write($path, 'short');

        self::assertSame('short', file_get_contents($path));
    }

    public function testWriteHandlesEmptyContents(): void
    {
        $path = $this->path('empty.txt');

        File::write($path, '');

        self::assertFileExists($path);
        self::assertSame('', file_get_contents($path));
        self::assertSame(0, filesize($path));
    }

    public function testWriteHandlesBinaryContentsWithNullBytes(): void
    {
        $path = $this->path('binary.bin');
        $binary = random_bytes(4096) . "\x00\x00" . random_bytes(64);

        File::write($path, $binary);

        self::assertSame($binary, file_get_contents($path));
    }

    public function testWriteLeavesNoTemporaryFileBehind(): void
    {
        $path = $this->path('clean.txt');

        File::write($path, 'content');

        $leftovers = array_values(array_filter(
            glob($this->dir . '/*') ?: [],
            static fn (string $entry): bool => str_contains(basename($entry), '.egl-utils-'),
        ));

        self::assertSame([], $leftovers, 'the temporary file must be renamed away, not left behind');
    }

    public function testWritePreservesAnExistingFilesPermissions(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX permission bits are not meaningfully enforced on Windows');
        }

        $path = $this->path('perms.txt');
        file_put_contents($path, 'before');
        chmod($path, 0640);
        clearstatcache(true, $path);

        File::write($path, 'after');
        clearstatcache(true, $path);

        self::assertSame(0640, fileperms($path) & 0777, 'a rewrite must not silently change the mode');
    }

    public function testWriteGivesANewFileTheDefaultModeNotTheTempFilesRestrictiveOne(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX permission bits are not meaningfully enforced on Windows');
        }

        $path = $this->path('newfile.txt');

        File::write($path, 'content');
        clearstatcache(true, $path);

        // tempnam() creates 0600; the target must not inherit it.
        self::assertSame(0644, fileperms($path) & 0777);
    }

    public function testWriteThrowsWhenTheDirectoryDoesNotExist(): void
    {
        $this->expectException(FileException::class);
        $this->expectExceptionMessage('does not exist');

        File::write($this->path('no/such/dir/file.txt'), 'content');
    }

    /**
     * The mechanism behind the atomicity guarantee: a write must **replace** the file, not
     * truncate and refill it in place. A replaced file is a different file — a new inode — so
     * the inode changing across a rewrite is a deterministic, single-process observation of the
     * property, with no concurrency to make it flaky.
     *
     * This is the assertion a naive `file_put_contents($path, …)` fails: truncating in place
     * keeps the inode. Verified by planting exactly that implementation and watching this test
     * go red — which is what makes it a test of atomicity rather than a test that the write
     * happened at all.
     */
    public function testWriteReplacesTheFileRatherThanTruncatingItInPlace(): void
    {
        $path = $this->path('atomic.txt');
        File::write($path, str_repeat('A', 4096));

        clearstatcache(true, $path);
        $before = fileinode($path);
        self::assertNotFalse($before, 'this platform must report inodes for the test to mean anything');

        File::write($path, str_repeat('B', 2048));

        clearstatcache(true, $path);
        $after = fileinode($path);

        self::assertNotSame(
            $before,
            $after,
            'the inode did not change, so the file was modified in place rather than replaced — '
            . 'an in-place write is observable half-done by a concurrent reader',
        );
        self::assertSame(str_repeat('B', 2048), file_get_contents($path));
    }

    /**
     * The atomicity guarantee itself, observed directly: a reader that opened the file *before*
     * a rewrite goes on reading the complete previous contents from its own handle. It never
     * sees a truncated or half-written file, because the replacement gave the target a new inode
     * and left the old one intact until the last handle closed.
     *
     * POSIX only. Windows refuses to `rename()` over a target another handle holds open — the
     * finding that shaped this design and is recorded in ADR-0005 — so the scenario cannot exist
     * there.
     */
    public function testAnAlreadyOpenReaderKeepsSeeingTheCompletePreviousContents(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Windows refuses to rename over a target that is held open (ADR-0005)');
        }

        $path = $this->path('atomic-open-handle.txt');
        $old = str_repeat('A', 100_000);
        $new = str_repeat('B', 40_000);

        File::write($path, $old);

        $reader = fopen($path, 'rb');
        self::assertNotFalse($reader);

        try {
            File::write($path, $new);

            $observed = stream_get_contents($reader);

            self::assertSame($old, $observed, 'an open reader must not observe the replacement at all');
            self::assertSame($new, file_get_contents($path), 'a fresh read must see the new contents');
        } finally {
            fclose($reader);
        }
    }

    // ----------------------------------------------------------------- read

    public function testReadReturnsTheFullContents(): void
    {
        $path = $this->path('read.txt');
        $contents = str_repeat("payload line\n", 5000);
        file_put_contents($path, $contents);

        self::assertSame($contents, File::read($path));
    }

    public function testReadRoundTripsWhatWriteStored(): void
    {
        $path = $this->path('roundtrip.bin');
        $payload = random_bytes(8192);

        File::write($path, $payload);

        self::assertSame($payload, File::read($path));
    }

    public function testReadReturnsEmptyStringForAnEmptyFile(): void
    {
        $path = $this->path('emptyread.txt');
        touch($path);

        self::assertSame('', File::read($path));
    }

    public function testReadThrowsWhenTheFileIsMissing(): void
    {
        $this->expectException(FileException::class);
        $this->expectExceptionMessage('not a file');

        File::read($this->path('absent.txt'));
    }

    public function testReadThrowsWhenGivenADirectory(): void
    {
        $this->expectException(FileException::class);
        $this->expectExceptionMessage('not a file');

        File::read($this->dir);
    }

    // ----------------------------------------------------------------- mime

    /**
     * The distinguishing behaviour of item 23: detection reads the *contents*. A PNG named
     * `.txt` is a PNG. This is the test that separates a real detector from one that maps
     * extensions, and it is why an extension is never trusted — a caller-supplied `.jpg` that
     * is really a PHP script is the classic upload vulnerability.
     */
    public function testMimeIgnoresTheExtensionAndReadsTheContents(): void
    {
        // A genuinely valid 1x1 PNG, not just the 8-byte signature: this libmagic requires the
        // IHDR chunk before it will commit to image/png, and a signature-only fixture is
        // reported as application/octet-stream. Verified rather than assumed — and a real file
        // is portable across libmagic versions in a way that relying on one version's leniency
        // would not be.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==',
            true,
        );
        self::assertIsString($png);

        $misnamed = $this->path('actually-a-png.txt');
        File::write($misnamed, $png);

        self::assertSame('image/png', File::mime($misnamed));
    }

    public function testMimeDetectsGifFromContentUnderAMisleadingExtension(): void
    {
        // A second format, so the claim "detection reads content" rests on more than one
        // fixture: GIF's magic is self-sufficient where PNG's signature alone is not.
        $path = $this->path('actually-a-gif.pdf');
        File::write($path, 'GIF89a' . str_repeat("\x00", 32));

        self::assertSame('image/gif', File::mime($path));
    }

    public function testMimeDetectsPlainTextRegardlessOfAMisleadingExtension(): void
    {
        $path = $this->path('actually-text.png');
        File::write($path, "just some ordinary prose, nothing binary about it\n");

        self::assertSame('text/plain', File::mime($path));
    }

    public function testMimeThrowsWhenTheFileIsMissing(): void
    {
        $this->expectException(FileException::class);
        $this->expectExceptionMessage('not a file');

        File::mime($this->path('absent.bin'));
    }

    // -------------------------------------------------------- exception type

    public function testEveryFailureIsCatchableThroughTheLibraryMarker(): void
    {
        // FileException joins the ADR-0004 family, so a consumer catching everything this
        // library raises catches filesystem failures too — asserted here rather than assumed
        // from the class declaration.
        try {
            File::read($this->path('absent.txt'));
            self::fail('expected a FileException');
        } catch (\D4np\Utils\Support\UtilsThrowable $e) {
            self::assertInstanceOf(FileException::class, $e);
        }
    }
}
