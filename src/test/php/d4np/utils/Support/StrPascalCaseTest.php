<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Str::pascalCase()` — spec r3 FR-31 (RFC-0002).
 *
 * Word boundaries are whitespace, underscores, and hyphens; case mapping is ASCII-only with
 * multibyte characters passing through un-mangled (half-mapping bytes would corrupt them).
 */
final class StrPascalCaseTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pascalCases(): iterable
    {
        yield 'space separated' => ['order line', 'OrderLine'];
        yield 'underscore separated' => ['order_line', 'OrderLine'];
        yield 'hyphen separated' => ['order-line', 'OrderLine'];
        yield 'mixed separators and runs' => ['order -_ line  item', 'OrderLineItem'];
        yield 'uppercase input is normalized' => ['ORDER LINE', 'OrderLine'];
        yield 'single word' => ['order', 'Order'];
        yield 'already PascalCase is one word, first letter kept upper' => ['OrderLine', 'Orderline'];
        yield 'digits ride along' => ['line 2 item', 'Line2Item'];
        yield 'surrounding separators trimmed' => ['  _order line_  ', 'OrderLine'];
        yield 'empty stays empty' => ['', ''];
        yield 'multibyte passes through unmangled' => ['café menu', 'CaféMenu'];
    }

    #[DataProvider('pascalCases')]
    public function testPascalCase(string $input, string $expected): void
    {
        self::assertSame($expected, Str::pascalCase($input));
    }

    public function testDeliberatelyNotIdempotent(): void
    {
        // Documented, not accidental: an already-PascalCase input has no separators, so it is
        // ONE word — strtolower flattens its internals before ucwords recapitalizes the first
        // letter. Re-running the function is therefore not a no-op, and the provider's
        // 'already PascalCase' case pins the same fact from the other side.
        self::assertSame('Orderlineitem', Str::pascalCase(Str::pascalCase('order line item')));
    }
}
