<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Security;

use D4np\Utils\Security\Escaper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Spec FR-09's four escaping contexts.
 *
 * The OWASP cheat-sheet **snapshot** suite is roadmap item 5.4 and is not this file; what is here
 * is the behavioural contract each method owes its own context, plus the break-out payloads that
 * distinguish the contexts from one another. The cases that matter most are the ones proving a
 * value escaped for one context is *not* safe in another — that separation is the entire reason
 * FR-09 asks for four methods rather than one.
 */
#[Group('T-06')]
final class EscaperTest extends TestCase
{
    // ---- html() -------------------------------------------------------------------------------

    public function testHtmlEscapesTheFiveSignificantCharacters(): void
    {
        self::assertSame('&lt;a&gt; &amp; &quot;q&quot; &#039;s&#039;', Escaper::html('<a> & "q" \'s\''));
    }

    /**
     * `ENT_COMPAT` — PHP's default before 8.1 — leaves `'` alone, which is safe in `x="…"` and an
     * XSS hole in `x='…'`. Pinning `ENT_QUOTES` is what closes that.
     */
    public function testHtmlEscapesSingleQuotesNotJustDouble(): void
    {
        self::assertSame('&#039;', Escaper::html("'"));
    }

    public function testHtmlLeavesOrdinaryTextAlone(): void
    {
        self::assertSame('Grace Hopper — héllo 漢 🙂', Escaper::html('Grace Hopper — héllo 漢 🙂'));
    }

    /**
     * The single most load-bearing detail in the class. Without `ENT_SUBSTITUTE`,
     * `htmlspecialchars()` returns an **empty string** for input containing an invalid byte
     * sequence — silent, total data loss. Verified against PHP directly, then pinned here.
     */
    public function testHtmlSubstitutesInvalidUtf8RatherThanReturningEmpty(): void
    {
        $escaped = Escaper::html("before \xC3\x28 after");

        self::assertNotSame('', $escaped);
        self::assertStringContainsString('before', $escaped);
        self::assertStringContainsString('after', $escaped);
        self::assertStringContainsString("\u{FFFD}", $escaped);
        // And what comes out is itself well-formed, so it cannot corrupt the document.
        self::assertSame(1, \preg_match('//u', $escaped));
    }

    // ---- attr() -------------------------------------------------------------------------------

    public function testAttrEscapesEveryNonAlphanumericAsciiCharacter(): void
    {
        self::assertSame('&#x20;', Escaper::attr(' '));
        self::assertSame('&#x2F;', Escaper::attr('/'));
        self::assertSame('&#x3D;', Escaper::attr('='));
        self::assertSame('&#x60;', Escaper::attr('`'));
        self::assertSame('&#x09;', Escaper::attr("\t"));
        self::assertSame('&#x0A;', Escaper::attr("\n"));
    }

    public function testAttrLeavesAlphanumericsAlone(): void
    {
        self::assertSame('abcXYZ0189', Escaper::attr('abcXYZ0189'));
    }

    /**
     * The case `html()` cannot cover. In `<div class=USER_VALUE>` a bare space ends the attribute
     * and the next token becomes a *new* attribute — no quote character is needed to inject an
     * event handler, so `html()` output is not safe here and `attr()` output is.
     */
    public function testAttrDefeatsUnquotedAttributeBreakOut(): void
    {
        $payload = 'x onmouseover=alert(1)';

        // html() lets this through intact: every character in it is one htmlspecialchars ignores.
        self::assertSame($payload, Escaper::html($payload));

        // attr() neutralises the delimiters that make it work.
        $escaped = Escaper::attr($payload);
        self::assertStringNotContainsString(' ', $escaped);
        self::assertStringNotContainsString('=', $escaped);
        self::assertStringNotContainsString('(', $escaped);
    }

    /**
     * Escaping the individual *bytes* of a multibyte character — the naive reading of OWASP's
     * "ASCII values less than 256", which predates UTF-8 — would emit one entity per byte and
     * corrupt the text. Every character that can terminate an attribute is ASCII, so passing
     * valid multibyte through is both safe and correct.
     */
    public function testAttrPassesValidMultibyteThroughWithoutMojibake(): void
    {
        self::assertSame('héllo漢🙂', Escaper::attr('héllo漢🙂'));
    }

    public function testAttrSubstitutesInvalidUtf8LikeHtmlDoes(): void
    {
        $escaped = Escaper::attr("ok\xC3\x28bad");

        self::assertSame(1, \preg_match('//u', $escaped));
        self::assertStringContainsString("\u{FFFD}", $escaped);
    }

    // ---- js() ---------------------------------------------------------------------------------

    public function testJsEscapesQuotesAndBackslash(): void
    {
        self::assertSame('\\x27', Escaper::js("'"));
        self::assertSame('\\x22', Escaper::js('"'));
        self::assertSame('\\x5C', Escaper::js('\\'));
    }

    /**
     * The break-out this method exists for. Inside a `<script>` element the HTML parser runs
     * first and knows nothing about JavaScript string literals: `</script>` ends the element
     * wherever it appears, quoted or not. Escaping `/` is what stops it.
     */
    public function testJsEscapesTheSlashThatWouldCloseAScriptElement(): void
    {
        $escaped = Escaper::js('</script><img src=x onerror=alert(1)>');

        self::assertStringNotContainsString('/', $escaped);
        self::assertStringNotContainsString('<', $escaped);
        self::assertStringNotContainsString('>', $escaped);
        self::assertSame('\\x2F', Escaper::js('/'));
    }

    /**
     * `LINE SEPARATOR` and `PARAGRAPH SEPARATOR` are *line terminators* in JavaScript before
     * ES2019 — an unescaped one ends the statement, and everything after it is code rather than
     * string. They are the reason `js()` cannot pass non-ASCII through the way `attr()` safely
     * can.
     */
    public function testJsEscapesTheUnicodeLineTerminators(): void
    {
        self::assertSame('\\u2028', Escaper::js("\u{2028}"));
        self::assertSame('\\u2029', Escaper::js("\u{2029}"));
    }

    public function testJsEmitsSurrogatePairsAboveTheBasicMultilingualPlane(): void
    {
        // U+1F642 is stored in UTF-16 as D83D DE42, and JavaScript's \u addresses code units.
        self::assertSame('\\uD83D\\uDE42', Escaper::js('🙂'));
    }

    public function testJsOutputIsPureAscii(): void
    {
        $escaped = Escaper::js('héllo 漢 🙂 <>&"\'');

        self::assertSame(1, \preg_match('/^[\x20-\x7E]*$/', $escaped), 'output must survive any document charset');
    }

    public function testJsLeavesAlphanumericsAlone(): void
    {
        self::assertSame('abcXYZ0189', Escaper::js('abcXYZ0189'));
    }

    public function testJsSubstitutesInvalidUtf8(): void
    {
        $escaped = Escaper::js("ok\xC3\x28bad");

        self::assertStringStartsWith('ok', $escaped);
        self::assertStringContainsString('\\uFFFD', $escaped);
    }

    // ---- url() --------------------------------------------------------------------------------

    public function testUrlPercentEncodesPerRfc3986(): void
    {
        self::assertSame('a%20b', Escaper::url('a b'));
        self::assertSame('a%2Fb', Escaper::url('a/b'));
        self::assertSame('%3Cscript%3E', Escaper::url('<script>'));
    }

    /**
     * Not `urlencode()`: that renders a space as `+`, which is correct only in a
     * `x-www-form-urlencoded` body and wrong in a path segment, where `+` is a literal plus.
     */
    public function testUrlUsesRawEncodingNotFormEncoding(): void
    {
        self::assertNotSame(\urlencode('a b'), Escaper::url('a b'));
    }

    /**
     * The likely misuse, pinned as a *safe* failure: passing a whole URL encodes its `:` and `/`,
     * producing an inert relative path rather than a working link. A broken link is a visible
     * failure; the point of asserting it is that the failure mode is not a silent hole.
     */
    public function testUrlOnAWholeUrlProducesAnInertRelativePathRatherThanAScheme(): void
    {
        self::assertSame('javascript%3Aalert%281%29', Escaper::url('javascript:alert(1)'));
        self::assertStringNotContainsString(':', Escaper::url('https://example.com/a'));
    }

    // ---- the separation between contexts -------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function xssPayloads(): iterable
    {
        yield 'script tag' => ['<script>alert(1)</script>'];
        yield 'img onerror' => ['<img src=x onerror=alert(1)>'];
        yield 'svg onload' => ['<svg/onload=alert(1)>'];
        yield 'quote break-out, double' => ['" onfocus=alert(1) autofocus="'];
        yield 'quote break-out, single' => ["' onfocus=alert(1) autofocus='"];
        yield 'unquoted attribute break-out' => ['x onmouseover=alert(1)'];
        yield 'script close inside string' => ['</script><script>alert(1)</script>'];
        yield 'js string break-out' => ["';alert(1);//"];
        yield 'backtick template literal' => ['`${alert(1)}`'];
        yield 'html comment' => ['<!--<script>alert(1)</script>-->'];
        yield 'entity-encoded' => ['&lt;script&gt;alert(1)&lt;/script&gt;'];
        yield 'unicode line terminator' => ["';\u{2028}alert(1);//"];
        yield 'null byte' => ["<scr\0ipt>alert(1)</scr\0ipt>"];
    }

    /**
     * After `html()`, no payload can still open a tag or close an attribute with a quote.
     */
    #[DataProvider('xssPayloads')]
    public function testHtmlNeutralisesTagAndQuoteCharacters(string $payload): void
    {
        $escaped = Escaper::html($payload);

        self::assertStringNotContainsString('<', $escaped);
        self::assertStringNotContainsString('>', $escaped);
        self::assertStringNotContainsString('"', $escaped);
        self::assertStringNotContainsString("'", $escaped);
    }

    /**
     * After `attr()`, nothing but alphanumerics survives — so no delimiter of any kind remains,
     * quoted context or not.
     */
    #[DataProvider('xssPayloads')]
    public function testAttrLeavesOnlyAlphanumericsAndEntities(string $payload): void
    {
        $escaped = Escaper::attr($payload);

        self::assertSame(1, \preg_match('/^(?:[a-zA-Z0-9]|&#x[0-9A-F]{2};|[\x80-\xFF]+)*$/', $escaped));
    }

    /**
     * After `js()`, the output is pure ASCII alphanumerics and `\x`/`\u` escapes — nothing that
     * can terminate a string literal, a statement, or the enclosing `<script>` element.
     */
    #[DataProvider('xssPayloads')]
    public function testJsLeavesNothingThatCanTerminateAStringOrTheScriptElement(string $payload): void
    {
        $escaped = Escaper::js($payload);

        self::assertSame(1, \preg_match('/^(?:[a-zA-Z0-9]|\\\\x[0-9A-F]{2}|\\\\u[0-9A-F]{4})*$/', $escaped));
        self::assertStringNotContainsString('/', $escaped);
        self::assertStringNotContainsString("'", $escaped);
        self::assertStringNotContainsString('"', $escaped);
    }

    /**
     * The reason FR-09 asks for four methods and not one: `html()` output is **not** safe in the
     * other contexts, and this asserts that rather than leaving it as advice in a docblock.
     */
    public function testHtmlOutputIsNotSufficientForTheOtherContexts(): void
    {
        // Safe in quoted-attribute and element-text context …
        self::assertSame('x&#039;y', Escaper::html("x'y"));

        // … but unchanged where the attribute is unquoted, and a space still delimits.
        self::assertSame('a b', Escaper::html('a b'));
        self::assertStringNotContainsString(' ', Escaper::attr('a b'));

        // … and inside a script element, `html()` leaves the slash that closes it.
        self::assertStringContainsString('/', Escaper::html('</script>'));
        self::assertStringNotContainsString('/', Escaper::js('</script>'));
    }

    public function testEveryContextHandlesTheEmptyString(): void
    {
        self::assertSame('', Escaper::html(''));
        self::assertSame('', Escaper::attr(''));
        self::assertSame('', Escaper::js(''));
        self::assertSame('', Escaper::url(''));
    }

    /**
     * All four must agree about malformed input, so a template that switches context does not
     * also switch failure behaviour.
     */
    public function testAllContextsAgreeThatInvalidUtf8BecomesTheReplacementCharacter(): void
    {
        $bad = "a\xC3\x28b";

        self::assertStringContainsString("\u{FFFD}", Escaper::html($bad));
        self::assertStringContainsString("\u{FFFD}", Escaper::attr($bad));
        self::assertStringContainsString('\\uFFFD', Escaper::js($bad));
    }
}
