<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Security;

use D4np\Utils\Database\DatabaseConnection;
use D4np\Utils\Database\Operator;
use D4np\Utils\Database\QueryBuilder;
use D4np\Utils\Database\SqlStatement;
use D4np\Utils\Security\Sanitizer;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * Spec FR-09b (`richText()`) and FR-10 (`sqlLikePattern()`).
 *
 * The `sqlLikePattern()` cases run against a **real SQLite database**, not on strings alone.
 * Asserting that `%` became `!%` would only prove this class does what it was written to do; what
 * matters is whether the driver then treats it as a literal, and that is a question only the
 * driver can answer. These are `#[Group('T-02')]` because they close the third leg of spec §7's
 * T-02 suite, left open at roadmap item 4.4.
 */
#[Group('T-02')]
final class SanitizerTest extends TestCase
{
    // ---- sqlLikePattern() ----------------------------------------------------------------------

    public function testWildcardsAndTheEscapeCharacterAreEscaped(): void
    {
        self::assertSame('100!%', Sanitizer::sqlLikePattern('100%'));
        self::assertSame('a!_b', Sanitizer::sqlLikePattern('a_b'));
        self::assertSame('ex!!clam', Sanitizer::sqlLikePattern('ex!clam'));
    }

    public function testOrdinaryTextIsUntouched(): void
    {
        self::assertSame('Grace Hopper', Sanitizer::sqlLikePattern('Grace Hopper'));
        self::assertSame('', Sanitizer::sqlLikePattern(''));
    }

    /**
     * The escape character has to be escaped *first*. Escaping it last would also double the
     * escape characters this method had just introduced, turning `100%` into `100!!%` — a literal
     * `!` followed by a live wildcard.
     */
    public function testTheEscapeCharacterIsProcessedBeforeTheWildcardsItIntroduces(): void
    {
        self::assertSame('!!!%', Sanitizer::sqlLikePattern('!%'));
    }

    public function testTheEscapeCharacterIsConfigurable(): void
    {
        self::assertSame('100\\%', Sanitizer::sqlLikePattern('100%', '\\'));
    }

    // ---- sqlLikePattern() against a real driver ------------------------------------------------

    private function seeded(): DatabaseConnection
    {
        $connection = new DatabaseConnection(new PDO('sqlite::memory:'));
        $connection->execute(SqlStatement::literal('CREATE TABLE t (v TEXT)'));
        foreach (['100%', '100X', '1000', 'a_b', 'axb', 'plain'] as $value) {
            $connection->execute(SqlStatement::literal('INSERT INTO t (v) VALUES (?)', [$value]));
        }

        return $connection;
    }

    /**
     * @return list<string>
     */
    private function matching(DatabaseConnection $connection, string $pattern): array
    {
        return array_map(
            static function (array $row): string {
                self::assertIsString($row['v']);

                return $row['v'];
            },
            (new QueryBuilder($connection, 't'))->select('v')->whereLike('v', $pattern)->get(),
        );
    }

    /**
     * The vulnerability, stated as a test: a bound-but-unescaped `%` is still pattern syntax.
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testAnUnescapedWildcardMatchesEverythingWithThatPrefix(): void
    {
        self::assertSame(['100%', '100X', '1000'], $this->matching($this->seeded(), '100%'));
    }

    /**
     * The fix: the same input, escaped, matches only itself.
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testAnEscapedWildcardMatchesOnlyTheLiteralValue(): void
    {
        $connection = $this->seeded();

        self::assertSame(['100%'], $this->matching($connection, Sanitizer::sqlLikePattern('100%')));
        self::assertSame(['a_b'], $this->matching($connection, Sanitizer::sqlLikePattern('a_b')));
    }

    /**
     * The caller's own wildcards still work — a prefix search is the escaped term plus a live `%`.
     * If this method escaped the whole pattern, every `LIKE` would degrade to an equality test.
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testTheCallersOwnWildcardStillSearchesByPrefix(): void
    {
        self::assertSame(
            ['100%', '100X', '1000'],
            $this->matching($this->seeded(), Sanitizer::sqlLikePattern('100') . '%'),
        );
    }

    /**
     * **The trap this pairing exists to close.** `where()` with `Operator::Like` emits no `ESCAPE`
     * clause, so on SQLite an escaped pattern matches *nothing* — silently, and differently from
     * MySQL, where backslash-as-default-escape makes the same code appear to work.
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testWithoutTheEscapeClauseAnEscapedPatternSilentlyMatchesNothing(): void
    {
        $connection = $this->seeded();
        $escaped = Sanitizer::sqlLikePattern('100%');

        $withoutClause = (new QueryBuilder($connection, 't'))
            ->select('v')
            ->where('v', Operator::Like, $escaped)
            ->get();

        self::assertSame([], $withoutClause, 'this is the silent failure whereLike() exists to prevent');
        self::assertSame(['100%'], $this->matching($connection, $escaped));
    }

    /**
     * `QueryBuilder` cannot import `Sanitizer` — RFC-0001's layering rule forbids a `Database` →
     * `Security` dependency — so the escape character is spelled out in both. This is the check
     * that keeps the two copies honest.
     */
    public function testTheBuilderAndTheSanitizerAgreeOnTheEscapeCharacter(): void
    {
        // A private constant needs no setAccessible() — ReflectionClassConstant reads it directly.
        $builderConstant = new ReflectionClassConstant(QueryBuilder::class, 'LIKE_ESCAPE');

        self::assertSame(Sanitizer::LIKE_ESCAPE, $builderConstant->getValue());
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testTheEscapeClauseNamesTheSameCharacter(): void
    {
        $sql = (new QueryBuilder(new DatabaseConnection(new PDO('sqlite::memory:')), 't'))
            ->whereLike('v', 'x')
            ->toSql();

        self::assertStringContainsString("LIKE ? ESCAPE '" . Sanitizer::LIKE_ESCAPE . "'", $sql);
    }

    // ---- richText() ----------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function markup(): iterable
    {
        yield 'ordinary formatting survives' => ['<p>Hello <b>world</b></p>', '<p>Hello <b>world</b></p>'];
        yield 'script element is removed' => ['<script>alert(1)</script>', ''];
        yield 'style element is removed' => ['<style>body{}</style>', ''];
        yield 'iframe is removed' => ['<iframe src="https://evil.example"></iframe>', ''];
        yield 'svg payload is removed' => ['<svg onload=alert(1)>', ''];
    }

    #[DataProvider('markup')]
    public function testRichTextReducesMarkupToTheAllowlist(string $html, string $expected): void
    {
        self::assertSame($expected, Sanitizer::richText($html));
    }

    public function testEventHandlerAttributesAreStripped(): void
    {
        $sanitized = Sanitizer::richText('<img src="https://ok.example/a.png" onerror="alert(1)">');

        self::assertStringNotContainsString('onerror', $sanitized);
        self::assertStringNotContainsString('alert', $sanitized);
    }

    /**
     * `allowSafeElements()` alone does not do this — the scheme allowlist is what does.
     */
    public function testJavascriptAndDataSchemesAreRefused(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html;base64,PHNjcmlwdD4='] as $href) {
            $sanitized = Sanitizer::richText(sprintf('<a href="%s">x</a>', $href));

            self::assertStringNotContainsString('javascript:', $sanitized);
            self::assertStringNotContainsString('data:', $sanitized);
        }
    }

    public function testHttpsLinksSurviveAndCarryRelNoopener(): void
    {
        $sanitized = Sanitizer::richText('<a href="https://ok.example">ok</a>');

        self::assertStringContainsString('https://ok.example', $sanitized);
        self::assertStringContainsString('noopener', $sanitized);
        self::assertStringContainsString('noreferrer', $sanitized);
    }

    /**
     * A relative URL's meaning depends on the page it is rendered into, which is not knowable here.
     */
    public function testRelativeLinksAreRefused(): void
    {
        self::assertStringNotContainsString('/admin', Sanitizer::richText('<a href="/admin">x</a>'));
    }

    public function testTheSanitizerIsBuiltOnceAndReused(): void
    {
        // Two calls must agree; the memoised instance is shared process-wide (see ADR-0008's
        // reasoning for the hydrator, applied here).
        self::assertSame(Sanitizer::richText('<p>a</p>'), Sanitizer::richText('<p>a</p>'));
    }
}
