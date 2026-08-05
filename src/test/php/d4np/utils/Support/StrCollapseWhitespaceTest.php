<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Str::collapseWhitespace()` — spec r3 FR-31 (RFC-0002).
 *
 * The load-bearing claims: every ASCII whitespace run becomes one space, the ends are
 * trimmed, and multibyte content is never split — the ASCII-set match cannot touch UTF-8
 * continuation bytes, which is why the method needs no `u` flag and no mbstring.
 */
final class StrCollapseWhitespaceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function collapseCases(): iterable
    {
        yield 'already collapsed' => ['a b c', 'a b c'];
        yield 'internal runs of spaces' => ['a    b     c', 'a b c'];
        yield 'tabs and newlines collapse to one space' => ["a\t\tb\r\nc", 'a b c'];
        yield 'leading and trailing whitespace trimmed' => ["  \t a b \n ", 'a b'];
        yield 'mixed run collapses to a single space' => ["a \t \n b", 'a b'];
        yield 'empty string stays empty' => ['', ''];
        yield 'whitespace-only becomes empty' => [" \t\r\n ", ''];
        yield 'multibyte content preserved intact' => ["  café \t naïve  ", 'café naïve'];
        yield 'NBSP is content, not whitespace (documented ASCII scope)' => ["a\u{00A0}b", "a\u{00A0}b"];
    }

    #[DataProvider('collapseCases')]
    public function testCollapse(string $input, string $expected): void
    {
        self::assertSame($expected, Str::collapseWhitespace($input));
    }

    public function testIdempotent(): void
    {
        $once = Str::collapseWhitespace("  a \t b\n\nc  ");

        self::assertSame($once, Str::collapseWhitespace($once));
    }
}
