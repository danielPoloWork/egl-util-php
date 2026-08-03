<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

use JsonException as NativeJsonException;

/**
 * JSON encoding or decoding failed.
 *
 * **The name deliberately shadows PHP's native `\JsonException`** — same failure domain, and
 * inventing a different word for it would cost more in confusion than the collision does. The
 * two are related by wrapping, not by inheritance: this class must extend
 * {@see UtilsException} to be catchable with everything else the package raises, so it cannot
 * also extend the native one.
 *
 * `Json::encode()` / `Json::decode()` pass `JSON_THROW_ON_ERROR`, catch the native exception,
 * and rethrow it through {@see self::wrap()}. The original is always retrievable via
 * `getPrevious()` — nothing is lost in translation.
 *
 * In a file that touches both, alias at the import:
 *
 * ```php
 * use D4np\Utils\Support\JsonException;
 * use JsonException as NativeJsonException;
 * ```
 */
final class JsonException extends UtilsException
{
    /**
     * Wrap PHP's native JSON exception, preserving its message, code, and the original as
     * `getPrevious()`.
     */
    public static function wrap(NativeJsonException $previous): self
    {
        return new self($previous->getMessage(), (int) $previous->getCode(), $previous);
    }
}
