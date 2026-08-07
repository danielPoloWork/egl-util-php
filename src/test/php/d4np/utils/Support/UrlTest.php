<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\InvalidUrlException;
use D4np\Utils\Support\Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Url` parsing, normalization and recomposition — spec r3 FR-27 (RFC-0002), ADR-0036.
 *
 * The downgrade policy has its own suite ({@see UrlSchemeDowngradeTest}); this one covers
 * what the object must get right before that policy means anything: that it refuses input
 * `parse_url()` would launder, that it refuses relative references `parse_url()` accepts,
 * and that it recomposes without losing a component.
 */
final class UrlTest extends TestCase
{
    public function testParsesEveryComponent(): void
    {
        $url = Url::parse('https://user:pass@example.com:8443/a/b?q=1&r=2#frag');

        self::assertSame('https', $url->scheme());
        self::assertSame('user:pass', $url->userInfo());
        self::assertSame('example.com', $url->host());
        self::assertSame(8443, $url->port());
        self::assertSame('/a/b', $url->path());
        self::assertSame('q=1&r=2', $url->rawQuery());
        self::assertSame('frag', $url->fragment());
    }

    public function testRoundTripsAFullUrlUnchanged(): void
    {
        $input = 'https://user:pass@example.com:8443/a/b?q=1&r=2#frag';

        self::assertSame($input, Url::parse($input)->toString());
    }

    public function testStringCastIsTheRecomposedUrl(): void
    {
        $url = Url::parse('https://example.com/a');

        self::assertSame($url->toString(), (string) $url);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalizationCases(): iterable
    {
        yield 'scheme lowercased' => ['HTTPS://example.com/', 'https://example.com/'];
        yield 'host lowercased' => ['https://EXAMPLE.COM/', 'https://example.com/'];
        yield 'path case preserved' => ['https://example.com/Path', 'https://example.com/Path'];
        yield 'default https port dropped' => ['https://example.com:443/x', 'https://example.com/x'];
        yield 'default http port dropped' => ['http://example.com:80/x', 'http://example.com/x'];
        yield 'non-default port kept' => ['https://example.com:8443/x', 'https://example.com:8443/x'];
        yield 'empty path becomes slash' => ['https://example.com', 'https://example.com/'];
        yield 'query preserved byte-exact' => ['https://example.com/?b=2&a=1', 'https://example.com/?b=2&a=1'];
        yield 'encoded query untouched' => ['https://example.com/?s=a%20b', 'https://example.com/?s=a%20b'];
    }

    #[DataProvider('normalizationCases')]
    public function testNormalization(string $input, string $expected): void
    {
        self::assertSame($expected, Url::parse($input)->toString());
    }

    #[DataProvider('normalizationCases')]
    public function testRecompositionIsStableUnderReparsing(string $input): void
    {
        $once = Url::parse($input)->toString();

        self::assertSame($once, Url::parse($once)->toString());
    }

    /**
     * The finding behind ADR-0036: `parse_url()` does not reject control characters, it
     * rewrites each to `_`. Both halves are asserted — PHP's laundering, and our refusal —
     * because the refusal only matters if the underlying behavior is what we claim.
     *
     * @return iterable<string, array{string}>
     */
    public static function controlCharacterCases(): iterable
    {
        yield 'newline' => ["https://example.com\n/evil"];
        yield 'carriage return' => ["https://example.com/a\rb"];
        yield 'CRLF' => ["https://example.com/a\r\nb"];
        yield 'NUL' => ["https://example.com/a\0b"];
        yield 'tab in host' => ["https://exam\tple.com/"];
        yield 'DEL' => ["https://example.com/a\x7Fb"];
    }

    #[DataProvider('controlCharacterCases')]
    public function testControlCharactersAreRefused(string $input): void
    {
        $this->expectException(InvalidUrlException::class);
        $this->expectExceptionMessage('control character');

        Url::parse($input);
    }

    public function testParseUrlLaundersControlCharactersWhichIsWhyWeRefuseThem(): void
    {
        // Not testing our code: pinning the PHP behavior the guard exists for, so that if a
        // future PHP starts rejecting these outright, this test says so.
        $parts = \parse_url("https://example.com\n/evil");

        self::assertIsArray($parts);
        self::assertSame('example.com_', $parts['host'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAbsoluteCases(): iterable
    {
        yield 'bare word' => ['not a url'];
        yield 'relative path' => ['/just/a/path'];
        yield 'protocol-relative' => ['//example.com/path'];
        yield 'scheme with no host' => ['mailto:someone@example.com'];
        yield 'empty string' => [''];
    }

    #[DataProvider('notAbsoluteCases')]
    public function testRelativeReferencesAreRefused(string $input): void
    {
        $this->expectException(InvalidUrlException::class);
        $this->expectExceptionMessage('absolute URL');

        Url::parse($input);
    }

    public function testParseUrlAcceptsABareWordWhichIsWhyAbsolutenessIsCheckedSeparately(): void
    {
        // parse_url('not a url') succeeds with ['path' => 'not a url'], so "did it parse?"
        // cannot answer "is this a URL?".
        self::assertSame(['path' => 'not a url'], \parse_url('not a url'));
    }

    public function testUnparseableStringIsRefused(): void
    {
        $this->expectException(InvalidUrlException::class);

        // parse_url() returns false for this one.
        Url::parse('http:///path');
    }

    public function testCredentialsAreReportedTruthfullyAndCanBeStripped(): void
    {
        $url = Url::parse('https://user:secret@example.com/a');

        self::assertSame('user:secret', $url->userInfo());
        self::assertSame('https://example.com/a', $url->withoutUserInfo()->toString());
    }

    public function testUserOnlyCredentialHasNoColon(): void
    {
        self::assertSame('user', Url::parse('https://user@example.com/')->userInfo());
    }

    public function testHostIsTheAuthorityAfterAnyCredentials(): void
    {
        // A backslash before '@' makes the readable-looking host the *userinfo* and the real
        // host what follows — correct per RFC 3986, and the reason a host check must read
        // host() rather than search the raw string.
        $url = Url::parse('https://example.com\\@evil.com/');

        self::assertSame('evil.com', $url->host());
    }

    public function testWithersAreImmutable(): void
    {
        $url = Url::parse('https://example.com/a?x=1#f');

        $url->withPath('/b');
        $url->withFragment('other');
        $url->withoutUserInfo();

        self::assertSame('https://example.com/a?x=1#f', $url->toString());
    }

    public function testWithPathReplacesThePath(): void
    {
        self::assertSame(
            'https://example.com/b?x=1',
            Url::parse('https://example.com/a?x=1')->withPath('/b')->toString(),
        );
    }

    public function testWithPathNormalizesEmptyToSlash(): void
    {
        self::assertSame('/', Url::parse('https://example.com/a')->withPath('')->path());
    }

    public function testWithFragmentSetsAndClears(): void
    {
        $url = Url::parse('https://example.com/a');

        self::assertSame('https://example.com/a#top', $url->withFragment('top')->toString());
        self::assertNull($url->withFragment('top')->withFragment(null)->fragment());
    }

    public function testControlCharactersAreRefusedInWithersToo(): void
    {
        $url = Url::parse('https://example.com/');

        $this->expectException(InvalidUrlException::class);

        $url->withPath("/a\r\nX-Injected: 1");
    }

    public function testFragmentWitherRefusesControlCharacters(): void
    {
        $this->expectException(InvalidUrlException::class);

        Url::parse('https://example.com/')->withFragment("a\nb");
    }
}
