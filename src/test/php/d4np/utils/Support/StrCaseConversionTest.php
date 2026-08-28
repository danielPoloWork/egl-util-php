<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Str::snakeCase()` / `Str::camelCase()` — spec r30 FR-56 (RFC-0004, roadmap item 15.1).
 *
 * Both share one word-splitting engine ({@see \D4np\Utils\Support\Str}'s private
 * `splitWords()`), which is what makes the round-trip property below hold rather than being
 * two independently hand-written conversions that happen to usually agree.
 */
final class StrCaseConversionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function snakeCases(): iterable
    {
        yield 'space separated' => ['order line', 'order_line'];
        yield 'hyphen separated' => ['order-line', 'order_line'];
        yield 'already snake_case is idempotent' => ['order_line', 'order_line'];
        yield 'PascalCase input' => ['OrderLine', 'order_line'];
        yield 'camelCase input' => ['orderLine', 'order_line'];
        yield 'uppercase words' => ['ORDER LINE', 'order_line'];
        yield 'acronym run stays one word' => ['APIKey', 'api_key'];
        yield 'acronym run, three words' => ['XMLHttpRequest', 'xml_http_request'];
        yield 'digit stays with preceding letters' => ['line2Item', 'line2_item'];
        yield 'digit-only word' => ['plan2fa', 'plan2fa'];
        yield 'mixed separators and runs' => ['order -_ line  item', 'order_line_item'];
        yield 'surrounding separators trimmed' => ['  _order line_  ', 'order_line'];
        yield 'single word' => ['order', 'order'];
        yield 'empty stays empty' => ['', ''];
        yield 'multibyte passes through unmangled' => ['café menu', 'café_menu'];
    }

    #[DataProvider('snakeCases')]
    public function testSnakeCase(string $input, string $expected): void
    {
        self::assertSame($expected, Str::snakeCase($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function camelCases(): iterable
    {
        yield 'snake_case input' => ['order_line', 'orderLine'];
        yield 'space separated' => ['order line', 'orderLine'];
        yield 'hyphen separated' => ['order-line', 'orderLine'];
        yield 'already camelCase is idempotent' => ['orderLine', 'orderLine'];
        yield 'PascalCase input becomes camelCase' => ['OrderLine', 'orderLine'];
        yield 'acronym-leading word lowercases as a whole' => ['api_key', 'apiKey'];
        yield 'single word' => ['order', 'order'];
        yield 'empty stays empty' => ['', ''];
        yield 'three words' => ['xml_http_request', 'xmlHttpRequest'];
    }

    #[DataProvider('camelCases')]
    public function testCamelCase(string $input, string $expected): void
    {
        self::assertSame($expected, Str::camelCase($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function alreadyCamelCaseCorpus(): iterable
    {
        yield 'simple' => ['orderLine'];
        yield 'three words' => ['isActiveUser'];
        yield 'single word' => ['order'];
        yield 'trailing digit' => ['version2'];
    }

    /**
     * The round trip a `#[MapFrom]` convention (roadmap item 15.4) needs to be able to rely
     * on — proven here, not merely asserted, so a future change to either method that breaks
     * the pairing fails a test instead of surfacing as a silently wrong column mapping.
     */
    #[DataProvider('alreadyCamelCaseCorpus')]
    public function testCamelCaseInvertsSnakeCaseForAlreadyCamelInput(string $value): void
    {
        self::assertSame($value, Str::camelCase(Str::snakeCase($value)));
    }

    public function testSnakeCaseIsIdempotent(): void
    {
        $once = Str::snakeCase('OrderLine Item');

        self::assertSame($once, Str::snakeCase($once));
    }
}
