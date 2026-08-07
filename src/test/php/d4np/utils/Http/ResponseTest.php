<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Http;

use D4np\Utils\Http\Response;
use D4np\Utils\Support\HttpException;
use D4np\Utils\Support\JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec FR-14's response helpers.
 *
 * The load-bearing cases are header handling: case-insensitive names that must not become
 * duplicates, and the CR/LF refusal that stops response splitting.
 */
final class ResponseTest extends TestCase
{
    public function testCreateCarriesStatusAndBody(): void
    {
        $response = Response::create(201, 'made');

        self::assertSame(201, $response->status());
        self::assertSame('made', $response->body());
        self::assertSame([], $response->headers());
    }

    public function testTextSetsAPlainContentType(): void
    {
        $response = Response::text('hi');

        self::assertSame(200, $response->status());
        self::assertSame('hi', $response->body());
        self::assertSame('text/plain; charset=utf-8', $response->header('Content-Type'));
    }

    public function testHtmlSetsAnHtmlContentType(): void
    {
        self::assertSame('text/html; charset=utf-8', Response::html('<p>x</p>')->header('content-type'));
    }

    /**
     * The body is deliberately **not** escaped: escaping is a render-time decision that depends on
     * where each value lands (ADR-0019's four contexts), and a blanket pass over an assembled
     * document would corrupt the markup it is meant to carry.
     */
    public function testHtmlDoesNotEscapeTheBody(): void
    {
        self::assertSame('<p>kept</p>', Response::html('<p>kept</p>')->body());
    }

    public function testJsonEncodesAndSetsTheContentType(): void
    {
        $response = Response::json(['ok' => true, 'n' => 1]);

        self::assertSame('{"ok":true,"n":1}', $response->body());
        self::assertSame('application/json; charset=utf-8', $response->header('Content-Type'));
    }

    /**
     * Encoded through `Json::encode()`, so an unencodable value raises rather than silently
     * producing the string `false` in the body (RFC-0001 R-7).
     */
    public function testJsonRaisesRatherThanEmittingAFalsyBody(): void
    {
        $this->expectException(JsonException::class);

        Response::json(NAN);
    }

    public function testRedirectSetsLocationAndDefaultsTo302(): void
    {
        $response = Response::redirect('/next');

        self::assertSame(302, $response->status());
        self::assertSame('/next', $response->header('Location'));
    }

    public function testRedirectRefusesANonRedirectStatus(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/3xx/');

        Response::redirect('/next', 200);
    }

    // ---- status validation -----------------------------------------------------------------------

    /**
     * @return iterable<string, array{int}>
     */
    public static function illegalStatuses(): iterable
    {
        yield 'below the range' => [99];
        yield 'above the range' => [600];
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'four digits' => [1000];
    }

    #[DataProvider('illegalStatuses')]
    public function testAStatusOutsideTheHttpRangeIsRefused(int $status): void
    {
        $this->expectException(HttpException::class);

        Response::create($status);
    }

    public function testTheStatusRangeBoundariesAreAccepted(): void
    {
        self::assertSame(100, Response::create(100)->status());
        self::assertSame(599, Response::create(599)->status());
    }

    // ---- headers ---------------------------------------------------------------------------------

    /**
     * HTTP header names are case-insensitive (RFC 9110), so these must not become two headers — a
     * duplicated `Content-Type` is how a response smuggles a second interpretation past a proxy.
     */
    public function testHeaderNamesAreCaseInsensitiveAndDoNotDuplicate(): void
    {
        $response = Response::create()
            ->withHeader('Content-Type', 'text/plain')
            ->withHeader('content-type', 'application/json');

        self::assertCount(1, $response->headers());
        self::assertSame('application/json', $response->header('CONTENT-TYPE'));
    }

    /**
     * Some clients are, in practice, less tolerant than the specification, so the spelling the
     * caller chose is what gets sent.
     */
    public function testTheOriginalHeaderCasingIsPreservedForOutput(): void
    {
        $headers = Response::create()->withHeader('X-Custom-Thing', 'v')->headers();

        self::assertArrayHasKey('X-Custom-Thing', $headers);
    }

    public function testWithoutHeaderRemovesCaseInsensitively(): void
    {
        $response = Response::create()->withHeader('X-A', '1')->withoutHeader('x-a');

        self::assertSame([], $response->headers());
    }

    /**
     * **Response splitting.** A CR or LF in a header value ends the line early and lets the
     * remainder be read as further headers or as the body. Refused rather than stripped, so a
     * caller finds out their value was not what they thought.
     *
     * @return iterable<string, array{string}>
     */
    public static function splittingValues(): iterable
    {
        yield 'CRLF then header' => ["v\r\nX-Injected: 1"];
        yield 'LF only' => ["v\nX-Injected: 1"];
        yield 'CR only' => ["v\rX-Injected: 1"];
        yield 'null byte' => ["v\0truncated"];
        yield 'CRLF CRLF into body' => ["v\r\n\r\n<script>alert(1)</script>"];
    }

    #[DataProvider('splittingValues')]
    public function testHeaderValuesThatCouldSplitTheResponseAreRefused(string $value): void
    {
        $this->expectException(HttpException::class);

        Response::create()->withHeader('X-Test', $value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function illegalNames(): iterable
    {
        yield 'empty' => [''];
        yield 'with space' => ['X Custom'];
        yield 'with colon' => ['X:Custom'];
        yield 'with newline' => ["X\nCustom"];
        yield 'with non-token char' => ['X-Cust(om)'];
    }

    #[DataProvider('illegalNames')]
    public function testIllegalHeaderNamesAreRefused(string $name): void
    {
        $this->expectException(HttpException::class);

        Response::create()->withHeader($name, 'v');
    }

    public function testTokenCharactersAreAcceptedInNames(): void
    {
        $response = Response::create()->withHeader('X-Custom_Thing.1', 'v');

        self::assertSame('v', $response->header('x-custom_thing.1'));
    }

    // ---- immutability ------------------------------------------------------------------------------

    /**
     * A response is built in stages, often across layers. The alternative to immutability is an
     * object a helper can change behind its caller's back.
     */
    public function testEveryWitherReturnsANewInstanceAndLeavesTheOriginalAlone(): void
    {
        $base = Response::create(200, 'body');

        $withHeader = $base->withHeader('X-A', '1');
        $withStatus = $base->withStatus(404);
        $withBody = $base->withBody('other');
        $without = $withHeader->withoutHeader('X-A');

        self::assertNotSame($base, $withHeader);
        self::assertSame([], $base->headers());
        self::assertSame(200, $base->status());
        self::assertSame('body', $base->body());

        self::assertSame('1', $withHeader->header('X-A'));
        self::assertSame(404, $withStatus->status());
        self::assertSame('other', $withBody->body());
        self::assertSame([], $without->headers());
    }

    public function testWithStatusValidatesToo(): void
    {
        $this->expectException(HttpException::class);

        Response::create()->withStatus(700);
    }
}
