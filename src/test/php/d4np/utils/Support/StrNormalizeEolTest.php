<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\Eol;
use D4np\Utils\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Str::normalizeEol()` — spec r31 FR-57 (RFC-0004, roadmap item 15.2).
 *
 * All three endings a text file can carry — CRLF, lone CR, LF — collapse to LF first, in that
 * order, before expanding to the target: the order is what keeps a Windows line ending from
 * leaving a stray LF behind.
 */
final class StrNormalizeEolTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?Eol, string}>
     */
    public static function cases(): iterable
    {
        yield 'CRLF to LF' => ["a\r\nb", Eol::Lf, "a\nb"];
        yield 'lone CR to LF' => ["a\rb", Eol::Lf, "a\nb"];
        yield 'LF to LF is a no-op' => ["a\nb", Eol::Lf, "a\nb"];
        yield 'LF to CRLF' => ["a\nb", Eol::CrLf, "a\r\nb"];
        yield 'lone CR to CRLF' => ["a\rb", Eol::CrLf, "a\r\nb"];
        yield 'CRLF to CRLF is a no-op' => ["a\r\nb", Eol::CrLf, "a\r\nb"];
        yield 'mixed endings, all three in one input, target LF' => [
            "one\r\ntwo\rthree\nfour", Eol::Lf, "one\ntwo\nthree\nfour",
        ];
        yield 'mixed endings, target CRLF' => [
            "one\r\ntwo\rthree\nfour", Eol::CrLf, "one\r\ntwo\r\nthree\r\nfour",
        ];
        yield 'no endings at all is unchanged' => ['no newlines here', Eol::CrLf, 'no newlines here'];
        yield 'empty string' => ['', Eol::Lf, ''];
        yield 'default target is LF' => ["a\r\nb", null, "a\nb"];
    }

    #[DataProvider('cases')]
    public function testNormalizeEol(string $value, ?Eol $target, string $expected): void
    {
        $result = $target === null ? Str::normalizeEol($value) : Str::normalizeEol($value, $target);

        self::assertSame($expected, $result);
    }

    /**
     * @return iterable<string, array{string, Eol}>
     */
    public static function idempotenceCases(): iterable
    {
        yield 'to LF' => ["one\r\ntwo\rthree\nfour", Eol::Lf];
        yield 'to CRLF' => ["one\r\ntwo\rthree\nfour", Eol::CrLf];
    }

    #[DataProvider('idempotenceCases')]
    public function testIsIdempotent(string $value, Eol $target): void
    {
        $once = Str::normalizeEol($value, $target);
        $twice = Str::normalizeEol($once, $target);

        self::assertSame($once, $twice);
    }

    public function testCrlfNeverLeavesAStrayLf(): void
    {
        $result = Str::normalizeEol("a\r\nb\r\nc", Eol::CrLf);

        self::assertSame("a\r\nb\r\nc", $result);
        self::assertSame(2, \substr_count($result, "\r\n"));
        self::assertSame(0, \substr_count(\str_replace("\r\n", '', $result), "\n"));
    }
}
