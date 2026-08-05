<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Str::nullIfBlank()` — spec r3 FR-31 (RFC-0002).
 *
 * Two claims, tested separately: blank collapses to `null`, and non-blank comes back
 * **unmodified** — the method answers "is there content?" and never trims, so it composes
 * with `collapseWhitespace()` without double-mutation.
 */
final class StrNullIfBlankTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null}>
     */
    public static function blankCases(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'spaces only' => ['   '];
        yield 'mixed ASCII whitespace only' => [" \t\r\n "];
    }

    #[DataProvider('blankCases')]
    public function testBlankBecomesNull(?string $input): void
    {
        self::assertNull(Str::nullIfBlank($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function contentCases(): iterable
    {
        yield 'plain word' => ['x'];
        yield 'zero is content' => ['0'];
        yield 'content with surrounding whitespace stays untrimmed' => ['  padded  '];
        yield 'multibyte content' => ['café'];
        yield 'NBSP counts as content (ASCII blankness scope)' => ["\u{00A0}"];
    }

    #[DataProvider('contentCases')]
    public function testContentComesBackUnmodified(string $input): void
    {
        self::assertSame($input, Str::nullIfBlank($input));
    }
}
