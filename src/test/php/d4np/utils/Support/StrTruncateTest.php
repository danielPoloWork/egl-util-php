<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Str::truncate()` — spec r30 FR-56 (RFC-0004, roadmap item 15.1).
 *
 * The suffix is inside the budget: a truncated result is never longer than `$length`
 * characters including the suffix.
 */
final class StrTruncateTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, string, string}>
     */
    public static function truncateCases(): iterable
    {
        yield 'fits unchanged, no suffix added' => ['hi', 5, '…', 'hi'];
        yield 'exact length unchanged, no suffix added' => ['hello', 5, '…', 'hello'];
        yield 'truncates with default ellipsis' => ['hello world', 8, '…', 'hello w…'];
        yield 'truncates with custom suffix' => ['hello world', 8, '...', 'hello...'];
        yield 'length equals suffix length, all suffix' => ['hello', 3, '...', '...'];
        yield 'multibyte value truncated by code point, not byte' => ['pässwörter', 4, '…', 'päs…'];
        yield 'zero length with empty suffix' => ['hello', 0, '', ''];
    }

    #[DataProvider('truncateCases')]
    public function testTruncate(string $value, int $length, string $suffix, string $expected): void
    {
        self::assertSame($expected, Str::truncate($value, $length, $suffix));
    }

    public function testResultNeverExceedsLengthIncludingSuffix(): void
    {
        $result = Str::truncate('a much longer sentence than the budget allows', 10);

        self::assertLessThanOrEqual(10, \mb_strlen($result));
    }

    public function testDefaultSuffixIsEllipsis(): void
    {
        self::assertSame('hello w…', Str::truncate('hello world', 8));
    }

    public function testRefusesNegativeLength(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::truncate('hello', -1);
    }

    public function testRefusesLengthShorterThanSuffix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot fit a 3-character suffix');

        Str::truncate('hello world', 2, '...');
    }

    public function testRefusesInvalidUtf8Value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::truncate("\xB1\x31", 1);
    }

    /** Never lengthens: a value that fits comes back byte-identical, not padded. */
    public function testNeverLengthensAValueThatAlreadyFits(): void
    {
        $value = 'short';

        self::assertSame($value, Str::truncate($value, 100));
    }
}
