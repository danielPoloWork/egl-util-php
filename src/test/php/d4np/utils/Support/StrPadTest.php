<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Str::padLeft()` / `Str::padRight()` — spec r3 FR-31 (RFC-0002).
 *
 * The reason these exist: `str_pad()` counts **bytes**, so multibyte strings under-pad —
 * exactly the defect the first data-provider case pins. Semantics follow PHP 8.3's
 * `mb_str_pad()` (at-or-below length is a no-op; empty pad refused) so a consumer on a newer
 * floor can migrate to the native function without behavior change.
 */
final class StrPadTest extends TestCase
{
    public function testStrPadUnderPadsMultibyteWhichIsWhyThisExists(): void
    {
        // 'héllo' is 5 characters but 6 bytes. Asked for 7, str_pad() counts bytes: it emits
        // 7 BYTES — which render as only 6 characters, one short. padLeft() emits 7 characters.
        $native = str_pad('héllo', 7);

        self::assertSame(7, strlen($native));
        self::assertSame(6, preg_match_all('/./su', $native));
        self::assertSame('  héllo', Str::padLeft('héllo', 7));
        self::assertSame(7, preg_match_all('/./su', Str::padLeft('héllo', 7)));
    }

    /**
     * @return iterable<string, array{string, int, string, string}>
     */
    public static function padLeftCases(): iterable
    {
        yield 'ascii' => ['42', 5, '0', '00042'];
        yield 'multibyte value counts code points' => ['héllo', 7, ' ', '  héllo'];
        yield 'multibyte pad slices by code points' => ['x', 4, 'ãb', 'ãbãx'];
        yield 'length equal to value is a no-op' => ['abc', 3, '0', 'abc'];
        yield 'length below value is a no-op' => ['abc', 2, '0', 'abc'];
        yield 'negative length is a no-op' => ['abc', -1, '0', 'abc'];
        yield 'empty value pads fully' => ['', 3, 'ab', 'aba'];
    }

    #[DataProvider('padLeftCases')]
    public function testPadLeft(string $value, int $length, string $pad, string $expected): void
    {
        self::assertSame($expected, Str::padLeft($value, $length, $pad));
    }

    /**
     * @return iterable<string, array{string, int, string, string}>
     */
    public static function padRightCases(): iterable
    {
        yield 'ascii' => ['42', 5, '0', '42000'];
        yield 'multibyte value counts code points' => ['héllo', 7, ' ', 'héllo  '];
        yield 'multibyte pad slices by code points' => ['x', 4, 'ãb', 'xãbã'];
        yield 'no-op at length' => ['abc', 3, '0', 'abc'];
    }

    #[DataProvider('padRightCases')]
    public function testPadRight(string $value, int $length, string $pad, string $expected): void
    {
        self::assertSame($expected, Str::padRight($value, $length, $pad));
    }

    public function testEmptyPadIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('$pad must not be empty');

        Str::padLeft('x', 5, '');
    }

    public function testInvalidUtf8ValueIsRefusedNotMiscounted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('$value is not valid UTF-8');

        Str::padLeft("\xC3\x28", 5);
    }

    public function testInvalidUtf8PadIsRefusedWhenPaddingIsNeeded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('$pad is not valid UTF-8');

        Str::padRight('x', 5, "\xC3\x28");
    }
}
