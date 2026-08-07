<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Http;

use D4np\Utils\Http\HttpResponse;
use D4np\Utils\Support\JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `HttpResponse` — the inbound half of spec r3 **FR-37** (ADR-0049).
 */
final class HttpResponseTest extends TestCase
{
    public function testHeadersAreReadCaseInsensitively(): void
    {
        $response = new HttpResponse(200, ['Content-Type: application/json'], '{}');

        self::assertSame('application/json', $response->header('content-type'));
        self::assertSame('application/json', $response->header('CONTENT-TYPE'));
        self::assertNull($response->header('X-Absent'));
    }

    public function testRepeatedHeadersAreAllKept(): void
    {
        // Set-Cookie is the case that breaks a name => value map, and the one that matters:
        // keeping only the last would silently drop every cookie but one.
        $response = new HttpResponse(200, ['Set-Cookie: a=1', 'Set-Cookie: b=2'], '');

        self::assertSame(['a=1', 'b=2'], $response->headerLine('Set-Cookie'));
        self::assertSame('a=1', $response->header('Set-Cookie'), 'header() yields the first');
    }

    public function testAMalformedHeaderLineIsDroppedRatherThanGuessedAt(): void
    {
        $response = new HttpResponse(200, ['not-a-header-line', 'X-Real: 1'], '');

        self::assertSame(['x-real' => ['1']], $response->headers());
    }

    #[DataProvider('statuses')]
    public function testOnlyTwoHundredsAreSuccessful(int $status, bool $successful): void
    {
        self::assertSame($successful, (new HttpResponse($status, [], ''))->isSuccessful());
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function statuses(): iterable
    {
        yield '200' => [200, true];
        yield '201' => [201, true];
        yield '299' => [299, true];
        yield '301 is unfinished, not successful' => [301, false];
        yield '304' => [304, false];
        yield '400' => [400, false];
        yield '500' => [500, false];
        yield '199' => [199, false];
    }

    public function testTheBodyDecodesAsJson(): void
    {
        self::assertSame(['a' => [1, 2]], (new HttpResponse(200, [], '{"a":[1,2]}'))->json());
    }

    public function testABodyThatIsNotJsonRaisesTheJsonFailureNotAClientOne(): void
    {
        // The payload's problem keeps the payload's exception type: reporting it as a transport
        // failure would tell the caller to retry a request that arrived perfectly well.
        $this->expectException(JsonException::class);

        (new HttpResponse(200, [], 'not json at all'))->json();
    }
}
