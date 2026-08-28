<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Str::isUlid()` — spec r30 FR-56 (RFC-0004, roadmap item 15.1).
 *
 * **The overflow boundary is the property under test**: alphabet- and length-correct is not
 * enough — the first character must additionally be `0`–`7`, the range
 * {@see \D4np\Utils\Support\Str::ulid()}'s own 48-bit timestamp can ever produce.
 */
final class StrIsUlidTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function cases(): iterable
    {
        yield 'the ULID specification\'s own worked example' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV', true];
        yield 'largest well-formed ULID' => ['7ZZZZZZZZZZZZZZZZZZZZZZZZZ', true];
        yield 'lowercase is accepted' => ['01arz3ndektsv4rrffq69g5fav', true];
        yield 'first character 8 overflows' => ['8ZZZZZZZZZZZZZZZZZZZZZZZZZ', false];
        yield 'first character 9 overflows' => ['9ZZZZZZZZZZZZZZZZZZZZZZZZZ', false];
        yield 'first character A overflows' => ['AZZZZZZZZZZZZZZZZZZZZZZZZZ', false];
        yield 'too short' => ['01ARZ3NDEKTSV4RRFFQ69G5FA', false];
        yield 'too long' => ['01ARZ3NDEKTSV4RRFFQ69G5FAVX', false];
        yield 'excluded letter I' => ['0IARZ3NDEKTSV4RRFFQ69G5FAV', false];
        yield 'excluded letter L' => ['0LARZ3NDEKTSV4RRFFQ69G5FAV', false];
        yield 'excluded letter O' => ['0OARZ3NDEKTSV4RRFFQ69G5FAV', false];
        yield 'excluded letter U' => ['0UARZ3NDEKTSV4RRFFQ69G5FAV', false];
        yield 'a UUID is not a ULID' => ['f47ac10b-58cc-4372-a567-0e02b2c3d479', false];
        yield 'empty string' => ['', false];
    }

    #[DataProvider('cases')]
    public function testIsUlid(string $value, bool $expected): void
    {
        self::assertSame($expected, Str::isUlid($value));
    }

    public function testOwnUlidGeneratorAlwaysValidates(): void
    {
        self::assertTrue(Str::isUlid(Str::ulid()));
    }
}
