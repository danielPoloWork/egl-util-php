<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\Str;
use PHPUnit\Framework\TestCase;

/** `Str::uuid()` — spec §2 item 20: a v4 UUID from `random_bytes()`. */
final class StrUuidTest extends TestCase
{
    public function testFormatMatchesRfc4122VariantV4(): void
    {
        // Version nibble literally '4'; variant nibble one of 8/9/a/b (the '10xx' bit pattern).
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            Str::uuid(),
        );
    }

    public function testIsThirtySixCharactersWithHyphensAtFixedPositions(): void
    {
        $uuid = Str::uuid();

        self::assertSame(36, strlen($uuid));
        self::assertSame('-', $uuid[8]);
        self::assertSame('-', $uuid[13]);
        self::assertSame('-', $uuid[18]);
        self::assertSame('-', $uuid[23]);
    }

    public function testSuccessiveCallsAreDistinct(): void
    {
        // A collision among 2000 v4 UUIDs (122 bits of entropy each) is not a realistic false
        // positive for this assertion; it is a statement about the generator being wired to a
        // real CSPRNG, not a birthday-bound probability claim.
        $count = 2000;
        $uuids = [];
        for ($i = 0; $i < $count; $i++) {
            $uuids[] = Str::uuid();
        }

        self::assertCount($count, array_unique($uuids));
    }
}
