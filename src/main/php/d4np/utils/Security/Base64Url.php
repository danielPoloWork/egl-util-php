<?php

declare(strict_types=1);

namespace D4np\Utils\Security;

/**
 * The base64url codec both compact-token formats in this group share (RFC 4648 §5).
 *
 * Extracted from {@see Crypto} when {@see Hmac} arrived needing the identical pair (spec r20
 * FR-48, RFC-0003; ADR-0065). The extraction is the point, not a tidy-up: item 10.4 shipped
 * `MutationBuilderTest` with its own ten-payload identifier corpus while `QueryBuilderTest` held
 * nineteen, and **both suites were green** — the newer of two copies held to the weaker rule and
 * nothing could see it. A second hand-rolled base64url decoder is that same defect waiting: this
 * one's strict-mode reasoning below is load-bearing and hard-won, and a copy that drifted from it
 * would still round-trip every token its own tests fed it.
 *
 * `@internal` for the same reason {@see SecretKey::bytes()} is: it exists to be shared by this
 * group's token formats, not to become a general-purpose encoder on the frozen public surface
 * (ADR-0059 places `@internal` symbols outside it). Callers that want base64url should say so in a
 * requirement.
 *
 * @internal {@see Crypto} and {@see Hmac} only.
 */
final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return \rtrim(\strtr(\base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @return string|false `false` on malformed input, never a partial decode
     */
    public static function decode(string $encoded): string|false
    {
        // No separate alphabet check: probed, base64_decode()'s own strict mode already rejects
        // every case one would try to catch here — a stray character, a space, wrong padding —
        // once the string has been through str_pad()/strtr() below. An earlier version added a
        // preg_match() guard first; it never fired, because strict mode already had every case
        // covered, which is the same "dead defensive code" shape ADR-0022 removed from `Hash`.
        $padded = \str_pad($encoded, \strlen($encoded) + ((4 - \strlen($encoded) % 4) % 4), '=');

        return \base64_decode(\strtr($padded, '-_', '+/'), true);
    }
}
