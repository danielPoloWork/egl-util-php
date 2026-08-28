<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Str::mask()` — spec r30 FR-56 (RFC-0004, roadmap item 15.1).
 *
 * The masked segment has a fixed length regardless of how much was actually hidden — the
 * property under test throughout: two values that differ only in length must produce masked
 * outputs of the SAME length.
 */
final class StrMaskTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, int, string, int, string}>
     */
    public static function maskCases(): iterable
    {
        yield 'card number, keep last 4' => ['4111111111111111', 0, 4, '*', 4, '****1111'];
        yield 'email local part, keep first 1' => ['mpolo@example.com', 1, 0, '*', 3, 'm***'];
        yield 'keep both ends' => ['secretvalue', 2, 2, '*', 4, 'se****ue'];
        yield 'custom mask character' => ['topsecret', 0, 3, '#', 2, '##ret'];
        yield 'maskLength zero yields no visible mask' => ['abcdef', 1, 1, '*', 0, 'af'];
        yield 'multibyte value, keep last 2' => ['pässwörd', 0, 2, '*', 4, '****rd'];
        yield 'multibyte mask character' => ['abcdef', 1, 1, '●', 2, 'a●●f'];
    }

    #[DataProvider('maskCases')]
    public function testMask(
        string $value,
        int $keepStart,
        int $keepEnd,
        string $maskChar,
        int $maskLength,
        string $expected,
    ): void {
        self::assertSame($expected, Str::mask($value, $keepStart, $keepEnd, $maskChar, $maskLength));
    }

    public function testDefaultsKeepNothingAndUseFourAsterisks(): void
    {
        self::assertSame('****', Str::mask('secret'));
    }

    /**
     * The load-bearing property: the SAME maskLength produces the SAME output length
     * regardless of how long the hidden middle actually was.
     */
    public function testOutputLengthIsIndependentOfHiddenLength(): void
    {
        $short = Str::mask('1234', 0, 0, '*', 4);
        $long = Str::mask('123456789012', 0, 0, '*', 4);

        self::assertSame(\mb_strlen($short), \mb_strlen($long));
        self::assertSame('****', $short);
        self::assertSame('****', $long);
    }

    public function testRefusesWhenNothingWouldBeMasked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('leaves nothing to mask in a 4-character value');

        Str::mask('1234', 2, 2);
    }

    public function testRefusalMessageNeverContainsTheValue(): void
    {
        try {
            Str::mask('super-secret-token', 20, 0);
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            self::assertStringNotContainsString('super-secret-token', $e->getMessage());
        }
    }

    public function testRefusesEmptyValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::mask('');
    }

    public function testRefusesNegativeKeepStart(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::mask('value', -1);
    }

    public function testRefusesNegativeKeepEnd(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::mask('value', 0, -1);
    }

    public function testRefusesNegativeMaskLength(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::mask('value', 0, 0, '*', -1);
    }

    public function testRefusesMultiCharacterMaskChar(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::mask('value', 0, 0, '**');
    }

    public function testRefusesEmptyMaskChar(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::mask('value', 0, 0, '');
    }

    public function testRefusesInvalidUtf8Value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::mask("\xB1\x31", 0, 0);
    }
}
