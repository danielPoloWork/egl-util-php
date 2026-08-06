<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\Csv;
use D4np\Utils\Support\Delimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * T-08's round-trip half — spec r3 §6, RFC-0002; ADR-0037.
 *
 * The property under test is the one PHP's defaults do **not** give you: whatever goes in
 * comes back out. The `trailing backslash` and `lone backslash` cases are the reason the
 * class passes `escape: ''` — under PHP's default escape they do not merely format
 * differently, they **corrupt**, and one of the tests below pins that native behaviour so
 * the fix is visibly load-bearing rather than decorative.
 */
#[Group('T-08')]
final class CsvRoundTripTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/egl-utils-csv-' . bin2hex(random_bytes(8));
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

    private function path(string $name = 'out.csv'): string
    {
        return $this->dir . '/' . $name;
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function fieldCases(): iterable
    {
        $backslash = chr(92);

        yield 'plain' => [['a', 'b']];
        yield 'embedded delimiter' => [['a,b', 'c']];
        yield 'embedded quote' => [['say "hi"', 'x']];
        yield 'embedded newline' => [["line1\nline2", 'x']];
        yield 'embedded CRLF' => [["line1\r\nline2", 'x']];
        yield 'trailing backslash' => [['ends with ' . $backslash, 'next']];
        yield 'lone backslash' => [[$backslash, 'x']];
        yield 'backslash before quote' => [['a' . $backslash . '"b', 'x']];
        yield 'empty field' => [['', 'x']];
        yield 'all empty' => [['', '']];
        yield 'single empty field' => [['']];
        yield 'leading zero preserved as text' => [['007', 'x']];
        yield 'unicode' => [['caffè', 'naïve']];
        yield 'leading and trailing spaces' => [['  padded  ', 'x']];
        yield 'semicolons inside a comma file' => [['a;b', 'c']];
        yield 'single quote' => [["it's", 'x']];
    }

    /**
     * @param list<string> $row
     */
    #[DataProvider('fieldCases')]
    public function testRowSurvivesTheRoundTrip(array $row): void
    {
        Csv::write($this->path(), [$row]);

        self::assertSame([$row], iterator_to_array(Csv::read($this->path())));
    }

    /**
     * @param list<string> $row
     */
    #[DataProvider('fieldCases')]
    public function testRowSurvivesTheRoundTripUnderEveryDelimiter(array $row): void
    {
        foreach (Delimiter::cases() as $delimiter) {
            Csv::write($this->path(), [$row], $delimiter);

            self::assertSame(
                [$row],
                iterator_to_array(Csv::read($this->path(), $delimiter)),
                sprintf('round trip failed for delimiter %s', $delimiter->name),
            );
        }
    }

    public function testPhpsDefaultEscapeCorruptsATrailingBackslashWhichIsWhyWeDisableIt(): void
    {
        // Not testing our code — pinning the native behaviour ADR-0037 is about, so that a
        // future PHP changing it makes this test say so rather than leaving the workaround
        // looking arbitrary.
        $backslash = chr(92);
        $row = ['ends with ' . $backslash, 'next'];

        $handle = fopen('php://memory', 'r+');
        self::assertIsResource($handle);
        fputcsv($handle, $row, ',', '"', $backslash);
        rewind($handle);
        $back = fgetcsv($handle, 0, ',', '"', $backslash);
        fclose($handle);

        // Two fields went in; one came back, having swallowed the delimiter and the newline.
        self::assertIsArray($back);
        self::assertCount(1, $back);
        self::assertNotSame($row, $back);
    }

    public function testManyRowsRoundTripInOrder(): void
    {
        $rows = [];
        for ($i = 0; $i < 200; $i++) {
            $rows[] = ["id-{$i}", "value, with comma {$i}", "quote \" {$i}"];
        }

        Csv::write($this->path(), $rows);

        self::assertSame($rows, iterator_to_array(Csv::read($this->path())));
    }

    public function testNumericsComeBackAsStringsWhichIsWhatCsvIs(): void
    {
        // CSV is untyped; the reader cannot know 42 was an int. Stated as a test so nobody
        // expects otherwise.
        Csv::write($this->path(), [[42, 1.5, true, false, null]]);

        self::assertSame([['42', '1.5', '1', '', '']], iterator_to_array(Csv::read($this->path())));
    }
}
