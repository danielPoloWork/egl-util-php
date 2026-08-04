<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

use JsonException as NativeJsonException;

/**
 * `json_encode()`/`json_decode()` wrappers that always throw on failure (spec §2 item 25).
 *
 * Both native functions accept `JSON_THROW_ON_ERROR`, which turns a silent `false`/`null`
 * return into a thrown `\JsonException` — but a caller has to remember to pass the flag every
 * time, and forgetting it is how a truncated payload becomes a `null` nobody checked. These
 * wrappers pass it unconditionally and rethrow through {@see JsonException::wrap()}
 * (RFC-0001 R-7), so the original is never lost — it stays reachable via `getPrevious()`.
 */
final class Json
{
    private function __construct()
    {
        // Static-only: no instances.
    }

    /**
     * @param int<1, max> $depth
     *
     * @throws JsonException if `$value` cannot be encoded — a resource, `NAN`/`INF`, or a
     *                        depth beyond `$depth`.
     */
    public static function encode(mixed $value, int $flags = 0, int $depth = 512): string
    {
        try {
            return json_encode($value, $flags | JSON_THROW_ON_ERROR, $depth);
        } catch (NativeJsonException $e) {
            throw JsonException::wrap($e);
        }
    }

    /**
     * @param int<1, max> $depth
     *
     * @throws JsonException if `$json` is not valid JSON, or nests beyond `$depth`.
     */
    public static function decode(string $json, bool $associative = true, int $depth = 512, int $flags = 0): mixed
    {
        try {
            return json_decode($json, $associative, $depth, $flags | JSON_THROW_ON_ERROR);
        } catch (NativeJsonException $e) {
            throw JsonException::wrap($e);
        }
    }
}
