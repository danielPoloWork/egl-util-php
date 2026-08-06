<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\Csv;
use D4np\Utils\Support\CsvException;
use D4np\Utils\Support\CsvSerializable;
use D4np\Utils\Support\Delimiter;
use D4np\Utils\Support\FileException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `Csv`'s contract beyond fidelity: streaming, atomicity, blank lines, the
 * {@see CsvSerializable} pairing, and the failure paths that must throw rather than return
 * `false` (spec r3 FR-28/FR-29, RFC-0002).
 */
final class CsvTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/egl-utils-csvio-' . bin2hex(random_bytes(8));
        if (!mkdir($this->dir) && !is_dir($this->dir)) {
            self::fail('could not create the test directory');
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $entry) {
            if (is_file($entry)) {
                @unlink($entry);
            }
        }
        @rmdir($this->dir);
    }

    private function path(string $name = 'out.csv'): string
    {
        return $this->dir . '/' . $name;
    }

    public function testWriteReturnsTheDataRowCount(): void
    {
        self::assertSame(3, Csv::write($this->path(), [['a'], ['b'], ['c']]));
    }

    public function testWriteAcceptsAGeneratorSoTheTableIsNeverBuffered(): void
    {
        $rows = (static function (): iterable {
            for ($i = 0; $i < 5; $i++) {
                yield [$i, "row-{$i}"];
            }
        })();

        self::assertSame(5, Csv::write($this->path(), $rows));
        self::assertCount(5, iterator_to_array(Csv::read($this->path())));
    }

    public function testDelimiterIsHonouredOnTheWire(): void
    {
        Csv::write($this->path(), [['a', 'b']], Delimiter::Semicolon);

        self::assertSame("a;b\n", file_get_contents($this->path()));
    }

    public function testTabDelimitedFieldsAreQuotedNotSplit(): void
    {
        Csv::write($this->path(), [["has\ttab", 'x']], Delimiter::Comma);

        self::assertSame([["has\ttab", 'x']], iterator_to_array(Csv::read($this->path())));
    }

    public function testReadSkipsEntirelyBlankLines(): void
    {
        file_put_contents($this->path(), "a,b\n\nc,d\n\n");

        self::assertSame([['a', 'b'], ['c', 'd']], iterator_to_array(Csv::read($this->path())));
    }

    public function testAQuotedEmptyFieldIsARealRowAndIsNotSkipped(): void
    {
        // The distinction the blank-line skip must not blur: "" is a row holding one empty
        // field; a blank line is not a row at all.
        file_put_contents($this->path(), "a\n\"\"\nb\n");

        self::assertSame([['a'], [''], ['b']], iterator_to_array(Csv::read($this->path())));
    }

    public function testReadIsAGeneratorSoNothingIsReadBeforeItIsAskedFor(): void
    {
        Csv::write($this->path(), [['a'], ['b'], ['c']]);

        $rows = Csv::read($this->path());
        $first = $rows->current();

        self::assertSame(['a'], $first);
    }

    public function testWriteIsAtomicSoAFailedWriteLeavesThePreviousFileIntact(): void
    {
        Csv::write($this->path(), [['original']]);

        $exploding = (static function (): iterable {
            yield ['new'];

            throw new RuntimeException('producer failed mid-stream');
        })();

        try {
            Csv::write($this->path(), $exploding);
            self::fail('the producer should have thrown');
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame("original\n", file_get_contents($this->path()));
    }

    public function testAFailedWriteLeavesNoTemporaryFileBehind(): void
    {
        $exploding = (static function (): iterable {
            yield ['x'];

            throw new RuntimeException('boom');
        })();

        try {
            Csv::write($this->path(), $exploding);
        } catch (RuntimeException) {
            // expected
        }

        $leftovers = array_filter(
            glob($this->dir . '/*') ?: [],
            static fn (string $p): bool => !str_ends_with($p, '.csv') && !str_ends_with($p, '.lock'),
        );

        self::assertSame([], array_values($leftovers));
    }

    public function testWritingToAMissingDirectoryThrowsFileException(): void
    {
        $this->expectException(FileException::class);

        Csv::write($this->dir . '/nope/out.csv', [['a']]);
    }

    public function testReadingAMissingFileThrowsFileException(): void
    {
        $this->expectException(FileException::class);
        $this->expectExceptionMessage('not a file');

        iterator_to_array(Csv::read($this->path('absent.csv')));
    }

    public function testWritingAnEmptyIterableProducesAnEmptyFile(): void
    {
        self::assertSame(0, Csv::write($this->path(), []));
        self::assertSame('', file_get_contents($this->path()));
        self::assertSame([], iterator_to_array(Csv::read($this->path())));
    }

    public function testSerializableEmitsItsHeaderOnceThenEveryRow(): void
    {
        $rows = [
            new CsvRowFixture('1', 'first'),
            new CsvRowFixture('2', 'second'),
        ];

        self::assertSame(2, Csv::write($this->path(), $rows));
        self::assertSame(
            [['id', 'label'], ['1', 'first'], ['2', 'second']],
            iterator_to_array(Csv::read($this->path())),
        );
    }

    public function testSerializableWidthMismatchIsRefusedNamingBothCounts(): void
    {
        // The interface's prose plea ("maintain strict consistency") turned into a mechanism.
        $rows = [
            new CsvRowFixture('1', 'first'),
            new CsvRaggedFixture(),
        ];

        $this->expectException(CsvException::class);
        $this->expectExceptionMessage('produced 3 value(s) for a 2-column header');

        Csv::write($this->path(), $rows);
    }

    public function testAPlainArrayRowIsWrittenAsGivenWithoutAHeader(): void
    {
        Csv::write($this->path(), [['a', 'b']]);

        self::assertSame([['a', 'b']], iterator_to_array(Csv::read($this->path())));
    }

    public function testASingleEmptyFieldSurvivesInsteadOfVanishing(): void
    {
        // Measured: fputcsv() writes [''] as a bare newline, which reads back as a blank
        // line and disappears — a silently lost row. The explicit empty-field form is
        // written instead.
        Csv::write($this->path(), [['a'], [''], ['b']]);

        self::assertSame("a\n\"\"\nb\n", file_get_contents($this->path()));
        self::assertSame([['a'], [''], ['b']], iterator_to_array(Csv::read($this->path())));
    }

    public function testASingleNullFieldIsTheSameCase(): void
    {
        Csv::write($this->path(), [[null]]);

        self::assertSame([['']], iterator_to_array(Csv::read($this->path())));
    }

    public function testFputcsvCannotExpressASingleEmptyFieldWhichIsWhyWeWriteItOurselves(): void
    {
        // Pinning the native behaviour the special case exists for.
        $handle = fopen('php://memory', 'r+');
        self::assertIsResource($handle);
        fputcsv($handle, [''], ',', '"', '');
        rewind($handle);
        $raw = stream_get_contents($handle);
        fclose($handle);

        self::assertSame("\n", $raw, 'fputcsv() emits a bare newline for a single empty field');
    }

    public function testAZeroColumnRowIsRefusedRatherThanWrittenAsABlankLine(): void
    {
        $this->expectException(CsvException::class);
        $this->expectExceptionMessage('at least one field');

        Csv::write($this->path(), [[]]);
    }
}

final class CsvRowFixture implements CsvSerializable
{
    public function __construct(
        private readonly string $id,
        private readonly string $label,
    ) {
    }

    public function csvHeader(): array
    {
        return ['id', 'label'];
    }

    public function csvRow(): array
    {
        return [$this->id, $this->label];
    }
}

final class CsvRaggedFixture implements CsvSerializable
{
    public function csvHeader(): array
    {
        return ['id', 'label'];
    }

    public function csvRow(): array
    {
        return ['1', 'two', 'three'];
    }
}
