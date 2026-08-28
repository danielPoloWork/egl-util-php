<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * String utilities: URL-friendly slugs, UUIDs, CSPRNG tokens (spec §2 items 19–21), the
 * FR-31 additions — whitespace collapsing, blank-to-null, charset transcoding, multibyte-safe
 * padding, class-name and case helpers (spec r3, RFC-0002) — the FR-46 time-sortable
 * identifiers, {@see self::ulid()} and {@see self::uuidV7()} (spec r18, RFC-0003) — and the
 * FR-56 batch: identifier validators, display masking, truncation, and the `snake_case()` /
 * `camelCase()` pair completing the case family {@see self::pascalCase()} opened (spec r30,
 * RFC-0004, roadmap item 15.1) — and the FR-57 batch: line-ending normalization, codepoint-safe
 * `length()`/`substr()`, and the `before()`/`after()`/`between()` segment-extraction family
 * (spec r31, RFC-0004, roadmap item 15.2).
 *
 * **This class holds no state, and one test asserts it.** Every method is a pure function of its
 * arguments and the CSPRNG. That is load-bearing for FR-46: guaranteeing that two identifiers
 * drawn in the same millisecond sort in generation order requires remembering the previous call,
 * and this class deliberately remembers nothing (ADR-0063).
 */
final class Str
{
    /**
     * The alphabet {@see self::random()} draws from when the caller does not supply one:
     * unambiguous alphanumerics, upper- and lowercase.
     */
    private const DEFAULT_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /**
     * Crockford's Base32, the ULID specification's encoding: the digits and uppercase letters
     * with `I`, `L`, `O` and `U` removed — the first three because they are confusable with `1`
     * and `0` when read aloud or transcribed, the fourth to avoid accidental obscenities.
     *
     * Order is the specification's and is what makes a ULID's lexicographic sort agree with its
     * numeric one: the alphabet is monotonically increasing in ASCII, so comparing the encoded
     * strings byte by byte compares the underlying integers.
     */
    private const CROCKFORD_BASE32 = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * The largest instant a 48-bit millisecond timestamp can carry — 10889-08-02T05:31:50.655Z.
     * Both FR-46 identifier formats spend exactly 48 bits on time, so this ceiling is theirs
     * rather than this library's choice.
     */
    private const MAX_TIMESTAMP_MS = 281474976710655;

    /**
     * RFC 9562 textual form, with the version nibble captured: 8-4-4-12 hex groups, a version
     * digit `1`–`8` opening the third group, and an RFC 4122 variant (`8`/`9`/`a`/`b`) opening
     * the fourth — the two bit fields every defined UUID version fixes at those offsets.
     * `Nil`/`Max` UUIDs (all-zero / all-`f`) and the historical Microsoft variant are
     * deliberately outside this pattern: they carry no version to validate against.
     */
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-([1-8])[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /**
     * A ULID (spec-conformant): 26 characters from Crockford's Base32, case-insensitively (the
     * specification requires decoders to accept either case even though {@see self::ulid()}
     * only ever emits uppercase). **The first character is restricted to `0`–`7`** — the two
     * always-zero padding bits {@see self::ulid()}'s docblock names leave only 3 bits of real
     * timestamp in that position, so `8`–`Z` there can never be produced by an encoder and
     * names a value outside the 48-bit timestamp range (the ULID specification's own
     * "overflow" boundary, `7ZZZZZZZZZZZZZZZZZZZZZZZZZ` being the largest valid value).
     */
    private const ULID_PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i';

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
        $ascii = \strtolower(self::transliterateToAscii($value));
        $collapsed = \preg_replace('/[^a-z0-9]+/', $separator, $ascii) ?? '';

        if ($separator === '') {
            return $collapsed;
        }

        // trim() treats its second argument as a character LIST, not a substring, so it would
        // mis-trim a multi-character separator. Strip the separator as a literal run instead.
        $quoted = \preg_quote($separator, '/');

        return \preg_replace("/^(?:{$quoted})+|(?:{$quoted})+\$/", '', $collapsed) ?? '';
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
        $bytes = \random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        $hex = \bin2hex($bytes);

        return \sprintf(
            '%s-%s-%s-%s-%s',
            \substr($hex, 0, 8),
            \substr($hex, 8, 4),
            \substr($hex, 12, 4),
            \substr($hex, 16, 4),
            \substr($hex, 20, 12),
        );
    }

    /**
     * Whether `$value` is a syntactically valid UUID: RFC 9562's 8-4-4-12 hex layout, a version
     * digit `1`–`8` and the RFC 4122 variant bits (spec r30 FR-56, RFC-0004).
     *
     * A **predicate, not a sanitizer** — it never rewrites, and it never throws on a malformed
     * `$value` (a validator that can fail two ways forces every caller to handle both; returning
     * `false` for anything not conforming is the one answer a boundary check needs). Pass
     * `$version` to additionally pin the version (`Str::isUuid($id, version: 7)` for FR-46's
     * `uuidV7()`, `version: 4` for {@see self::uuid()}) — an out-of-range `$version` itself is a
     * caller error and does throw, since that argument is not the value under test.
     *
     * **Deliberately excluded**: the Nil UUID (all zeros), the Max UUID (all `f`), and the
     * historical Microsoft variant — none carries a version this method can check against, and
     * accepting them under `$version = null` would make "valid UUID" mean "128 bits formatted
     * like one" rather than "a UUID some defined version actually produced."
     *
     * @throws InvalidArgumentException if `$version` is given and outside `1`–`8`
     */
    public static function isUuid(string $value, ?int $version = null): bool
    {
        if ($version !== null && ($version < 1 || $version > 8)) {
            throw new InvalidArgumentException(\sprintf(
                '$version must be between 1 and 8, got %d.',
                $version,
            ));
        }

        if (\preg_match(self::UUID_PATTERN, $value, $matches) !== 1) {
            return false;
        }

        return $version === null || (int) $matches[1] === $version;
    }

    /**
     * A ULID: a 26-character, time-sortable identifier (spec r18 FR-46, RFC-0003; ADR-0063).
     *
     * 128 bits — a 48-bit millisecond timestamp followed by 80 bits from {@see random_bytes()} —
     * encoded in Crockford's Base32. Because that alphabet ascends in ASCII and time occupies the
     * leading bits, **sorting the strings sorts them by generation time**: the property that makes
     * these usable as primary keys where a v4 UUID fragments a B-tree index.
     *
     * The clock is injectable so a test can pin a known instant; passing nothing reads the system
     * clock ({@see SystemClock}, spec FR-45).
     *
     * **Ordering within a single millisecond is explicitly not guaranteed** — two ULIDs drawn from
     * the same millisecond share a timestamp prefix and are ordered only by their random tails,
     * which is to say not ordered at all. Guaranteeing otherwise would require this class to
     * remember its previous call, and a static method holding cross-call state is the shape this
     * library refuses everywhere else. The index locality that motivates the format is a
     * millisecond-granularity property and is unaffected. Both halves of this are pinned by test.
     *
     * @throws InvalidArgumentException if the clock reports an instant before the Unix epoch or
     *                                   beyond what 48 bits of milliseconds can carry — neither is
     *                                   representable, and silently truncating would produce an
     *                                   identifier that sorts wrongly rather than one that fails.
     */
    public static function ulid(?ClockInterface $clock = null): string
    {
        $milliseconds = self::millisecondsFrom($clock, __FUNCTION__);

        // The two halves encode independently because both are whole multiples of five bits:
        // 48 bits of time into ten characters (the top two of the fifty are always zero, which is
        // why a ULID's first character never exceeds '7'), and 80 bits of entropy into sixteen.
        $encoded = '';
        for ($i = 9; $i >= 0; $i--) {
            $encoded = self::CROCKFORD_BASE32[$milliseconds & 31] . $encoded;
            $milliseconds >>= 5;
        }

        return $encoded . self::encodeBase32(\random_bytes(10));
    }

    /**
     * A version 7 UUID: RFC 9562's time-ordered layout, in the familiar 36-character form
     * (spec r18 FR-46, RFC-0003; ADR-0063).
     *
     * 48 bits of big-endian millisecond timestamp, the version nibble `7`, the variant bits `10`,
     * and the remaining 74 bits from {@see random_bytes()}. Sorts by generation time for the same
     * reason {@see self::ulid()} does, and is the answer where a consumer needs the sortability
     * but a UUID-shaped column: it is a valid UUID everywhere {@see self::uuid()} is.
     *
     * The same clock injection, the same 48-bit range refusal, and the **same explicit
     * non-guarantee of ordering within one millisecond** as `ulid()` — see there for why.
     *
     * @throws InvalidArgumentException if the instant is before the Unix epoch or beyond 48 bits
     */
    public static function uuidV7(?ClockInterface $clock = null): string
    {
        $milliseconds = self::millisecondsFrom($clock, __FUNCTION__);

        // `J` packs 64 bits big-endian; the low six bytes are the 48-bit timestamp the format
        // asks for. The version and variant nibbles are then stamped over random bytes exactly as
        // self::uuid() stamps them, because RFC 9562 places them at the same offsets for every
        // version.
        $bytes = \substr(\pack('J', $milliseconds), 2) . \random_bytes(10);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        $hex = \bin2hex($bytes);

        return \sprintf(
            '%s-%s-%s-%s-%s',
            \substr($hex, 0, 8),
            \substr($hex, 8, 4),
            \substr($hex, 12, 4),
            \substr($hex, 16, 4),
            \substr($hex, 20, 12),
        );
    }

    /**
     * Whether `$value` is a syntactically valid ULID: 26 characters, Crockford Base32,
     * case-insensitive (spec r30 FR-56, RFC-0004).
     *
     * **The overflow boundary is enforced, not just the alphabet and length** — the first
     * character is refused outside `0`–`7` (see {@see self::ULID_PATTERN}'s docblock for why
     * that is exactly the range {@see self::ulid()} can produce), so a string that is
     * alphabet-and-length-correct but names an instant beyond the 48-bit timestamp range is
     * rejected rather than accepted as "close enough." A predicate, not a sanitizer: never
     * throws, never rewrites.
     */
    public static function isUlid(string $value): bool
    {
        return \preg_match(self::ULID_PATTERN, $value) === 1;
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
            throw new InvalidArgumentException(\sprintf('$length must be >= 0, got %d.', $length));
        }

        $alphabetLength = \strlen($alphabet);
        if ($alphabetLength < 2) {
            throw new InvalidArgumentException('$alphabet must contain at least two characters.');
        }

        $token = '';
        for ($i = 0; $i < $length; $i++) {
            $token .= $alphabet[\random_int(0, $alphabetLength - 1)];
        }

        return $token;
    }

    /**
     * Trims the value and collapses every internal run of whitespace to a single space.
     *
     * Whitespace here is the ASCII set (`space`, `\t`, `\n`, `\r`, `\f`, vertical tab) — the
     * bytes `trim()` and PCRE's un-flagged `\s` agree on. That byte-oriented match is
     * deliberately **UTF-8-safe without a `u` flag**: every byte of a multibyte UTF-8 sequence
     * is `>= 0x80`, so an ASCII-set match can never split one. Unicode spaces (NBSP, U+2028…)
     * pass through untouched; a caller who needs those normalized transcodes or replaces them
     * explicitly first, rather than this method guessing.
     */
    public static function collapseWhitespace(string $value): string
    {
        return \preg_replace('/\s+/', ' ', \trim($value)) ?? '';
    }

    /**
     * `null` when the value is `null` or contains nothing but whitespace; the value itself,
     * **unmodified**, otherwise.
     *
     * Blankness is judged by `trim()`, but the non-blank return is deliberately not trimmed —
     * this method answers exactly one question ("is there content?") and mutates nothing, so it
     * composes without surprises: `Str::nullIfBlank(Str::collapseWhitespace($x))` is the
     * common cleanup pipeline, each step doing its one job.
     */
    public static function nullIfBlank(?string $value): ?string
    {
        if ($value === null || \trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * Normalizes every line ending in `$value` to `$target` (spec r31 FR-57, RFC-0004).
     *
     * All three endings a text file can carry are recognized on the way in — `\r\n`, a lone
     * `\r` (the classic Mac OS 9 ending, the one every "CRLF vs LF" helper forgets), and `\n`
     * itself — and collapsed to `\n` first before expanding to `$target`. **Order is
     * load-bearing**: collapsing `\r\n` before a lone `\r` is what keeps a Windows line ending
     * from leaving a stray `\n` behind; doing it the other way corrupts every CRLF in the input.
     *
     * Idempotent for the same reason `snakeCase()` is: after the first pass there is no `\r`
     * left standing alone for a second pass to find (a target of `Eol::CrLf` re-collapses its
     * own output before re-expanding it, byte-identically). A value already in `$target`'s form
     * is returned unchanged — this method never rewrites what does not need rewriting.
     */
    public static function normalizeEol(string $value, Eol $target = Eol::Lf): string
    {
        $lf = \str_replace(["\r\n", "\r"], "\n", $value);

        return $target === Eol::Lf ? $lf : \str_replace("\n", $target->value, $lf);
    }

    /**
     * The substring of `$value` before the first occurrence of `$needle`, or `null` when
     * `$needle` does not occur (spec r31 FR-57, RFC-0004).
     *
     * **Returns `null` on a miss, never `$value` unchanged.** Handing back the untouched subject
     * is a silent guess about what the caller wanted done with a needle that was not there;
     * `null` is the probing arm of this library's missing-value grammar ({@see Lookup}'s
     * `tryLabel()` precedent) and puts the decision back where it belongs. Paired with
     * {@see self::after()}, the identity `before($s, $n) . $n . after($s, $n) === $s` holds
     * whenever both return non-`null` — proven by construction (both are computed from the same
     * `strpos()` call's position), not merely tested for a handful of cases.
     *
     * Operates on bytes (`strpos()`/`substr()`), which is exactly what keeps this multibyte-safe
     * **without** a `u`-mode dependency: a cut only ever happens at a byte offset where `$needle`
     * itself was found, and a valid UTF-8 needle can only occur at valid UTF-8 boundaries — so
     * neither returned half can end mid-character.
     *
     * @throws InvalidArgumentException if `$needle` is empty
     */
    public static function before(string $value, string $needle): ?string
    {
        self::refuseEmptyNeedle($needle);
        $position = \strpos($value, $needle);

        return $position === false ? null : \substr($value, 0, $position);
    }

    /**
     * The substring of `$value` after the first occurrence of `$needle`, or `null` when
     * `$needle` does not occur (spec r31 FR-57, RFC-0004). See {@see self::before()} for the
     * missing-value rationale and the multibyte-safety argument; only the side differs.
     *
     * @throws InvalidArgumentException if `$needle` is empty
     */
    public static function after(string $value, string $needle): ?string
    {
        self::refuseEmptyNeedle($needle);
        $position = \strpos($value, $needle);

        return $position === false ? null : \substr($value, $position + \strlen($needle));
    }

    /**
     * The substring of `$value` before the **last** occurrence of `$needle`, or `null` when
     * `$needle` does not occur (spec r31 FR-57, RFC-0004). {@see self::before()}'s rationale and
     * multibyte-safety argument apply unchanged; only which occurrence is searched differs.
     *
     * @throws InvalidArgumentException if `$needle` is empty
     */
    public static function beforeLast(string $value, string $needle): ?string
    {
        self::refuseEmptyNeedle($needle);
        $position = \strrpos($value, $needle);

        return $position === false ? null : \substr($value, 0, $position);
    }

    /**
     * The substring of `$value` after the **last** occurrence of `$needle`, or `null` when
     * `$needle` does not occur (spec r31 FR-57, RFC-0004). {@see self::before()}'s rationale and
     * multibyte-safety argument apply unchanged; only which occurrence is searched differs.
     *
     * @throws InvalidArgumentException if `$needle` is empty
     */
    public static function afterLast(string $value, string $needle): ?string
    {
        self::refuseEmptyNeedle($needle);
        $position = \strrpos($value, $needle);

        return $position === false ? null : \substr($value, $position + \strlen($needle));
    }

    /**
     * The substring of `$value` strictly between the first occurrence of `$start` and the next
     * occurrence of `$end` that follows it, or `null` when either does not occur in that order
     * (spec r31 FR-57, RFC-0004).
     *
     * `$end` is searched for **starting after `$start`'s match**, not merely "anywhere in
     * `$value`" — so `between('<a><b>', '<', '>')` finds `a`, the first complete tag, rather than
     * treating the whole string as one span. `$start === $end` is legal and composes as expected
     * (`between('a,b,c', ',', ',')` is `'b'`, the first delimited segment).
     *
     * @throws InvalidArgumentException if `$start` or `$end` is empty
     */
    public static function between(string $value, string $start, string $end): ?string
    {
        self::refuseEmptyNeedle($start, '$start');
        self::refuseEmptyNeedle($end, '$end');

        $startPosition = \strpos($value, $start);
        if ($startPosition === false) {
            return null;
        }

        $contentStart = $startPosition + \strlen($start);
        $endPosition = \strpos($value, $end, $contentStart);
        if ($endPosition === false) {
            return null;
        }

        return \substr($value, $contentStart, $endPosition - $contentStart);
    }

    /**
     * Whether `$value` contains any one of `$needles` (spec r31 FR-57, RFC-0004) — the
     * multi-needle form `str_contains()` does not cover, so callers stop hand-rolling a
     * `foreach` loop over an allowlist of tokens to check for.
     *
     * **Every needle is validated before any is matched** — checking lazily during the search
     * would make the refusal depend on which needle happens to match first: an empty string
     * *after* one that matches would silently never be reached and never refused, so the same
     * malformed `$needles` list would throw or not depending on argument order alone.
     *
     * @param list<string> $needles
     *
     * @throws InvalidArgumentException if `$needles` contains an empty string — native
     *                                   `str_contains($x, '')` is unconditionally `true`, which
     *                                   would make one blank entry (a stray config line, an
     *                                   off-by-one `explode()`) silently satisfy every call
     */
    public static function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            self::refuseEmptyNeedle($needle, '$needles');
        }

        foreach ($needles as $needle) {
            if (\str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The length of `$value` in **Unicode code points**, not bytes (spec r31 FR-57, RFC-0004) —
     * a public name for what {@see self::countCodePoints()} already computed privately for
     * {@see self::padLeft()}/{@see self::padRight()}, exposed because the estate hand-rolls
     * `preg_split('//u', $x)` counts in more than one place (the Mail group's RFC 2047 folding
     * did exactly this) and one tested home beats N private copies.
     *
     * @throws InvalidArgumentException if `$value` is not valid UTF-8 — a length computed over
     *                                   broken encoding is not a length, {@see self::transcode()}'s
     *                                   strict-by-default stance applied here
     */
    public static function length(string $value): int
    {
        return self::countCodePoints($value, '$value');
    }

    /**
     * `substr()`'s exact contract — negative `$start` counts from the end, a negative `$length`
     * omits that many trailing characters, `null` means "to the end" — evaluated in **Unicode
     * code points** rather than bytes (spec r31 FR-57, RFC-0004).
     *
     * Built on `array_slice()` rather than reimplementing the offset arithmetic: `array_slice()`
     * already defines the identical negative-offset/negative-length semantics over a list, so
     * applying it to {@see self::codePoints()}'s list gets `substr()`'s full contract for free,
     * at code-point granularity, with nothing to get subtly wrong a second time.
     *
     * @throws InvalidArgumentException if `$value` is not valid UTF-8
     */
    public static function substr(string $value, int $start, ?int $length = null): string
    {
        return \implode('', \array_slice(self::codePoints($value, '$value'), $start, $length));
    }

    /**
     * Masks the middle of `$value`, keeping `$keepStart` characters at the start and `$keepEnd`
     * at the end — `mask('4111111111111111', keepEnd: 4)` → `****1111` (spec r30 FR-56,
     * RFC-0004).
     *
     * **The masked segment has a fixed length (`$maskLength`), independent of how much was
     * actually hidden.** That is the security property, not a formatting choice: a masked value
     * whose *length* still varies with the input's length leaks exactly what masking is meant to
     * hide — `***@x.com` versus `*********@x.com` already tells an observer the local part's
     * length even though every character of it is gone. Keeping the mask a constant width closes
     * that channel.
     *
     * Counts are **Unicode code points**, not bytes (`mb_strlen()`'s semantics without an
     * `ext-mbstring` dependency — {@see self::padLeft()}'s house mechanism, reused).
     *
     * **Refuses rather than silently returning the value unmasked**: when `$keepStart +
     * $keepEnd` covers the whole value, there is nothing left to mask and the "masked" result
     * would just be the original value — a caller who asked for masking and silently got none is
     * the failure mode this method exists to prevent. The refusal message states the character
     * *counts* only; `$value` itself never appears in an exception, so a caller logging the
     * failure cannot accidentally log the secret it was trying to mask.
     *
     * @throws InvalidArgumentException if `$keepStart`, `$keepEnd`, or `$maskLength` is
     *                                   negative; if `$maskChar` is not exactly one character;
     *                                   if `$value`/`$maskChar` is not valid UTF-8; or if
     *                                   `$keepStart + $keepEnd` covers the whole value
     */
    public static function mask(
        string $value,
        int $keepStart = 0,
        int $keepEnd = 0,
        string $maskChar = '*',
        int $maskLength = 4,
    ): string {
        if ($keepStart < 0) {
            throw new InvalidArgumentException(\sprintf('$keepStart must be >= 0, got %d.', $keepStart));
        }

        if ($keepEnd < 0) {
            throw new InvalidArgumentException(\sprintf('$keepEnd must be >= 0, got %d.', $keepEnd));
        }

        if ($maskLength < 0) {
            throw new InvalidArgumentException(\sprintf('$maskLength must be >= 0, got %d.', $maskLength));
        }

        if (\count(self::codePoints($maskChar, '$maskChar')) !== 1) {
            throw new InvalidArgumentException('$maskChar must be exactly one character.');
        }

        $points = self::codePoints($value, '$value');
        $length = \count($points);

        if ($keepStart + $keepEnd >= $length) {
            throw new InvalidArgumentException(\sprintf(
                'Str::mask(): keeping %d character(s) at the start and %d at the end leaves '
                . 'nothing to mask in a %d-character value.',
                $keepStart,
                $keepEnd,
                $length,
            ));
        }

        $prefix = \implode('', \array_slice($points, 0, $keepStart));
        $suffix = $keepEnd > 0 ? \implode('', \array_slice($points, -$keepEnd)) : '';

        return $prefix . \str_repeat($maskChar, $maskLength) . $suffix;
    }

    /**
     * Truncates `$value` to at most `$length` **characters** (Unicode code points), appending
     * `$suffix` only when truncation actually happens (spec r30 FR-56, RFC-0004).
     *
     * The suffix is accounted for *inside* the budget: the result of a truncated value is never
     * longer than `$length` characters including the suffix, so `truncate($x, 20)` never
     * produces something 21+ characters wide because a `…` was tacked on afterward. A `$length`
     * that already fits returns `$value` unchanged — no suffix is appended to a value that was
     * never cut.
     *
     * **Refuses rather than truncating past the suffix**: a `$length` shorter than `$suffix`
     * itself cannot produce a sensible result (there is no room left for any of the original
     * value), and returning a bare fragment of the suffix would be silent nonsense rather than a
     * signal that the caller's budget and suffix disagree.
     *
     * @throws InvalidArgumentException if `$length` is negative; if `$value`/`$suffix` is not
     *                                   valid UTF-8; or if `$length` is shorter than `$suffix`
     */
    public static function truncate(string $value, int $length, string $suffix = '…'): string
    {
        if ($length < 0) {
            throw new InvalidArgumentException(\sprintf('$length must be >= 0, got %d.', $length));
        }

        $points = self::codePoints($value, '$value');

        if (\count($points) <= $length) {
            return $value;
        }

        $suffixPoints = self::codePoints($suffix, '$suffix');
        $suffixLength = \count($suffixPoints);

        if ($length < $suffixLength) {
            throw new InvalidArgumentException(\sprintf(
                'Str::truncate(): a length of %d cannot fit a %d-character suffix.',
                $length,
                $suffixLength,
            ));
        }

        return \implode('', \array_slice($points, 0, $length - $suffixLength)) . $suffix;
    }

    /**
     * Converts `$value` from `$from` to `$to` (default UTF-8) via `iconv`.
     *
     * **Strict by default**: input the source encoding cannot parse, or characters the target
     * cannot represent, throw a {@see UtilsException} — silently dropping bytes is data loss,
     * and data loss must be opted into, never defaulted into (the same honesty rule as
     * `Escaper`'s U+FFFD substitution, ADR-0019). Pass `$lossy = true` to drop what the target
     * cannot represent (`//IGNORE`).
     *
     * `ext-iconv` is a **suggested** dependency: on a build without it this method refuses
     * with a clear message rather than degrading (the `Sanitizer::richText()` /
     * `Hash` fail-fast pattern, ADR-0021/ADR-0022). It is not `require`d because nothing else
     * in the core needs it and NFR-08 keeps the dependency floor where it is.
     *
     * @throws UtilsException when `ext-iconv` is absent, when an encoding name is unknown, or
     *                        — in strict mode — when the value does not survive conversion
     *                        losslessly
     */
    public static function transcode(
        string $value,
        string $from,
        string $to = 'UTF-8',
        bool $lossy = false,
    ): string {
        if (!\function_exists('iconv')) {
            throw new UtilsException(
                'Str::transcode() requires ext-iconv, which is not loaded. Install/enable '
                . 'ext-iconv (composer.json lists it under "suggest").',
            );
        }

        // Probe the encoding pair on the empty string first: iconv() signals "unknown
        // encoding" and "unconvertible input" identically (false + a notice), and the empty
        // string can only fail for the first reason — so the two failures stay distinguishable
        // in the message.
        if (@\iconv($from, $to, '') === false) {
            throw new UtilsException(\sprintf(
                'Str::transcode(): unknown encoding in pair "%s" -> "%s".',
                $from,
                $to,
            ));
        }

        $target = $lossy ? $to . '//IGNORE' : $to;
        $result = @\iconv($from, $target, $value);

        if ($result === false) {
            throw new UtilsException(\sprintf(
                'Str::transcode(): value is not losslessly convertible from "%s" to "%s" '
                . '(invalid byte sequence or unrepresentable character). '
                . 'Pass $lossy = true to drop unrepresentable characters explicitly.',
                $from,
                $to,
            ));
        }

        return $result;
    }

    /**
     * Left-pads to `$length` **characters** (Unicode code points), not bytes.
     *
     * `str_pad()` counts bytes, so it under-pads any multibyte string — `str_pad('héllo', 7)`
     * adds one space, not two. This method counts code points via PCRE (always available; no
     * `ext-mbstring` dependency) and follows the semantics of PHP 8.3's `mb_str_pad()`, so a
     * consumer on a newer floor can migrate to the native function without a behavior change:
     * a `$length` at or below the current character count returns the value unchanged, and an
     * empty `$pad` is refused.
     *
     * @throws InvalidArgumentException if `$pad` is empty, or `$value`/`$pad` is not valid UTF-8
     */
    public static function padLeft(string $value, int $length, string $pad = ' '): string
    {
        return self::pad($value, $length, $pad, STR_PAD_LEFT);
    }

    /**
     * Right-pads to `$length` **characters** (Unicode code points), not bytes.
     *
     * See {@see self::padLeft()} for the semantics; only the side differs.
     *
     * @throws InvalidArgumentException if `$pad` is empty, or `$value`/`$pad` is not valid UTF-8
     */
    public static function padRight(string $value, int $length, string $pad = ' '): string
    {
        return self::pad($value, $length, $pad, STR_PAD_RIGHT);
    }

    /**
     * The class name after the final namespace separator — `D4np\Utils\Support\Str` → `Str`.
     *
     * Accepts an object (its concrete class is used) or any backslash-separated name; the
     * string form is **not** required to name a loaded class, so the helper works on names
     * from configuration or logs without autoloading anything. An anonymous class returns the
     * literal `class@anonymous`: its runtime name embeds a NUL byte and the defining file
     * path, which is platform-shaped — one deterministic answer beats a fragment of a path.
     *
     * @throws InvalidArgumentException if the string is empty or ends in a separator (no name
     *                                   remains after the final `\`)
     */
    public static function shortClassName(object|string $class): string
    {
        $name = \is_object($class) ? \get_class($class) : $class;

        // Anonymous-class runtime names embed a NUL byte and the defining FILE PATH — which
        // contains backslashes on Windows, so naive last-separator surgery would return a
        // platform-dependent fragment. One deterministic answer on every platform instead.
        if (\str_starts_with($name, 'class@anonymous')) {
            return 'class@anonymous';
        }

        $position = \strrpos($name, '\\');
        $short = $position === false ? $name : \substr($name, $position + 1);

        if ($short === '') {
            throw new InvalidArgumentException(\sprintf(
                'shortClassName(): "%s" carries no class name after the final "\\".',
                $name,
            ));
        }

        return $short;
    }

    /**
     * PascalCase from words separated by whitespace, underscores, or hyphens —
     * `"order line"` / `"order_line"` / `"ORDER-LINE"` → `OrderLine`.
     *
     * Case mapping is ASCII-only (`strtolower`/`ucwords`): multibyte characters pass through
     * unchanged rather than being half-mapped byte by byte. Word boundaries are the three
     * separator classes named above — an already-PascalCase input is therefore a single word
     * and comes back with only its first letter guaranteed uppercase, not re-split.
     */
    public static function pascalCase(string $value): string
    {
        $worded = \preg_replace('/[\s_\-]+/', ' ', \trim($value)) ?? '';

        return \str_replace(' ', '', \ucwords(\strtolower($worded)));
    }

    /**
     * `snake_case` from words separated by whitespace, hyphens, underscores, **or camelCase /
     * PascalCase transitions** — `"OrderLine"` / `"orderLine"` / `"order line"` all become
     * `"order_line"` (spec r30 FR-56, RFC-0004). The DB-column ↔ property-name conversion the
     * estate hand-rolls per project; {@see self::camelCase()} is its inverse, sharing the same
     * word-splitting engine so the two agree about where one word ends and the next begins.
     *
     * **Acronym runs stay one word**: `"APIKey"` → `"api_key"`, not `"a_p_i_key"` — a run of
     * uppercase letters splits *before its last member* only when that member opens a new
     * lowercase word (`…API` + `Key…` → `…API_Key…`), the same two-pass rule most "decamelize"
     * implementations use. **A digit stays attached to the letters before it** unless directly
     * followed by an uppercase letter, which does start a new word: `"line2Item"` →
     * `"line2_item"`, not `"line_2_item"` — a documented, tested choice among two defensible
     * ones, not an oversight.
     *
     * Case mapping is ASCII-only, `pascalCase()`'s existing rule: multibyte characters inside a
     * word pass through unchanged. Idempotent: an already-`snake_case` input has no separators to
     * normalize and no case transitions to split, so it returns unchanged.
     */
    public static function snakeCase(string $value): string
    {
        return \implode('_', self::splitWords($value));
    }

    /**
     * `camelCase` from the same word boundaries {@see self::snakeCase()} recognizes — the
     * property-name ↔ DB-column conversion's other direction (spec r30 FR-56, RFC-0004).
     *
     * **Deliberately not built on {@see self::pascalCase()}**: that method treats an
     * already-camelCase input as a single word (documented there as intentional, and pinned by
     * its own test), which would silently flatten `"orderLine"` to `"orderline"` before this
     * method could re-capitalize it. `camelCase()` instead shares {@see self::snakeCase()}'s
     * word splitter, so `camelCase(snakeCase($x)) === $x` holds for any `$x` already in
     * camelCase — the round-trip a `#[MapFrom]` convention (roadmap item 15.4) needs to be able
     * to rely on.
     *
     * The first word is lowercased as a whole (not merely its first character): an
     * acronym-leading word from {@see self::snakeCase()}'s split — `"api_key"`'s `"api"` — comes
     * back as `"apiKey"`, never `"aPIKey"`.
     */
    public static function camelCase(string $value): string
    {
        $words = self::splitWords($value);

        if ($words === []) {
            return '';
        }

        $first = \array_shift($words);
        foreach ($words as $word) {
            $first .= \ucfirst($word);
        }

        return $first;
    }

    /**
     * The shared engine behind {@see self::padLeft()} / {@see self::padRight()}: mb_str_pad()
     * semantics over PCRE code-point counting, so the promise ("characters, not bytes") holds
     * with no extension dependency.
     */
    private static function pad(string $value, int $length, string $pad, int $side): string
    {
        if ($pad === '') {
            throw new InvalidArgumentException('$pad must not be empty.');
        }

        $valueChars = self::countCodePoints($value, '$value');
        $deficit = $length - $valueChars;

        if ($deficit <= 0) {
            return $value;
        }

        $padChars = self::countCodePoints($pad, '$pad');
        $repeated = \str_repeat($pad, (int) \ceil($deficit / $padChars));
        $codePoints = \preg_split('//u', $repeated, -1, PREG_SPLIT_NO_EMPTY);
        $padding = \implode('', \array_slice($codePoints === false ? [] : $codePoints, 0, $deficit));

        return $side === STR_PAD_LEFT ? $padding . $value : $value . $padding;
    }

    /**
     * Code points in `$value`, throwing (with the offending parameter named) on invalid UTF-8 —
     * a padder that miscounts mojibake would pad it wrongly and silently.
     */
    private static function countCodePoints(string $value, string $parameter): int
    {
        $count = \preg_match_all('/./su', $value);

        if ($count === false) {
            throw new InvalidArgumentException(\sprintf('%s is not valid UTF-8.', $parameter));
        }

        return $count;
    }

    /**
     * `$value` split into its Unicode code points, throwing (with the offending parameter
     * named) on invalid UTF-8 — the same contract {@see self::countCodePoints()} pins for a
     * plain count, exposed here as a list because {@see self::mask()} and
     * {@see self::truncate()} need to slice by code point, not merely count them.
     *
     * @return list<string>
     */
    private static function codePoints(string $value, string $parameter): array
    {
        $points = \preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        if ($points === false) {
            throw new InvalidArgumentException(\sprintf('%s is not valid UTF-8.', $parameter));
        }

        return $points;
    }

    /**
     * The word-splitting engine shared by {@see self::snakeCase()} and {@see self::camelCase()}
     * — every existing separator (whitespace, hyphen, underscore) normalized to one boundary,
     * then a camelCase/acronym-aware boundary inserted before lowercasing and exploding. Kept as
     * one function so the two public methods can never disagree about where a word starts.
     *
     * @return list<string> lowercase words, in order; empty when `$value` has no content
     */
    private static function splitWords(string $value): array
    {
        $normalized = \trim(\preg_replace('/[\s_\-]+/', '_', \trim($value)) ?? '', '_');

        if ($normalized === '') {
            return [];
        }

        // Pass 1: a lowercase letter or digit immediately followed by an uppercase letter opens
        // a new word ("orderLine" -> "order_Line"; "line2Item" -> "line2_Item" — the digit stays
        // with what precedes it since this pass never looks at digits on the RIGHT).
        $withBoundaries = \preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $normalized) ?? $normalized;

        // Pass 2: an acronym run (2+ uppercase letters) splits before its LAST member when that
        // member opens a new lowercase word — "APIKey" -> "API_Key", not "A_P_I_Key" (pass 1
        // alone never fires here: no lowercase/digit precedes any of "API"'s own letters).
        $withBoundaries = \preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $withBoundaries) ?? $withBoundaries;

        $collapsed = \trim(\preg_replace('/_+/', '_', $withBoundaries) ?? $withBoundaries, '_');

        if ($collapsed === '') {
            return [];
        }

        return \array_map(
            static fn (string $word): string => \strtolower($word),
            \explode('_', $collapsed),
        );
    }

    /**
     * The shared refusal behind {@see self::before()}/{@see self::after()}/
     * {@see self::beforeLast()}/{@see self::afterLast()}/{@see self::between()}: an empty
     * needle would make `strpos()` report a match at position 0 of everything, which is not "the
     * needle was found" in any sense a caller meant.
     */
    private static function refuseEmptyNeedle(string $needle, string $parameter = '$needle'): void
    {
        if ($needle === '') {
            throw new InvalidArgumentException(\sprintf('%s must not be empty.', $parameter));
        }
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
        if (!\function_exists('transliterator_transliterate')) {
            return null;
        }

        $result = @\transliterator_transliterate('Any-Latin; Latin-ASCII', $value);

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
        if (!\function_exists('iconv')) {
            return null;
        }

        $result = @\iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $result === false ? null : $result;
    }

    /**
     * Tier 3, the last resort with no extension dependency at all: keep only printable ASCII
     * and drop the rest. Never fails, never returns `null` — {@see self::transliterateToAscii()}
     * always terminates here.
     */
    private static function viaAsciiFilter(string $value): string
    {
        return \preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';
    }

    /**
     * Milliseconds since the Unix epoch, from the given clock or the system one, refused when
     * outside what 48 bits can carry.
     *
     * `U * 1000 + v` rather than a float `microtime()`: `v` is always the 0–999 milliseconds
     * *within* the second and `U` is the whole second, so the arithmetic stays exact and stays
     * correct for pre-epoch instants (where `U` is negative and `v` is not) — which is precisely
     * how such an instant is detected below instead of silently wrapping.
     *
     * @param non-empty-string $method the caller's name, so the refusal names the API the
     *                                 consumer actually called rather than this private helper
     *
     * @throws InvalidArgumentException if the instant is not representable in 48 bits
     */
    private static function millisecondsFrom(?ClockInterface $clock, string $method): int
    {
        $now = ($clock ?? new SystemClock())->now();
        $milliseconds = ((int) $now->format('U')) * 1000 + ((int) $now->format('v'));

        if ($milliseconds < 0 || $milliseconds > self::MAX_TIMESTAMP_MS) {
            throw new InvalidArgumentException(\sprintf(
                'Str::%s() needs an instant between the Unix epoch and %s, got %s.',
                $method,
                (new DateTimeImmutable('@281474976710'))->format('Y-m-d\TH:i:s\Z'),
                $now->format(DateTimeImmutable::ATOM),
            ));
        }

        return $milliseconds;
    }

    /**
     * Crockford Base32 over a byte string whose bit count is a multiple of five.
     *
     * Callers pass ten bytes (80 bits → 16 characters, exactly), so no padding case exists and
     * none is invented: a partial final group would need a padding convention, and the two
     * formats that use this never produce one.
     */
    private static function encodeBase32(string $bytes): string
    {
        $encoded = '';
        $accumulator = 0;
        $bits = 0;

        for ($i = 0, $length = \strlen($bytes); $i < $length; $i++) {
            $accumulator = ($accumulator << 8) | \ord($bytes[$i]);
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= self::CROCKFORD_BASE32[($accumulator >> $bits) & 31];
            }
        }

        return $encoded;
    }
}
