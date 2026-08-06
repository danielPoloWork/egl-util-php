<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\InvalidUrlException;
use D4np\Utils\Support\Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The scheme policy — spec r3 FR-27's security clause (RFC-0002), ADR-0036.
 *
 * The estate defect this closes: a URL helper that decomposed an address and rebuilt it as
 * `"http://{$host}{$path}"`, so every `https` URL it touched came back plaintext. Two
 * mechanisms are asserted here — that recomposition **carries** the scheme (the defect is
 * structurally unreachable), and that an explicit downgrade is **refused**.
 */
final class UrlSchemeDowngradeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedDowngrades(): iterable
    {
        yield 'https to http' => ['https://example.com/a', 'http'];
        yield 'wss to ws' => ['wss://example.com/socket', 'ws'];
        yield 'ftps to ftp' => ['ftps://example.com/file', 'ftp'];
        yield 'sftp to ftp' => ['sftp://example.com/file', 'ftp'];
        yield 'case does not evade the check' => ['https://example.com/a', 'HTTP'];
    }

    #[DataProvider('refusedDowngrades')]
    public function testDowngradeIsRefused(string $url, string $target): void
    {
        $this->expectException(InvalidUrlException::class);
        $this->expectExceptionMessage('Refusing to downgrade');

        Url::parse($url)->withScheme($target);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function allowedTransitions(): iterable
    {
        yield 'http to https is an upgrade' => ['http://example.com/a', 'https', 'https://example.com/a'];
        yield 'ws to wss is an upgrade' => ['ws://example.com/s', 'wss', 'wss://example.com/s'];
        yield 'same scheme' => ['https://example.com/a', 'https', 'https://example.com/a'];
        yield 'plaintext to plaintext' => ['http://example.com/a', 'ftp', 'ftp://example.com/a'];
    }

    #[DataProvider('allowedTransitions')]
    public function testAllowedTransitions(string $url, string $target, string $expected): void
    {
        self::assertSame($expected, Url::parse($url)->withScheme($target)->toString());
    }

    public function testAnUnknownSchemeIsAllowedThroughWhichIsTheRecordedLimit(): void
    {
        // ADR-0036's honest limit: the security properties of an unrecognised scheme cannot
        // be asserted, so only *known* plaintext counterparts are refused. This test exists
        // so the limit is visible rather than discovered.
        self::assertSame(
            'myapp://example.com/a',
            Url::parse('https://example.com/a')->withScheme('myapp')->toString(),
        );
    }

    public function testTheSchemeSurvivesEveryRecomposition(): void
    {
        // The estate defect directly: no sequence of edits can silently yield plaintext.
        $url = Url::parse('https://example.com/old?a=1#f')
            ->withPath('/new')
            ->withQueryParam('b', '2')
            ->withFragment('g')
            ->withoutUserInfo();

        self::assertSame('https', $url->scheme());
        self::assertStringStartsWith('https://', $url->toString());
    }

    public function testUpgradeReNormalizesThePortAgainstTheNewScheme(): void
    {
        // :443 is not http's default, so it survives; after the upgrade it IS https's
        // default and must drop, or the URL would carry a redundant port.
        $url = Url::parse('http://example.com:443/a');

        self::assertSame(443, $url->port());
        self::assertSame('https://example.com/a', $url->withScheme('https')->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSchemes(): iterable
    {
        yield 'empty' => [''];
        yield 'leading digit' => ['1http'];
        yield 'contains a colon' => ['http:'];
        yield 'contains a slash' => ['ht/tp'];
        yield 'contains a space' => ['ht tp'];
    }

    #[DataProvider('invalidSchemes')]
    public function testSyntacticallyInvalidSchemesAreRefused(string $scheme): void
    {
        $this->expectException(InvalidUrlException::class);
        $this->expectExceptionMessage('Invalid scheme');

        Url::parse('https://example.com/')->withScheme($scheme);
    }

    public function testASchemeCarryingCrlfIsRefusedBeforeItReachesTheString(): void
    {
        $this->expectException(InvalidUrlException::class);
        $this->expectExceptionMessage('control character');

        Url::parse('https://example.com/')->withScheme("https\r\nX-Injected: 1");
    }
}
