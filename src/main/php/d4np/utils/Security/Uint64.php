<?php

declare(strict_types=1);

namespace D4np\Utils\Security;

/**
 * Fixed-width big-endian 64-bit integers, the shape this group's opaque state and tokens are built
 * from (spec r22 FR-50, RFC-0003; ADR-0067).
 *
 * Extracted from {@see Hmac}'s private expiry codec when {@see RateLimiter} arrived needing the same
 * thing twice over — a token count and a refill instant packed side by side. The extraction is the
 * point, for the reason {@see Base64Url}'s was: item 10.4 shipped two identifier corpora of ten and
 * nineteen payloads with **both suites green**, because the newer of two copies held to the weaker
 * rule and nothing could see it.
 *
 * **Fixed width is what makes concatenation unambiguous without a delimiter.** `encode(1) . "23"`
 * and `encode(12) . "3"` cannot collide, because the first eight bytes are always the integer —
 * the discipline ADR-0054 established by slicing fixed offsets rather than trusting separators, and
 * the reason ADR-0065's MAC can cover `expiry ‖ message` with nothing between them.
 *
 * Hand-rolled rather than `pack('J')`/`unpack('J')`: `unpack()` returns `array|false`, and the
 * `false` branch cannot fire on an eight-byte input, which is the dead defensive code ADR-0022
 * removed from {@see Hash}.
 *
 * @internal this group's token and state formats only
 */
final class Uint64
{
    public const BYTES = 8;

    public static function encode(int $value): string
    {
        $bytes = '';

        for ($shift = (self::BYTES - 1) * 8; $shift >= 0; $shift -= 8) {
            $bytes .= \chr(($value >> $shift) & 0xFF);
        }

        return $bytes;
    }

    /**
     * Reads the eight bytes at `$offset`.
     *
     * The caller has already checked the length — every format in this group pins its own total
     * size against a constant before slicing, so a bounds check here would be a second guard for
     * one invariant and could not fire.
     */
    public static function decode(string $bytes, int $offset = 0): int
    {
        $value = 0;

        for ($index = $offset; $index < $offset + self::BYTES; $index++) {
            $value = ($value << 8) | \ord($bytes[$index]);
        }

        return $value;
    }
}
