<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\Str;
use D4np\Utils\Support\UtilsException;
use PHPUnit\Framework\TestCase;

/**
 * `Str::transcode()` — spec r3 FR-31 (RFC-0002): strict by default, lossy by explicit opt-in.
 *
 * The legacy pipeline this generalizes ran `iconv(..., '...//IGNORE', ...)` unconditionally —
 * silent data loss on every unrepresentable character. The contract under test is the
 * inversion: loss **throws** unless the caller asked for it in the signature.
 *
 * The ext-iconv-absent refusal branch is NOT covered here: this suite's environment has
 * iconv loaded and `function_exists()` cannot be unloaded in-process. The branch was
 * probe-verified during development (the `Sanitizer::richText()` precedent, ADR-0021 — its
 * missing-dependency path has the same standing gap, recorded rather than faked).
 */
final class StrTranscodeTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('iconv')) {
            self::markTestSkipped('ext-iconv is not loaded in this environment.');
        }
    }

    public function testLegacyLatin9RoundTripsToUtf8(): void
    {
        // 0xA4 is the euro sign in ISO-8859-15 — the surveyed estate's exact daily case.
        self::assertSame('€', Str::transcode("\xA4", 'ISO-8859-15'));
    }

    public function testAsciiPassesThroughAnyPair(): void
    {
        self::assertSame('plain', Str::transcode('plain', 'ISO-8859-1', 'UTF-8'));
    }

    public function testStrictThrowsOnUnrepresentableTarget(): void
    {
        $this->expectException(UtilsException::class);
        $this->expectExceptionMessage('not losslessly convertible');

        // The euro sign has no ISO-8859-1 codepoint; strict mode must refuse, not drop.
        Str::transcode('price: 5€', 'UTF-8', 'ISO-8859-1');
    }

    public function testStrictThrowsOnInvalidInputBytes(): void
    {
        $this->expectException(UtilsException::class);
        $this->expectExceptionMessage('not losslessly convertible');

        // 0xC3 0x28 is a malformed UTF-8 sequence (lead byte with an invalid continuation).
        Str::transcode("\xC3\x28", 'UTF-8', 'ISO-8859-1');
    }

    public function testLossyDropsUnrepresentableCharactersOnExplicitOptIn(): void
    {
        self::assertSame('price: 5', Str::transcode('price: 5€', 'UTF-8', 'ISO-8859-1', lossy: true));
    }

    public function testUnknownEncodingIsNamedDistinctlyFromDataFailures(): void
    {
        $this->expectException(UtilsException::class);
        $this->expectExceptionMessage('unknown encoding');

        Str::transcode('x', 'NOT-A-CHARSET');
    }

    public function testEmptyStringTranscodesToEmptyString(): void
    {
        self::assertSame('', Str::transcode('', 'ISO-8859-15'));
    }
}
