<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Str::length()` / `Str::substr()` — spec r31 FR-57 (RFC-0004, roadmap item 15.2).
 *
 * Counted and sliced in Unicode code points, not bytes — the corpus deliberately spans ASCII,
 * 2/3/4-byte sequences, and a combining mark, since item 12.4's width lesson is that a
 * single-width corpus cannot expose a boundary computed in a different width.
 */
final class StrLengthSubstrTest extends TestCase
{
    /**
     * `é` (U+00E9, 2 bytes), `€` (U+20AC, 3 bytes), `𝄞` (U+1D11E, the musical G-clef, 4 bytes),
     * and `é` written as `e` + a combining acute accent (U+0301) — two code points, three bytes.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function lengthCases(): iterable
    {
        yield 'empty' => ['', 0];
        yield 'ascii' => ['hello', 5];
        yield '2-byte sequence' => ['café', 4];
        yield '3-byte sequence' => ['€100', 4];
        yield '4-byte sequence' => ['a𝄞b', 3];
        yield 'combining mark counts as two code points' => ["e\u{0301}", 2];
        yield 'mixed widths' => ['café€𝄞', 6];
    }

    #[DataProvider('lengthCases')]
    public function testLength(string $value, int $expected): void
    {
        self::assertSame($expected, Str::length($value));
    }

    public function testLengthRefusesInvalidUtf8(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::length("\xB1\x31");
    }

    /**
     * @return iterable<string, array{string, int, ?int, string}>
     */
    public static function substrCases(): iterable
    {
        yield 'from start, no length' => ['hello', 0, null, 'hello'];
        yield 'positive start' => ['hello', 1, null, 'ello'];
        yield 'positive start and length' => ['hello', 1, 3, 'ell'];
        yield 'negative start' => ['hello', -3, null, 'llo'];
        yield 'negative length' => ['hello', 1, -1, 'ell'];
        yield 'negative start and negative length' => ['hello', -4, -1, 'ell'];
        yield 'start beyond length' => ['hello', 10, null, ''];
        yield 'zero length' => ['hello', 1, 0, ''];
        yield 'multibyte, by code point not byte' => ['café menu', 0, 4, 'café'];
        yield 'multibyte, negative start' => ['café menu', -4, null, 'menu'];
        yield 'four-byte sequence sliced whole' => ['a𝄞b', 1, 1, '𝄞'];
    }

    #[DataProvider('substrCases')]
    public function testSubstr(string $value, int $start, ?int $length, string $expected): void
    {
        self::assertSame($expected, Str::substr($value, $start, $length));
    }

    public function testSubstrRefusesInvalidUtf8(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::substr("\xB1\x31", 0);
    }

    /**
     * `Str::substr()` must agree with native `substr()` on a pure-ASCII corpus — the byte and
     * code-point counts coincide there, so any divergence would be a genuine defect, not a
     * width artifact.
     */
    public function testAgreesWithNativeSubstrOnAscii(): void
    {
        $value = 'the quick brown fox';

        foreach ([[0, null], [4, null], [-3, null], [2, 5], [2, -2], [-10, 4]] as [$start, $length]) {
            self::assertSame(
                \substr($value, $start, $length),
                Str::substr($value, $start, $length),
                \sprintf('mismatch at start=%d, length=%s', $start, $length === null ? 'null' : (string) $length),
            );
        }
    }
}
