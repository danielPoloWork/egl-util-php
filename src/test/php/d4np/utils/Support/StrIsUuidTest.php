<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Str::isUuid()` — spec r30 FR-56 (RFC-0004, roadmap item 15.1).
 *
 * A predicate over RFC 9562's 8-4-4-12 layout, the version digit, and the RFC 4122 variant
 * bits — never a sanitizer, never throws on a malformed value under test.
 */
final class StrIsUuidTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?int, bool}>
     */
    public static function cases(): iterable
    {
        yield 'valid v4, no version pin' => ['f47ac10b-58cc-4372-a567-0e02b2c3d479', null, true];
        yield 'valid v4, correct version pin' => ['f47ac10b-58cc-4372-a567-0e02b2c3d479', 4, true];
        yield 'valid v4, wrong version pin' => ['f47ac10b-58cc-4372-a567-0e02b2c3d479', 7, false];
        yield 'valid v7, no version pin' => ['017f22e2-79b0-7cc3-98c4-dc0c0c07398f', null, true];
        yield 'valid v7, correct version pin' => ['017f22e2-79b0-7cc3-98c4-dc0c0c07398f', 7, true];
        yield 'valid v1' => ['6ba7b810-9dad-11d1-80b4-00c04fd430c8', 1, true];
        yield 'uppercase hex is accepted' => ['F47AC10B-58CC-4372-A567-0E02B2C3D479', 4, true];
        yield 'variant b is accepted' => ['f47ac10b-58cc-4372-b567-0e02b2c3d479', 4, true];
        yield 'nil UUID is rejected' => ['00000000-0000-0000-0000-000000000000', null, false];
        yield 'max UUID is rejected' => ['ffffffff-ffff-ffff-ffff-ffffffffffff', null, false];
        yield 'version 0 is rejected' => ['f47ac10b-58cc-0372-a567-0e02b2c3d479', null, false];
        yield 'version 9 is rejected' => ['f47ac10b-58cc-9372-a567-0e02b2c3d479', null, false];
        yield 'wrong variant is rejected' => ['f47ac10b-58cc-4372-1567-0e02b2c3d479', null, false];
        yield 'missing hyphen is rejected' => ['f47ac10b58cc-4372-a567-0e02b2c3d479', null, false];
        yield 'wrong length is rejected' => ['f47ac10b-58cc-4372-a567-0e02b2c3d47', null, false];
        yield 'non-hex character is rejected' => ['g47ac10b-58cc-4372-a567-0e02b2c3d479', null, false];
        yield 'empty string is rejected' => ['', null, false];
        yield 'a ULID is not a UUID' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV', null, false];
    }

    #[DataProvider('cases')]
    public function testIsUuid(string $value, ?int $version, bool $expected): void
    {
        self::assertSame($expected, Str::isUuid($value, $version));
    }

    public function testOwnUuidGeneratorAlwaysValidatesAsV4(): void
    {
        self::assertTrue(Str::isUuid(Str::uuid(), 4));
    }

    public function testRefusesVersionBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::isUuid('f47ac10b-58cc-4372-a567-0e02b2c3d479', 0);
    }

    public function testRefusesVersionAboveEight(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::isUuid('f47ac10b-58cc-4372-a567-0e02b2c3d479', 9);
    }
}
