<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Security;

use D4np\Utils\Security\Escaper;
use D4np\Utils\Tests\Security\Corpus\Snapshot;
use D4np\Utils\Tests\Security\Corpus\XssCorpus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Spec §7: *"OWASP XSS cheat-sheet corpus per Escaper context (snapshot suite)"* — roadmap 5.4.
 *
 * **Two kinds of assertion, doing two different jobs.** The snapshot records what each escaper
 * produces for every payload, so a change in behaviour becomes a reviewable diff rather than a
 * silent drift. The invariants assert what must be *true* of that output regardless of what it
 * happens to be. A snapshot alone would happily record broken output forever; invariants alone
 * would miss a change that stays within them. `EscaperTest` (item 5.1) covers the per-method
 * contract; this covers the corpus.
 *
 * Update the recording deliberately, and read the diff:
 *
 * ```bash
 * UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --filter EscaperOwaspCorpusTest
 * ```
 */
#[Group('T-06')]
final class EscaperOwaspCorpusTest extends TestCase
{
    private const SNAPSHOT = 'escaper-owasp-corpus';

    /**
     * @return array<string, string>
     */
    private static function currentOutput(): array
    {
        $out = [];

        foreach (XssCorpus::escaperPayloads() as $name => $payload) {
            $out[$name . '::html'] = Escaper::html($payload);
            $out[$name . '::attr'] = Escaper::attr($payload);
            $out[$name . '::js'] = Escaper::js($payload);
            $out[$name . '::url'] = Escaper::url($payload);
        }

        return $out;
    }

    public function testOutputMatchesTheRecordedSnapshot(): void
    {
        $actual = self::currentOutput();

        if (Snapshot::shouldUpdate()) {
            Snapshot::write(self::SNAPSHOT, $actual);
            self::markTestSkipped('snapshot rewritten; re-run without UPDATE_SNAPSHOTS to assert it');
        }

        $expected = Snapshot::read(self::SNAPSHOT);

        self::assertNotNull(
            $expected,
            'no snapshot recorded yet — run with UPDATE_SNAPSHOTS=1 and review the result before committing it',
        );

        ksort($actual);
        ksort($expected);

        self::assertSame(
            $expected,
            $actual,
            'escaper output changed. If the change is intended, re-record with UPDATE_SNAPSHOTS=1 '
            . 'and review the diff — this file exists so that never happens silently.',
        );
    }

    /**
     * A snapshot proves stability, not safety. These are the safety half.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function payloads(): iterable
    {
        foreach (XssCorpus::escaperPayloads() as $name => $payload) {
            yield $name => [$name, $payload];
        }
    }

    /**
     * After `html()`, nothing can open a tag or close a quoted attribute.
     */
    #[DataProvider('payloads')]
    public function testHtmlLeavesNoTagOrQuoteCharacters(string $name, string $payload): void
    {
        $escaped = Escaper::html($payload);

        foreach (['<', '>', '"', "'"] as $dangerous) {
            self::assertStringNotContainsString($dangerous, $escaped, $name);
        }
    }

    /**
     * After `attr()`, only alphanumerics, `&#xHH;` entities and non-ASCII bytes remain — so no
     * delimiter survives, quoted context or not. This is the property that makes `attr()` safe in
     * an *unquoted* attribute, which `html()` is not (ADR-0019).
     */
    #[DataProvider('payloads')]
    public function testAttrLeavesNoAttributeDelimiter(string $name, string $payload): void
    {
        $escaped = Escaper::attr($payload);

        self::assertSame(1, preg_match('/^(?:[a-zA-Z0-9]|&#x[0-9A-F]{2};|[\x80-\xFF])*$/', $escaped), $name);
    }

    /**
     * After `js()`, the output is pure ASCII alphanumerics and `\x`/`\u` escapes — nothing that can
     * terminate a string literal, a statement, or the enclosing `<script>` element.
     */
    #[DataProvider('payloads')]
    public function testJsLeavesNothingThatCanTerminateAStringOrScriptElement(string $name, string $payload): void
    {
        $escaped = Escaper::js($payload);

        self::assertSame(1, preg_match('/^(?:[a-zA-Z0-9]|\\\\x[0-9A-F]{2}|\\\\u[0-9A-F]{4})*$/', $escaped), $name);
    }

    /**
     * After `url()`, no scheme separator survives, so a `javascript:` or `data:` payload cannot be
     * read as a scheme even if a caller misuses this on a whole URL (ADR-0019 records that misuse
     * as a deliberately *visible* failure).
     */
    #[DataProvider('payloads')]
    public function testUrlLeavesNoSchemeSeparator(string $name, string $payload): void
    {
        self::assertStringNotContainsString(':', Escaper::url($payload), $name);
    }

    /**
     * Every escaper must return well-formed UTF-8 even for input that is not — otherwise the
     * output could itself corrupt the document it lands in (ADR-0019's U+FFFD substitution).
     */
    #[DataProvider('payloads')]
    public function testEveryContextReturnsWellFormedUtf8(string $name, string $payload): void
    {
        foreach (['html', 'attr', 'js', 'url'] as $context) {
            /** @var callable(string): string $escaper */
            $escaper = [Escaper::class, $context];

            self::assertSame(1, preg_match('//u', $escaper($payload)), $name . '::' . $context);
        }
    }

    /**
     * The corpus has to keep containing the shapes it claims to. A payload list that quietly loses
     * its tag-less entries still passes every assertion above while testing much less.
     */
    public function testTheCorpusStillCoversTheTechniquesItClaimsTo(): void
    {
        $names = array_keys(XssCorpus::escaperPayloads());

        foreach (['script-alert', 'attr-breakout-unquoted', 'js-script-close', 'url-javascript-scheme', 'js-line-separator'] as $required) {
            self::assertContains($required, $names);
        }

        self::assertGreaterThanOrEqual(30, count($names), 'the corpus has shrunk — was that deliberate?');
    }
}
