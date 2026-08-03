<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\Json;
use D4np\Utils\Support\JsonException;
use D4np\Utils\Support\UtilsThrowable;
use JsonException as NativeJsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** `Json` — spec §2 item 25, and the T-05 round-trip property test spec §7 names. */
final class JsonTest extends TestCase
{
    /**
     * The T-05 property test (spec §7): round-trips. Each value must survive
     * `decode(encode($value))` unchanged — the property `Json` exists to guarantee.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function roundTripValues(): iterable
    {
        yield 'null' => [null];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'zero' => [0];
        yield 'negative int' => [-42];
        yield 'float' => [3.14159];
        yield 'empty string' => [''];
        yield 'ascii string' => ['hello world'];
        yield 'unicode string' => ['Café — Ångström — Токио — 🎉'];
        yield 'string with a literal quote and backslash' => ['she said "hi\\"'];
        yield 'empty list' => [[]];
        yield 'flat list' => [[1, 2, 3]];
        yield 'assoc array' => [['a' => 1, 'b' => ['c' => 2, 'd' => [3, 4]]]];
        yield 'array with null and bool members' => [['x' => null, 'y' => true, 'z' => false]];
    }

    #[DataProvider('roundTripValues')]
    public function testRoundTrip(mixed $value): void
    {
        self::assertSame($value, Json::decode(Json::encode($value)));
    }

    public function testEncodeProducesTheExpectedJsonText(): void
    {
        self::assertSame('{"a":1,"b":[2,3]}', Json::encode(['a' => 1, 'b' => [2, 3]]));
    }

    public function testDecodeAssociativeReturnsAnArrayByDefault(): void
    {
        $result = Json::decode('{"a":1}');

        self::assertIsArray($result);
        self::assertSame(['a' => 1], $result);
    }

    public function testDecodeNonAssociativeReturnsAnObject(): void
    {
        $result = Json::decode('{"a":1}', false);

        self::assertInstanceOf(\stdClass::class, $result);
        self::assertSame(1, $result->a);
    }

    public function testDecodeThrowsOnMalformedJsonAndWrapsTheNativeException(): void
    {
        try {
            Json::decode('{"a": invalid}');
            self::fail('expected a JsonException');
        } catch (JsonException $e) {
            self::assertInstanceOf(NativeJsonException::class, $e->getPrevious(), 'the native exception must stay reachable');
        }
    }

    public function testEncodeThrowsOnAResourceWhichCannotBeEncoded(): void
    {
        $resource = fopen('php://memory', 'rb');
        self::assertIsResource($resource);

        try {
            $this->expectException(JsonException::class);
            Json::encode($resource);
        } finally {
            fclose($resource);
        }
    }

    public function testEncodeThrowsOnNanWhichHasNoJsonRepresentation(): void
    {
        $this->expectException(JsonException::class);

        Json::encode(NAN);
    }

    public function testDecodeThrowsWhenNestingExceedsTheDepthLimit(): void
    {
        $this->expectException(JsonException::class);

        Json::decode('[[[[[1]]]]]', true, 3);
    }

    public function testEveryFailureIsCatchableThroughTheLibraryMarker(): void
    {
        try {
            Json::decode('not json');
            self::fail('expected a JsonException');
        } catch (UtilsThrowable $e) {
            self::assertInstanceOf(JsonException::class, $e);
        }
    }
}
