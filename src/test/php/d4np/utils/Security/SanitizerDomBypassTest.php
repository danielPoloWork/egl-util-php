<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Security;

use D4np\Utils\Security\Sanitizer;
use D4np\Utils\Tests\Security\Corpus\Snapshot;
use D4np\Utils\Tests\Security\Corpus\XssCorpus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Spec §7: *"DOM-bypass corpus for richText()"* — roadmap 5.4.
 *
 * **Mutation XSS is a different threat from ordinary XSS, and needs a different assertion.** An
 * ordinary payload is dangerous on sight, and a sanitizer that removes what it can see defeats it.
 * An mXSS payload is *inert when parsed once* and becomes executable when the sanitized output is
 * re-serialised and parsed again — typically by escaping a foreign-content or raw-text context
 * (`<svg>`, `<math>`, `<noscript>`, `<style>`, `<xmp>`) which changes how the parser treats
 * everything after it. Checking "no `<script>` in the output" does not catch that, because there
 * is no `<script>` in the output.
 *
 * So the load-bearing assertion here is **idempotence**: sanitizing twice must equal sanitizing
 * once. If `richText(richText($x)) !== richText($x)`, the output is not stable under re-parse, and
 * instability under re-parse is precisely the mXSS signature — whether or not this particular
 * corpus contains a payload that exploits it.
 *
 * This is also the concrete argument for RFC-0001's *"no hand-rolled tag stripper"*: none of these
 * are detectable by a regex, because none of them require a dangerous token to be *present* in the
 * text being examined.
 */
#[Group('T-06')]
final class SanitizerDomBypassTest extends TestCase
{
    private const SNAPSHOT = 'sanitizer-dom-bypass';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function payloads(): iterable
    {
        foreach (XssCorpus::domBypassPayloads() as $name => $payload) {
            yield $name => [$name, $payload];
        }
    }

    public function testOutputMatchesTheRecordedSnapshot(): void
    {
        $actual = [];
        foreach (XssCorpus::domBypassPayloads() as $name => $payload) {
            $actual[$name] = Sanitizer::richText($payload);
        }

        if (Snapshot::shouldUpdate()) {
            Snapshot::write(self::SNAPSHOT, $actual);
            self::markTestSkipped('snapshot rewritten; re-run without UPDATE_SNAPSHOTS to assert it');
        }

        $expected = Snapshot::read(self::SNAPSHOT);
        self::assertNotNull($expected, 'no snapshot recorded yet — run with UPDATE_SNAPSHOTS=1 and review it');

        ksort($actual);
        ksort($expected);

        self::assertSame(
            $expected,
            $actual,
            'sanitizer output changed. A change here can mean the upstream sanitizer was upgraded, '
            . 'which is fine — but it must be looked at, because it can equally mean a payload that '
            . 'was neutralised no longer is.',
        );
    }

    /**
     * **The mXSS assertion.** Output that changes when fed back through the sanitizer is output
     * whose parse is not stable — the property every mutation payload relies on.
     */
    #[DataProvider('payloads')]
    public function testSanitizingIsIdempotent(string $name, string $payload): void
    {
        $once = Sanitizer::richText($payload);

        self::assertSame($once, Sanitizer::richText($once), $name . ': output is not stable under re-parse');
    }

    /**
     * No event-handler attribute may survive, in any casing.
     */
    #[DataProvider('payloads')]
    public function testNoEventHandlerSurvives(string $name, string $payload): void
    {
        $sanitized = Sanitizer::richText($payload);

        self::assertSame(0, preg_match('/\son[a-z]+\s*=/i', $sanitized), $name . ': ' . $sanitized);
    }

    /**
     * No executable element may survive.
     */
    #[DataProvider('payloads')]
    public function testNoExecutableElementSurvives(string $name, string $payload): void
    {
        $sanitized = strtolower(Sanitizer::richText($payload));

        foreach (['<script', '<iframe', '<object', '<embed', '<base', '<meta', '<link', '<style', '<form'] as $element) {
            self::assertStringNotContainsString($element, $sanitized, $name);
        }
    }

    /**
     * No scripting URL scheme may survive, including the entity-encoded spellings that exist
     * precisely to slip past a substring check on the raw input.
     */
    #[DataProvider('payloads')]
    public function testNoScriptingSchemeSurvives(string $name, string $payload): void
    {
        $sanitized = strtolower(Sanitizer::richText($payload));

        foreach (['javascript:', 'vbscript:', 'data:text/html'] as $scheme) {
            self::assertStringNotContainsString($scheme, $sanitized, $name);
        }
    }

    /**
     * A sanitizer that destroys everything is trivially "safe" and useless. This is the assertion
     * that keeps the others honest — without it, `return '';` would pass every test above.
     */
    public function testLegitimateRichTextSurvives(): void
    {
        $sanitized = Sanitizer::richText('<p>Hello <strong>world</strong> and <em>others</em></p>');

        self::assertStringContainsString('<strong>world</strong>', $sanitized);
        self::assertStringContainsString('<em>others</em>', $sanitized);

        $link = Sanitizer::richText('<p>See <a href="https://example.com/docs">the docs</a>.</p>');
        self::assertStringContainsString('https://example.com/docs', $link);
        self::assertStringContainsString('the docs', $link);

        $list = Sanitizer::richText('<ul><li>one</li><li>two</li></ul>');
        self::assertStringContainsString('<li>one</li>', $list);
    }

    public function testTheCorpusStillCoversTheMutationTechniquesItClaimsTo(): void
    {
        $names = array_keys(XssCorpus::domBypassPayloads());

        foreach (['mxss-noscript-title', 'mxss-svg-style', 'mxss-math-mglyph', 'mxss-xmp'] as $required) {
            self::assertContains($required, $names);
        }

        self::assertGreaterThanOrEqual(18, count($names), 'the corpus has shrunk — was that deliberate?');
    }
}
