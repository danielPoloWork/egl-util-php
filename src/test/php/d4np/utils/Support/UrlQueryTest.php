<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\InvalidUrlException;
use D4np\Utils\Support\Url;
use PHPUnit\Framework\TestCase;

/**
 * Query composition — spec r3 FR-27's "query composition without hand-concatenation"
 * (RFC-0002), ADR-0036.
 *
 * Two behaviours are load-bearing and both are non-obvious: an untouched query is preserved
 * **byte-exact** (re-encoding one nobody edited would invalidate a signature computed over
 * it), and a composed query is RFC 3986-encoded rather than form-encoded.
 */
final class UrlQueryTest extends TestCase
{
    public function testAnUntouchedQueryIsPreservedByteExact(): void
    {
        // Deliberately non-canonical: unsorted, mixed encodings, a repeated key. None of it
        // is rewritten, because nothing asked for it to be.
        $raw = 'z=1&a=%20&a=2&flag&s=a+b';

        self::assertSame($raw, Url::parse("https://example.com/?{$raw}")->rawQuery());
        self::assertSame("https://example.com/?{$raw}", Url::parse("https://example.com/?{$raw}")->toString());
    }

    public function testQueryDecodesToAnArray(): void
    {
        self::assertSame(
            ['a' => '1', 'b' => 'two'],
            Url::parse('https://example.com/?a=1&b=two')->query(),
        );
    }

    public function testDecodingIsLossyForRepeatedKeysWhichIsWhyRawQueryExists(): void
    {
        $url = Url::parse('https://example.com/?a=1&a=2');

        // parse_str() keeps the last occurrence — documented on query(), pinned here.
        self::assertSame(['a' => '2'], $url->query());
        self::assertSame('a=1&a=2', $url->rawQuery());
    }

    public function testWithQueryEncodesPerRfc3986NotAsAForm(): void
    {
        $url = Url::parse('https://example.com/')->withQuery(['s' => 'a b']);

        // http_build_query()'s default would give 's=a+b' — the HTML-form encoding, which is
        // not what a URL query is.
        self::assertSame('s=a%20b', $url->rawQuery());
    }

    public function testWithQueryReplacesTheWholeQuery(): void
    {
        self::assertSame(
            'https://example.com/?c=3',
            Url::parse('https://example.com/?a=1&b=2')->withQuery(['c' => '3'])->toString(),
        );
    }

    public function testWithQueryAcceptsAnEmptyArrayAndDropsTheQuestionMark(): void
    {
        self::assertSame(
            'https://example.com/a',
            Url::parse('https://example.com/a?x=1')->withQuery([])->toString(),
        );
    }

    public function testNullValuesAreRefusedRatherThanSilentlyDropped(): void
    {
        $this->expectException(InvalidUrlException::class);
        $this->expectExceptionMessage('null');

        Url::parse('https://example.com/')->withQuery(['a' => '1', 'b' => null]);
    }

    public function testHttpBuildQueryDropsNullsWhichIsWhyTheyAreRefused(): void
    {
        // Pinning the PHP behaviour the refusal exists for: 'b' vanishes without a trace.
        self::assertSame('a=1', \http_build_query(['a' => '1', 'b' => null], '', '&', PHP_QUERY_RFC3986));
    }

    public function testANestedNullIsRefusedAndItsPathIsNamed(): void
    {
        // A nested null is dropped exactly as quietly as a top-level one, so the guard
        // descends. PHPStan is what surfaced this: the acceptable shape is recursive, the
        // type cannot say so, and the first version of the guard only walked the top level.
        $this->expectException(InvalidUrlException::class);
        $this->expectExceptionMessage('"filter.status" is null');

        Url::parse('https://example.com/')->withQuery(['filter' => ['status' => null]]);
    }

    public function testNestedArraysOfScalarsAreAccepted(): void
    {
        $url = Url::parse('https://example.com/')->withQuery(['f' => ['a' => '1', 'b' => '2']]);

        self::assertSame('f%5Ba%5D=1&f%5Bb%5D=2', $url->rawQuery());
        self::assertSame(['f' => ['a' => '1', 'b' => '2']], $url->query());
    }

    public function testAnIntegerQueryKeyDecodesAsAnIntegerNotAString(): void
    {
        // The superglobal-key lesson from ADR-0025, in a query string: '?0=zero' yields the
        // INTEGER key 0, which is why query() is typed array-key rather than string.
        self::assertSame([0 => 'zero'], Url::parse('https://example.com/?0=zero')->query());
    }

    public function testWithQueryParamAddsAKey(): void
    {
        self::assertSame(
            'a=1&b=2',
            Url::parse('https://example.com/?a=1')->withQueryParam('b', '2')->rawQuery(),
        );
    }

    public function testWithQueryParamReplacesAnExistingKey(): void
    {
        self::assertSame(
            'a=9',
            Url::parse('https://example.com/?a=1')->withQueryParam('a', '9')->rawQuery(),
        );
    }

    public function testWithQueryParamAcceptsScalarsAndEncodesBoolsAsDigits(): void
    {
        $url = Url::parse('https://example.com/')
            ->withQueryParam('i', 42)
            ->withQueryParam('f', 1.5)
            ->withQueryParam('t', true)
            ->withQueryParam('e', '');

        self::assertSame('i=42&f=1.5&t=1&e=', $url->rawQuery());
    }

    public function testWithQueryParamEncodesSpecialCharacters(): void
    {
        $url = Url::parse('https://example.com/')->withQueryParam('q', 'a&b=c d');

        self::assertSame('q=a%26b%3Dc%20d', $url->rawQuery());
        self::assertSame(['q' => 'a&b=c d'], $url->query());
    }

    public function testWithoutQueryParamRemovesOneKey(): void
    {
        self::assertSame(
            'a=1',
            Url::parse('https://example.com/?a=1&b=2')->withoutQueryParam('b')->rawQuery(),
        );
    }

    public function testRemovingTheLastParamLeavesNoQuestionMark(): void
    {
        self::assertSame(
            'https://example.com/',
            Url::parse('https://example.com/?a=1')->withoutQueryParam('a')->toString(),
        );
    }

    public function testRemovingAnAbsentKeyIsANoOp(): void
    {
        self::assertSame(
            'a=1',
            Url::parse('https://example.com/?a=1')->withoutQueryParam('zzz')->rawQuery(),
        );
    }

    public function testEditingAQueryReEncodesItAndLosesDuplicateKeys(): void
    {
        // The documented consequence of decode-edit-encode: stated here so it is a known
        // trade-off rather than a surprise. rawQuery() is the exact form; editing leaves it.
        $url = Url::parse('https://example.com/?a=1&a=2')->withQueryParam('b', '3');

        self::assertSame('a=2&b=3', $url->rawQuery());
    }

    public function testQueryParamSurvivesFragmentAndPathEdits(): void
    {
        $url = Url::parse('https://example.com/x?a=1#f')->withPath('/y');

        self::assertSame('https://example.com/y?a=1#f', $url->toString());
    }
}
