<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Str::before()` / `after()` / `beforeLast()` / `afterLast()` / `between()` / `containsAny()`
 * — spec r31 FR-57 (RFC-0004, roadmap item 15.2).
 *
 * `null` on a miss, never the subject unchanged — the probing arm of this library's
 * missing-value grammar.
 */
final class StrSegmentExtractionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function beforeCases(): iterable
    {
        yield 'found' => ['user@example.com', '@', 'user'];
        yield 'found at start' => ['@example.com', '@', ''];
        yield 'not found' => ['user-example-com', '@', null];
        yield 'multiple occurrences takes the first' => ['a-b-c', '-', 'a'];
        yield 'multibyte subject and needle' => ['pässwörd:secret', 'wörd', 'päss'];
    }

    #[DataProvider('beforeCases')]
    public function testBefore(string $subject, string $needle, ?string $expected): void
    {
        self::assertSame($expected, Str::before($subject, $needle));
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function afterCases(): iterable
    {
        yield 'found' => ['user@example.com', '@', 'example.com'];
        yield 'found at end' => ['user@', '@', ''];
        yield 'not found' => ['user-example-com', '@', null];
        yield 'multiple occurrences takes the first' => ['a-b-c', '-', 'b-c'];
        yield 'multibyte subject and needle' => ['pässwörd:secret', 'wörd', ':secret'];
    }

    #[DataProvider('afterCases')]
    public function testAfter(string $subject, string $needle, ?string $expected): void
    {
        self::assertSame($expected, Str::after($subject, $needle));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function reconstructionCases(): iterable
    {
        yield 'ascii' => ['user@example.com', '@'];
        yield 'repeated needle' => ['a-b-c-d', '-'];
        yield 'needle at start' => ['-abc', '-'];
        yield 'needle at end' => ['abc-', '-'];
        yield 'multibyte subject and needle' => ['pässwörd:secret:more', 'wörd'];
        yield 'multi-character needle' => ['keyA=>value', '=>'];
    }

    /**
     * The property the issue names: before() . needle . after() reconstructs the subject
     * exactly whenever the needle was found — proven for a corpus, not merely claimed.
     */
    #[DataProvider('reconstructionCases')]
    public function testBeforeAfterReconstructsSubjectAtFirstOccurrence(string $subject, string $needle): void
    {
        $before = Str::before($subject, $needle);
        $after = Str::after($subject, $needle);

        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertSame($subject, $before . $needle . $after);
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function beforeLastCases(): iterable
    {
        yield 'found, takes the last' => ['a-b-c', '-', 'a-b'];
        yield 'single occurrence' => ['user@example.com', '@', 'user'];
        yield 'not found' => ['abc', '-', null];
    }

    #[DataProvider('beforeLastCases')]
    public function testBeforeLast(string $subject, string $needle, ?string $expected): void
    {
        self::assertSame($expected, Str::beforeLast($subject, $needle));
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function afterLastCases(): iterable
    {
        yield 'found, takes the last' => ['a-b-c', '-', 'c'];
        yield 'single occurrence' => ['user@example.com', '@', 'example.com'];
        yield 'not found' => ['abc', '-', null];
        yield 'file extension' => ['archive.tar.gz', '.', 'gz'];
    }

    #[DataProvider('afterLastCases')]
    public function testAfterLast(string $subject, string $needle, ?string $expected): void
    {
        self::assertSame($expected, Str::afterLast($subject, $needle));
    }

    /**
     * @return iterable<string, array{string, string, string, ?string}>
     */
    public static function betweenCases(): iterable
    {
        yield 'found' => ['<a>content</a>', '<a>', '</a>', 'content'];
        yield 'takes the first start and the next end after it' => ['<a><b>', '<', '>', 'a'];
        yield 'start not found' => ['no tags here', '<a>', '</a>', null];
        yield 'end not found after start' => ['<a>content', '<a>', '</a>', null];
        yield 'same delimiter for start and end' => ['a,b,c', ',', ',', 'b'];
        yield 'empty span' => ['<a></a>', '<a>', '</a>', ''];
        yield 'multibyte delimiters' => ['[[pässwörd]]', '[[', ']]', 'pässwörd'];
    }

    #[DataProvider('betweenCases')]
    public function testBetween(string $subject, string $start, string $end, ?string $expected): void
    {
        self::assertSame($expected, Str::between($subject, $start, $end));
    }

    public function testRefusesEmptyNeedleOnBefore(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::before('anything', '');
    }

    public function testRefusesEmptyNeedleOnAfter(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::after('anything', '');
    }

    public function testRefusesEmptyNeedleOnBeforeLast(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::beforeLast('anything', '');
    }

    public function testRefusesEmptyNeedleOnAfterLast(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::afterLast('anything', '');
    }

    public function testRefusesEmptyStartOnBetween(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::between('anything', '', 'end');
    }

    public function testRefusesEmptyEndOnBetween(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::between('anything', 'start', '');
    }

    public function testContainsAnyFindsOneOfSeveralNeedles(): void
    {
        self::assertTrue(Str::containsAny('hello world', ['xyz', 'world', 'abc']));
    }

    public function testContainsAnyReturnsFalseWhenNoneMatch(): void
    {
        self::assertFalse(Str::containsAny('hello world', ['xyz', 'abc']));
    }

    public function testContainsAnyReturnsFalseForEmptyNeedleList(): void
    {
        self::assertFalse(Str::containsAny('hello world', []));
    }

    public function testContainsAnyRefusesAnEmptyNeedleInTheList(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::containsAny('hello world', ['world', '']);
    }

    /**
     * The refusal must not depend on whether a matching needle happens to come first — every
     * needle is validated before any is matched.
     */
    public function testContainsAnyRefusalIsOrderIndependent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::containsAny('hello world', ['', 'world']);
    }
}
