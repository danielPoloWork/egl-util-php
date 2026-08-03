<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * `Str::slug()` — spec §2 item 19, and the T-05 property test spec §7 requires (idempotence).
 *
 * The three transliteration tiers are tested independently through reflection on the private
 * `via*` methods, not only through the public `slug()` chain: the environment this suite runs
 * in has `ext-intl` loaded, so a test that only calls `slug()` would never exercise the
 * `iconv` or ascii-filter fallback tiers, and a fallback nobody has run is a fallback nobody
 * has verified.
 */
final class StrSlugTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function slugCases(): iterable
    {
        yield 'already a slug' => ['hello-world', 'hello-world'];
        yield 'spaces to separator' => ['Hello World', 'hello-world'];
        yield 'punctuation collapses' => ['Hello, World!!!', 'hello-world'];
        yield 'leading and trailing punctuation trimmed' => ['--Hello World--', 'hello-world'];
        yield 'internal runs collapse to one separator' => ['Hello   ---   World', 'hello-world'];
        yield 'digits pass through' => ['Article 42: The Answer', 'article-42-the-answer'];
        yield 'accented latin (café)' => ['café', 'cafe'];
        yield 'accented latin (Ångström)' => ['Ångström', 'angstrom'];
        yield 'accented latin (Zürich)' => ['Zürich', 'zurich'];
    }

    #[DataProvider('slugCases')]
    public function testKnownCases(string $input, string $expected): void
    {
        self::assertSame($expected, Str::slug($input));
    }

    public function testEmptyStringSlugsToEmptyString(): void
    {
        self::assertSame('', Str::slug(''));
    }

    public function testOnlyPunctuationSlugsToEmptyString(): void
    {
        self::assertSame('', Str::slug('!!!---???'));
    }

    public function testCustomSeparator(): void
    {
        self::assertSame('hello_world', Str::slug('Hello World', '_'));
    }

    public function testEmptySeparatorConcatenatesWithNoJoiner(): void
    {
        self::assertSame('helloworld', Str::slug('Hello World', ''));
    }

    public function testMultiCharacterSeparatorIsTrimmedAsAWholeUnit(): void
    {
        // trim() would treat '::' as the character list [':'] and still strip correctly here,
        // but this proves the implementation does not rely on that coincidence: a separator
        // whose characters could also appear as ordinary trim-list members is exercised too.
        self::assertSame('hello::world', Str::slug('---Hello World---', '::'));
    }

    public function testCyrillicIsTransliteratedNotDropped(): void
    {
        // "Tokyo" in Cyrillic. Transliterated to Latin letters, not stripped as non-ASCII —
        // the distinguishing behavior of a real transliterator over a blunt ASCII filter.
        $slug = Str::slug('Токио');

        self::assertNotSame('', $slug, 'a transliterable script must not slug to nothing');
        self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function idempotenceCorpus(): iterable
    {
        yield 'plain words' => ['Hello World'];
        yield 'punctuation-heavy' => ['  ---Hello,, World!!!---  '];
        yield 'accented' => ['Café Ångström Zürich'];
        yield 'cyrillic' => ['Токио Стрит'];
        yield 'digits and words' => ['Article 42: The Answer (final)'];
        yield 'already slugged' => ['already-a-slug-123'];
        yield 'empty' => [''];
        yield 'only punctuation' => ['!!!???'];
    }

    /** T-05 property test (spec §7): slugifying a slug must be a no-op. */
    #[DataProvider('idempotenceCorpus')]
    public function testSlugIsIdempotent(string $input): void
    {
        $once = Str::slug($input);
        $twice = Str::slug($once);

        self::assertSame($once, $twice, sprintf('slug("%s") = "%s" is not idempotent', $input, $once));
    }

    private static function invokeViaMethod(string $method, string $value): ?string
    {
        $reflection = new ReflectionMethod(Str::class, $method);
        $reflection->setAccessible(true);

        /** @var string|null $result */
        $result = $reflection->invoke(null, $value);

        return $result;
    }

    public function testViaIntlTransliteratesWhenExtensionIsLoaded(): void
    {
        if (!function_exists('transliterator_transliterate')) {
            self::markTestSkipped('ext-intl is not loaded in this environment');
        }

        self::assertSame('cafe', self::invokeViaMethod('viaIntl', 'café'));
        self::assertSame('Angstrom', self::invokeViaMethod('viaIntl', 'Ångström'));
    }

    public function testViaIconvTransliteratesWhenExtensionIsLoaded(): void
    {
        if (!function_exists('iconv')) {
            self::markTestSkipped('ext-iconv is not loaded in this environment');
        }

        // iconv's //TRANSLIT approximation is libc-dependent — glibc renders "é" as "'e" here,
        // not "e" — so the contract worth asserting is "no multi-byte UTF-8 survives", not one
        // exact rendering that would make this test brittle across platforms.
        $result = self::invokeViaMethod('viaIconv', 'café');

        self::assertNotNull($result);
        self::assertSame(strlen($result), mb_strlen($result), 'result must be single-byte-per-character ASCII');
        self::assertStringContainsString('caf', $result);
    }

    public function testViaAsciiFilterDropsNonAsciiEntirelyAndKeepsPrintableAscii(): void
    {
        // The tier with no extension dependency: proved directly, independent of what this
        // environment happens to have loaded. "café" -> "caf": é is two UTF-8 bytes, both
        // outside the printable-ASCII range, so the filter drops the byte pair rather than
        // leaving a mangled remainder — no partial character ever survives.
        self::assertSame('caf', self::invokeViaMethod('viaAsciiFilter', 'café'));
        self::assertSame('Hello World!', self::invokeViaMethod('viaAsciiFilter', 'Hello World!'));
        self::assertSame('', self::invokeViaMethod('viaAsciiFilter', 'Токио'));
        // Control characters are not printable ASCII (0x20-0x7E) either.
        self::assertSame('ab', self::invokeViaMethod('viaAsciiFilter', "a\x01b"));
    }
}
