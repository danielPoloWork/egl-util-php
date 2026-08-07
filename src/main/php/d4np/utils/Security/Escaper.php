<?php

declare(strict_types=1);

namespace D4np\Utils\Security;

/**
 * Context-aware output escaping (spec FR-09, ADR-0019).
 *
 * RFC-0001's third security mechanism: *"Output: context-aware escaping at render time, never
 * input mutilation."* The four methods here are not four spellings of one idea — they are four
 * different grammars, and a value escaped for one is **not** safe in another. `html()` output
 * inside a `<script>` block is still executable; `js()` output inside an unquoted attribute can
 * still break out. There is deliberately no general-purpose `escape()`, because the method that
 * did not need a context is the one that would get used in the wrong one.
 *
 * | method | safe in | mechanism |
 * |---|---|---|
 * | {@see html()} | element text, **quoted** attributes | `htmlspecialchars` with the pinned flags |
 * | {@see attr()} | any attribute, **including unquoted** | `&#xHH;` for every non-alphanumeric ASCII |
 * | {@see js()} | inside a JavaScript **string literal** | `\xHH` / `\uXXXX` |
 * | {@see url()} | one URL **component** | `rawurlencode` |
 *
 * **Invalid UTF-8 becomes U+FFFD, in all four, by design.** This is not incidental tidiness: it
 * is the single most load-bearing detail in the class. Called *without* `ENT_SUBSTITUTE`,
 * `htmlspecialchars()` returns an **empty string** for input containing an invalid byte sequence
 * — verified, not assumed. A template that silently renders nothing where a value should be is a
 * bug that hides; historically, malformed sequences were also an XSS vector in their own right,
 * because a browser resynchronising on a broken sequence could reinterpret the bytes around it.
 * `html()` gets that behaviour from the pinned flag; {@see attr()} and {@see js()} get it from
 * {@see toValidUtf8()}, which reproduces exactly the same U+FFFD substitution so that all four
 * methods answer identically for the same bad input.
 *
 * That substitution is implemented with PCRE rather than `mbstring`, deliberately: `mbstring` is
 * not among this library's declared extensions (`ext-pdo`, `ext-fileinfo` — spec NFR-08), and a
 * security helper is the wrong place to acquire a new hard dependency. `mb_convert_encoding()`
 * would also have substituted `?` rather than U+FFFD, disagreeing with `htmlspecialchars()`.
 */
final class Escaper
{
    /**
     * Matches one well-formed UTF-8 sequence, or — as a last alternative — any single byte.
     *
     * The final `.` is what makes the pattern total: anything that is not a valid sequence is
     * captured one byte at a time, and {@see toValidUtf8()} replaces exactly those. Overlong
     * encodings (`\xE0\x80\xAF`), surrogate halves (`\xED\xA0\x80`) and codepoints above
     * U+10FFFF (`\xF4\x90\x80\x80`) all fail the valid alternatives and are caught by it — each
     * verified directly, because "the regex looks right" is not a property a security boundary
     * should rest on.
     */
    private const UTF8_SEQUENCE = '/[\x00-\x7F]'
        . '|[\xC2-\xDF][\x80-\xBF]'
        . '|\xE0[\xA0-\xBF][\x80-\xBF]'
        . '|[\xE1-\xEC][\x80-\xBF]{2}'
        . '|\xED[\x80-\x9F][\x80-\xBF]'
        . '|[\xEE-\xEF][\x80-\xBF]{2}'
        . '|\xF0[\x90-\xBF][\x80-\xBF]{2}'
        . '|[\xF1-\xF3][\x80-\xBF]{3}'
        . '|\xF4[\x80-\x8F][\x80-\xBF]{2}'
        . '|./s';

    private function __construct()
    {
        // Static-only: no instances.
    }

    /**
     * Escape for HTML element text, or for an attribute **you have quoted**.
     *
     * The flags are spec FR-09's, verbatim, and each earns its place:
     *
     * - `ENT_QUOTES` escapes both `"` and `'`. `ENT_COMPAT` (PHP's default before 8.1) leaves `'`
     *   alone, which is safe in `attr="…"` and an XSS hole in `attr='…'`.
     * - `ENT_SUBSTITUTE` replaces invalid UTF-8 with U+FFFD instead of returning `''`. See the
     *   class docblock — this is the flag that turns silent, total data loss into a visible
     *   replacement character.
     * - `'UTF-8'` is passed explicitly rather than relying on `default_charset`, which a consumer's
     *   `php.ini` can change underneath this library.
     *
     * The flags are also *not* left to PHP's defaults even where they currently agree: PHP 8.1
     * changed the default from `ENT_COMPAT` to `ENT_QUOTES | ENT_SUBSTITUTE`, and a security
     * guarantee that holds only on some supported versions is not a guarantee.
     *
     * **Not safe** inside `<script>`, inside a `style` attribute, in an unquoted attribute, or as
     * a URL. Those are {@see js()}, a CSS context this library does not yet cover, {@see attr()}
     * and {@see url()} respectively.
     */
    public static function html(string $value): string
    {
        return \htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escape for an HTML attribute value, including one the caller did not quote.
     *
     * `html()` is sufficient for `attr="…"` and `attr='…'`. It is **not** sufficient for
     * `attr=…`, where a space, tab, newline, `/`, `>` or backtick ends the attribute and begins a
     * new one — `onmouseover=alert(1)` needs no quote character at all to get in.
     *
     * An escaper cannot see its own call site and therefore cannot know whether the caller quoted
     * the attribute. Assuming they did means being wrong in the direction of an XSS hole, so this
     * assumes they did not: **every non-alphanumeric ASCII character becomes `&#xHH;`**, which is
     * OWASP's rule for the unquoted-attribute context and is inert in the quoted one too. The
     * cost is verbosity in the rendered HTML, paid on the rare path where it is not needed rather
     * than on the common path where its absence is exploitable.
     *
     * Characters outside ASCII pass through unchanged, and that is deliberate rather than an
     * omission: every character that can terminate an attribute is ASCII, so a multibyte sequence
     * cannot be a delimiter. Escaping the individual **bytes** of one — the naive reading of
     * OWASP's "ASCII values less than 256", which predates UTF-8 being the default — would emit
     * one entity per byte and corrupt the text into mojibake.
     */
    public static function attr(string $value): string
    {
        $escaped = \preg_replace_callback(
            '/[^a-zA-Z0-9]/',
            static function (array $match): string {
                $byte = $match[0];

                // Leave the bytes of a valid multibyte sequence alone; only ASCII can delimit.
                if ($byte >= "\x80") {
                    return $byte;
                }

                return \sprintf('&#x%02X;', \ord($byte));
            },
            self::toValidUtf8($value),
        );

        return $escaped ?? '';
    }

    /**
     * Escape for use **inside a JavaScript string literal** — `var x = '<here>';`
     *
     * Three things this handles that a naive "escape quotes and backslashes" does not, each of
     * which is a real, documented break-out:
     *
     * - **`/` is escaped.** Inside a `<script>` element the HTML parser runs first and knows
     *   nothing about JavaScript string literals: the byte sequence `</script>` ends the element
     *   wherever it appears, quoted or not. Escaping `/` as `\x2F` is what stops a payload
     *   closing the block it is sitting in.
     * - **U+2028 and U+2029 are escaped**, despite being non-ASCII. `LINE SEPARATOR` and
     *   `PARAGRAPH SEPARATOR` are *line terminators* in JavaScript before ES2019, so an unescaped
     *   one ends the statement and everything after it is code. They are the reason this method
     *   cannot simply pass non-ASCII through the way {@see attr()} safely can.
     * - **Everything non-alphanumeric is escaped**, rather than a denylist of characters thought
     *   to be dangerous. A denylist in an escaper is a list of the attacks its author had heard
     *   of.
     *
     * Non-ASCII is emitted as `\uXXXX`, with a surrogate pair above U+FFFF, so the output is pure
     * ASCII and survives any charset the surrounding document is served with.
     *
     * **This does not make a value safe as JavaScript.** It makes it safe as a *string* in
     * JavaScript. Interpolating attacker-controlled data anywhere a value is not already a string
     * literal — an event-handler attribute, a `src`, `eval()`, a JSON-in-HTML block — is a
     * different problem this method does not solve.
     */
    public static function js(string $value): string
    {
        $escaped = \preg_replace_callback(
            '/[^a-zA-Z0-9]/u',
            static function (array $match): string {
                $character = $match[0];

                if (\strlen($character) === 1) {
                    return \sprintf('\\x%02X', \ord($character));
                }

                $codepoint = self::codepointOf($character);

                if ($codepoint > 0xFFFF) {
                    // JavaScript's \u escape addresses UTF-16 code units, so anything outside the
                    // BMP is written as the surrogate pair it is stored as.
                    $offset = $codepoint - 0x10000;

                    return \sprintf('\\u%04X\\u%04X', 0xD800 + ($offset >> 10), 0xDC00 + ($offset & 0x3FF));
                }

                return \sprintf('\\u%04X', $codepoint);
            },
            self::toValidUtf8($value),
        );

        return $escaped ?? '';
    }

    /**
     * Escape one **component** of a URL — a single path segment, or one query-string value.
     *
     * `rawurlencode()` per spec FR-09, which is RFC 3986 percent-encoding. Note it is not
     * `urlencode()`: that encodes a space as `+`, which is correct only in
     * `application/x-www-form-urlencoded` bodies and wrong in a path segment, where `+` is a
     * literal plus.
     *
     * **A component, not a URL.** Passing a whole URL through this encodes its `:` and `/`, so
     * `https://example.com/a` becomes `https%3A%2F%2Fexample.com%2Fa` — a relative path that
     * resolves to nothing. That is a visible, harmless failure, and it is worth stating plainly
     * *because* it is the likely misuse: the failure mode is a broken link, not a silent hole.
     *
     * It follows that this method is **not** the defence for a whole-URL sink such as
     * `href="…"`. There, the risk is a `javascript:` or `data:` scheme, and the defence is
     * validating the scheme against an allowlist — a different mechanism, not in FR-09's scope,
     * and deliberately not invented here. (Should such a value be run through this method anyway,
     * the result is inert: `javascript:alert(1)` encodes to `javascript%3Aalert%281%29`, which no
     * browser treats as a scheme.)
     */
    public static function url(string $value): string
    {
        return \rawurlencode($value);
    }

    /**
     * Replace every byte that is not part of a well-formed UTF-8 sequence with U+FFFD.
     *
     * Reproduces `ENT_SUBSTITUTE`'s behaviour for the two methods that do their own escaping, so
     * that all four answer identically for the same malformed input — asserted, not assumed.
     */
    private static function toValidUtf8(string $value): string
    {
        $valid = \preg_replace_callback(
            self::UTF8_SEQUENCE,
            static function (array $match): string {
                // A single byte above ASCII reached the `.` alternative, meaning it did not form a
                // valid sequence with its neighbours.
                if (\strlen($match[0]) === 1 && $match[0] >= "\x80") {
                    return "\u{FFFD}";
                }

                return $match[0];
            },
            $value,
        );

        return $valid ?? '';
    }

    /**
     * The Unicode codepoint of one well-formed UTF-8 character.
     *
     * Decoded by hand rather than via `mb_ord()` — see the class docblock on why `mbstring` is
     * not a dependency of this library. The input is always a single character produced by a
     * `/u` match over a string {@see toValidUtf8()} has already made well-formed.
     */
    private static function codepointOf(string $character): int
    {
        return match (\strlen($character)) {
            2 => ((\ord($character[0]) & 0x1F) << 6)
                | (\ord($character[1]) & 0x3F),
            3 => ((\ord($character[0]) & 0x0F) << 12)
                | ((\ord($character[1]) & 0x3F) << 6)
                | (\ord($character[2]) & 0x3F),
            default => ((\ord($character[0]) & 0x07) << 18)
                | ((\ord($character[1]) & 0x3F) << 12)
                | ((\ord($character[2]) & 0x3F) << 6)
                | (\ord($character[3]) & 0x3F),
        };
    }
}
