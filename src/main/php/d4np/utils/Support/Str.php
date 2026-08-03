<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

use InvalidArgumentException;

/**
 * String utilities: URL-friendly slugs, UUIDs, and CSPRNG tokens (spec §2 items 19–21).
 */
final class Str
{
    /**
     * The alphabet {@see self::random()} draws from when the caller does not supply one:
     * unambiguous alphanumerics, upper- and lowercase.
     */
    private const DEFAULT_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    private function __construct()
    {
        // Static-only: no instances.
    }

    /**
     * A URL-friendly slug: lowercase ASCII alphanumerics joined by `$separator`, with no
     * leading, trailing, or doubled separator.
     *
     * Non-ASCII input is transliterated first, in three tiers of decreasing fidelity — each a
     * pure, independently testable step (see the private `via*` methods): the ICU
     * transliterator (`ext-intl`) when loaded, `iconv`'s `//TRANSLIT` when it is not, and — if
     * neither extension is present — dropping whatever falls outside printable ASCII. The
     * result is always slug-safe, never an error: a slug generator that throws on emoji is
     * worse than one that produces a shorter slug.
     *
     * **Idempotent**: `slug(slug($x)) === slug($x)`, asserted as a property test (spec T-05).
     * Once produced, a slug is already lowercase ASCII with the separator as its only
     * non-alphanumeric character, so re-running finds nothing left to change.
     */
    public static function slug(string $value, string $separator = '-'): string
    {
        $ascii = strtolower(self::transliterateToAscii($value));
        $collapsed = preg_replace('/[^a-z0-9]+/', $separator, $ascii) ?? '';

        if ($separator === '') {
            return $collapsed;
        }

        // trim() treats its second argument as a character LIST, not a substring, so it would
        // mis-trim a multi-character separator. Strip the separator as a literal run instead.
        $quoted = preg_quote($separator, '/');

        return preg_replace("/^(?:{$quoted})+|(?:{$quoted})+\$/", '', $collapsed) ?? '';
    }

    /**
     * A version 4 (random) UUID, generated from {@see random_bytes()}.
     *
     * Sets the version nibble to `4` and the variant bits to `10` per RFC 4122 §4.4, over 16
     * cryptographically random bytes — the two fixed nibbles are metadata, not randomness lost:
     * a v4 UUID carries 122 bits of entropy, not 128.
     */
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /**
     * A CSPRNG token of `$length` characters drawn uniformly from `$alphabet`.
     *
     * Built on {@see random_int()} (CSPRNG-backed, rejection-sampled — no modulo bias), one
     * character per call. Suitable for tokens that must not be guessable: session identifiers,
     * one-time codes, API keys.
     *
     * @throws InvalidArgumentException if `$length` is negative or `$alphabet` has fewer than
     *                                   two characters (fewer would make the token predictable
     *                                   or, at zero, undefined).
     */
    public static function random(int $length = 32, string $alphabet = self::DEFAULT_ALPHABET): string
    {
        if ($length < 0) {
            throw new InvalidArgumentException(sprintf('$length must be >= 0, got %d.', $length));
        }

        $alphabetLength = strlen($alphabet);
        if ($alphabetLength < 2) {
            throw new InvalidArgumentException('$alphabet must contain at least two characters.');
        }

        $token = '';
        for ($i = 0; $i < $length; $i++) {
            $token .= $alphabet[random_int(0, $alphabetLength - 1)];
        }

        return $token;
    }

    private static function transliterateToAscii(string $value): string
    {
        return self::viaIntl($value) ?? self::viaIconv($value) ?? self::viaAsciiFilter($value);
    }

    /**
     * Tier 1: the ICU transliterator (`ext-intl`). Converts any script to Latin and then strips
     * diacritics — "café" → "cafe", "Ångström" → "Angstrom", "Токио" → "Tokio".
     *
     * @return string|null `null` when `ext-intl` is not loaded, so the caller falls through.
     */
    private static function viaIntl(string $value): ?string
    {
        if (!function_exists('transliterator_transliterate')) {
            return null;
        }

        $result = @transliterator_transliterate('Any-Latin; Latin-ASCII', $value);

        return $result === false ? null : $result;
    }

    /**
     * Tier 2: `iconv`'s transliterating charset conversion. Coarser than ICU — it approximates
     * or drops what it cannot map — but `ext-iconv` ships with PHP far more often than
     * `ext-intl` does.
     *
     * @return string|null `null` when `iconv` is not loaded, so the caller falls through.
     */
    private static function viaIconv(string $value): ?string
    {
        if (!function_exists('iconv')) {
            return null;
        }

        $result = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $result === false ? null : $result;
    }

    /**
     * Tier 3, the last resort with no extension dependency at all: keep only printable ASCII
     * and drop the rest. Never fails, never returns `null` — {@see self::transliterateToAscii()}
     * always terminates here.
     */
    private static function viaAsciiFilter(string $value): string
    {
        return preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';
    }
}
