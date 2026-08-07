<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** `Str::random()` — spec §2 item 21: a CSPRNG alphanumeric token. */
final class StrRandomTest extends TestCase
{
    public function testDefaultLengthIsThirtyTwo(): void
    {
        self::assertSame(32, \strlen(Str::random()));
    }

    public function testRespectsACustomLength(): void
    {
        self::assertSame(0, \strlen(Str::random(0)));
        self::assertSame(1, \strlen(Str::random(1)));
        self::assertSame(64, \strlen(Str::random(64)));
    }

    public function testEveryCharacterComesFromTheDefaultAlphabet(): void
    {
        $token = Str::random(500);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $token);
    }

    public function testRespectsACustomAlphabet(): void
    {
        $token = Str::random(200, 'ab');

        self::assertMatchesRegularExpression('/^[ab]+$/', $token);
        // Long enough that both characters of a 2-symbol alphabet appearing is a near
        // certainty (p(all-same) = 2 * 0.5^200), so this also catches a generator that always
        // picks index 0.
        self::assertStringContainsString('a', $token);
        self::assertStringContainsString('b', $token);
    }

    public function testSuccessiveTokensAreDistinct(): void
    {
        $tokens = [];
        for ($i = 0; $i < 200; $i++) {
            $tokens[] = Str::random();
        }

        self::assertCount(200, \array_unique($tokens));
    }

    public function testNegativeLengthThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('$length must be >= 0');

        Str::random(-1);
    }

    public function testTooShortAlphabetThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('$alphabet must contain at least two characters');

        Str::random(10, 'x');
    }

    public function testEmptyAlphabetThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::random(10, '');
    }
}
