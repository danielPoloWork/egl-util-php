<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Persistence;

use D4np\Utils\Persistence\RowNormalizer;
use D4np\Utils\Support\DatabaseException;
use D4np\Utils\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Spec §6's **T-15 policy table** — `RowNormalizer`, spec r3 FR-36 (RFC-0002), ADR-0042.
 *
 * A table rather than a narrative because the class *is* a policy: four independent switches
 * whose interesting behaviour lives in the combinations, and whose defaults are a decision
 * (ADR-0042) that a test should pin rather than describe. {@see self::policyTable()} is that
 * pinning — input, policy, expected output, one row per claim.
 *
 * The two cases most worth reading are the ones a hand-rolled version of this pipeline gets
 * wrong: `'0'` is **not** blank (PHP's `empty()` says it is), and a non-string value is
 * returned by identity rather than coerced.
 */
#[Group('T-15')]
final class RowNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, RowNormalizer, array<string, mixed>}>
     */
    public static function policyTable(): iterable
    {
        // ---- defaults: trim, and nothing else -------------------------------------------------
        yield 'default trims a fixed-width CHAR value' => [
            ['name' => 'Ada                 '],
            new RowNormalizer(),
            ['name' => 'Ada'],
        ];
        yield 'default trims leading whitespace too' => [
            ['name' => "\t Ada \n"],
            new RowNormalizer(),
            ['name' => 'Ada'],
        ];
        yield 'default leaves internal whitespace alone' => [
            ['note' => '  two  spaces  inside  '],
            new RowNormalizer(),
            ['note' => 'two  spaces  inside'],
        ];
        yield 'default leaves a blank value as an empty string, not null' => [
            ['note' => '     '],
            new RowNormalizer(),
            ['note' => ''],
        ];
        yield 'default does not transcode' => [
            // Valid UTF-8 arriving from a UTF-8 database: untouched, and no encoding guessed.
            ['name' => 'Grace Höpper'],
            new RowNormalizer(),
            ['name' => 'Grace Höpper'],
        ];

        // ---- trim off ------------------------------------------------------------------------
        yield 'trim can be turned off, and then nothing is touched' => [
            ['name' => '  Ada  '],
            new RowNormalizer(trim: false),
            ['name' => '  Ada  '],
        ];

        // ---- collapseWhitespace --------------------------------------------------------------
        yield 'collapse squeezes internal runs' => [
            ['note' => 'two  spaces   inside'],
            new RowNormalizer(collapseWhitespace: true),
            ['note' => 'two spaces inside'],
        ];
        yield 'collapse also trims, so it subsumes trim rather than fighting it' => [
            ['note' => '  a  b  '],
            new RowNormalizer(collapseWhitespace: true),
            ['note' => 'a b'],
        ];
        yield 'collapse still trims when trim is explicitly off' => [
            // Documents the precedence rather than leaving it to be discovered: collapsing
            // implies trimming, because Str::collapseWhitespace() trims.
            ['note' => '  a  b  '],
            new RowNormalizer(trim: false, collapseWhitespace: true),
            ['note' => 'a b'],
        ];
        yield 'collapse turns a newline run into one space' => [
            ['note' => "line\n\nbreak"],
            new RowNormalizer(collapseWhitespace: true),
            ['note' => 'line break'],
        ];

        // ---- blankToNull ---------------------------------------------------------------------
        yield 'blankToNull turns an all-whitespace value into null' => [
            ['note' => '     '],
            new RowNormalizer(blankToNull: true),
            ['note' => null],
        ];
        yield 'blankToNull turns an empty string into null' => [
            ['note' => ''],
            new RowNormalizer(blankToNull: true),
            ['note' => null],
        ];
        yield 'blankToNull leaves "0" alone — it is content, whatever empty() thinks' => [
            // The classic bug this table exists to pin: a hand-rolled `empty($v) ? null : $v`
            // silently nulls a legitimate '0', and a flag column is exactly where that lands.
            ['flag' => '0'],
            new RowNormalizer(blankToNull: true),
            ['flag' => '0'],
        ];
        yield 'blankToNull leaves "false" and " x " content alone' => [
            ['a' => 'false', 'b' => ' x '],
            new RowNormalizer(blankToNull: true),
            ['a' => 'false', 'b' => 'x'],
        ];
        yield 'blankToNull sees the trimmed value, so whitespace-only becomes null' => [
            // Order matters: without trimming first, '   ' is not empty and would survive.
            ['note' => "  \t  "],
            new RowNormalizer(trim: true, blankToNull: true),
            ['note' => null],
        ];

        // ---- non-strings and keys ------------------------------------------------------------
        yield 'non-string values pass through by identity' => [
            ['id' => 42, 'ratio' => 1.5, 'ok' => true, 'missing' => null],
            new RowNormalizer(collapseWhitespace: true, blankToNull: true),
            ['id' => 42, 'ratio' => 1.5, 'ok' => true, 'missing' => null],
        ];
        yield 'keys are never touched, however padded they look' => [
            ['  gc_comm  ' => '  value  '],
            new RowNormalizer(),
            ['  gc_comm  ' => 'value'],
        ];
        yield 'an empty row stays empty' => [
            [],
            new RowNormalizer(collapseWhitespace: true, blankToNull: true),
            [],
        ];

        // ---- everything at once --------------------------------------------------------------
        yield 'the full legacy pipeline, all switches on' => [
            ['name' => "  Ada   Lovelace \n", 'note' => '   ', 'id' => 7],
            new RowNormalizer(collapseWhitespace: true, blankToNull: true),
            ['name' => 'Ada Lovelace', 'note' => null, 'id' => 7],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $expected
     */
    #[DataProvider('policyTable')]
    public function testThePolicyTableHolds(array $row, RowNormalizer $normalizer, array $expected): void
    {
        self::assertSame($expected, $normalizer->normalize($row));
    }

    public function testKeyOrderIsPreserved(): void
    {
        $row = ['z' => ' 1 ', 'a' => ' 2 ', 'm' => ' 3 '];

        self::assertSame(['z', 'a', 'm'], array_keys((new RowNormalizer())->normalize($row)));
    }

    public function testARowIsNotMutatedInPlace(): void
    {
        // The parameter is by value and reassigned inside, but a future refactor to `&$row`
        // would be a silent behaviour change for every caller sharing the array.
        $row = ['name' => '  Ada  '];
        (new RowNormalizer())->normalize($row);

        self::assertSame(['name' => '  Ada  '], $row);
    }

    // ---- transcoding -------------------------------------------------------------------------

    #[RequiresPhpExtension('iconv')]
    public function testTranscodingConvertsFromTheDeclaredEncoding(): void
    {
        // 0xE9 is 'é' in ISO-8859-15 and invalid on its own in UTF-8.
        $row = ['name' => "Gr\xE9ta"];

        self::assertSame(
            ['name' => 'Gréta'],
            (new RowNormalizer(fromEncoding: 'ISO-8859-15'))->normalize($row),
        );
    }

    #[RequiresPhpExtension('iconv')]
    public function testTranscodingHappensBeforeTrimming(): void
    {
        // The ordering claim, made observable: the value needs both conversion and trimming,
        // and the result is correct only if the conversion ran first.
        $row = ['name' => "  Gr\xE9ta  "];

        self::assertSame(
            ['name' => 'Gréta'],
            (new RowNormalizer(fromEncoding: 'ISO-8859-15'))->normalize($row),
        );
    }

    /**
     * Strict by default: a value the target cannot represent is refused, not silently shortened.
     * This is the estate's `//IGNORE` reversed, and the reason the message names the column.
     */
    #[RequiresPhpExtension('iconv')]
    public function testAnUnconvertibleValueIsRefusedAndNamesItsColumn(): void
    {
        try {
            // '€' exists in UTF-8 and has no representation in ISO-8859-1.
            (new RowNormalizer(fromEncoding: 'UTF-8', toEncoding: 'ISO-8859-1'))
                ->normalize(['price_label' => '10 €']);

            self::fail('expected a DatabaseException');
        } catch (DatabaseException $e) {
            self::assertStringContainsString('price_label', $e->getMessage());
            self::assertNotNull($e->getPrevious(), 'the original transcoding failure is the cause');
        }
    }

    #[RequiresPhpExtension('iconv')]
    public function testLossyModeDropsWhatTheTargetCannotRepresent(): void
    {
        $normalized = (new RowNormalizer(fromEncoding: 'UTF-8', toEncoding: 'ISO-8859-1', lossy: true))
            ->normalize(['price_label' => '10 €']);

        // Explicitly opted into: the euro sign is gone and the rest survives.
        self::assertSame(['price_label' => '10'], $normalized);
    }

    #[RequiresPhpExtension('iconv')]
    public function testNonStringValuesAreNotTranscoded(): void
    {
        // A resource would be destroyed by iconv(); ints and floats are meaningless to it.
        $handle = fopen('php://memory', 'rb');
        self::assertNotFalse($handle);

        try {
            $normalized = (new RowNormalizer(fromEncoding: 'ISO-8859-15'))
                ->normalize(['blob' => $handle, 'id' => 1]);

            self::assertSame($handle, $normalized['blob']);
            self::assertSame(1, $normalized['id']);
        } finally {
            fclose($handle);
        }
    }

    // ---- the trim-only fast path (item 10.11, ADR-0047) --------------------------------------

    /**
     * Every value the corpus below carries, through **every** policy combination, compared
     * against {@see self::oracle()} — an implementation of ADR-0042's ordering written outside
     * the class.
     *
     * This is the fast path's safety net, and it is aimed at the actual risk. The shortcut
     * itself is a `trim()` call that cannot be wrong; what *can* be wrong is the condition
     * choosing it, and a condition that fires one combination too widely is invisible to any
     * test that only exercises the default policy. So the matrix asserts the whole truth
     * table behaviourally: if `$trimOnly` ever fires where the general pipeline would have
     * produced something else, exactly one of these combinations disagrees with the oracle.
     *
     * @param array<string, mixed> $row
     */
    #[DataProvider('policyCombinations')]
    public function testEveryPolicyCombinationAgreesWithAnIndependentOracle(
        array $row,
        ?string $fromEncoding,
        bool $trim,
        bool $collapseWhitespace,
        bool $blankToNull,
    ): void {
        $normalizer = new RowNormalizer(
            fromEncoding: $fromEncoding,
            trim: $trim,
            collapseWhitespace: $collapseWhitespace,
            blankToNull: $blankToNull,
        );

        $expected = [];

        foreach ($row as $column => $value) {
            $expected[$column] = self::oracle($value, $fromEncoding, $trim, $collapseWhitespace, $blankToNull);
        }

        self::assertSame($expected, $normalizer->normalize($row));
    }

    /**
     * The same matrix with an encoding declared, so the half of the truth table where the fast
     * path must **never** fire is covered too. Gated on `iconv` like every other transcoding
     * test here.
     *
     * @param array<string, mixed> $row
     */
    #[DataProvider('transcodingPolicyCombinations')]
    #[RequiresPhpExtension('iconv')]
    public function testEveryTranscodingCombinationAgreesWithAnIndependentOracle(
        array $row,
        ?string $fromEncoding,
        bool $trim,
        bool $collapseWhitespace,
        bool $blankToNull,
    ): void {
        $this->testEveryPolicyCombinationAgreesWithAnIndependentOracle(
            $row,
            $fromEncoding,
            $trim,
            $collapseWhitespace,
            $blankToNull,
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ?string, bool, bool, bool}>
     */
    public static function policyCombinations(): iterable
    {
        yield from self::combinationsFor(null);
    }

    /**
     * `ISO-8859-15` → `UTF-8` deliberately: every byte is valid in the source encoding, so
     * transcoding can never throw here and the matrix stays about the *composition* of steps
     * rather than about failure handling (which
     * {@see self::testAnUnconvertibleValueIsRefusedAndNamesItsColumn()} owns).
     *
     * @return iterable<string, array{array<string, mixed>, ?string, bool, bool, bool}>
     */
    public static function transcodingPolicyCombinations(): iterable
    {
        yield from self::combinationsFor('ISO-8859-15');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ?string, bool, bool, bool}>
     */
    private static function combinationsFor(?string $fromEncoding): iterable
    {
        $corpus = [
            'padded' => '  Ada  ',
            'clean' => 'Ada',
            'empty' => '',
            'whitespace only' => '   ',
            'tabs and newlines' => "\t Ada \n",
            'zero string' => '0',
            'padded zero' => '  0  ',
            'internal runs' => 'two  spaces  inside',
            'padded internal runs' => '  two  spaces  ',
            'multibyte' => 'Grace Hopper',
            'int' => 42,
            'null' => null,
            'float' => 3.14,
            'bool' => true,
        ];

        foreach ([true, false] as $trim) {
            foreach ([true, false] as $collapseWhitespace) {
                foreach ([true, false] as $blankToNull) {
                    $label = sprintf(
                        'from=%s trim=%s collapse=%s blankToNull=%s',
                        $fromEncoding ?? 'null',
                        $trim ? 'y' : 'n',
                        $collapseWhitespace ? 'y' : 'n',
                        $blankToNull ? 'y' : 'n',
                    );

                    yield $label => [$corpus, $fromEncoding, $trim, $collapseWhitespace, $blankToNull];
                }
            }
        }
    }

    /**
     * ADR-0042's pipeline, restated independently of the class under test: transcode first,
     * then collapse *or* trim, then blank-to-`null` last.
     */
    private static function oracle(
        mixed $value,
        ?string $fromEncoding,
        bool $trim,
        bool $collapseWhitespace,
        bool $blankToNull,
    ): mixed {
        if (!is_string($value)) {
            return $value;
        }

        if ($fromEncoding !== null) {
            $value = Str::transcode($value, $fromEncoding, 'UTF-8', false);
        }

        if ($collapseWhitespace) {
            $value = Str::collapseWhitespace($value);
        } elseif ($trim) {
            $value = trim($value);
        }

        return $blankToNull ? Str::nullIfBlank($value) : $value;
    }

    /**
     * The fast path asserted as a **mechanism**, per ADR-0027's rule for a property no
     * behaviour can observe.
     *
     * The two paths are output-identical by construction — which is exactly why nothing above
     * would notice if the condition were replaced by `false` and the optimization silently
     * stopped happening. That failure class has a history in this project (a mutation gate
     * that ran on nothing, item 10.8; a coverage gate with no driver, item 2.7), so the
     * condition's truth table is pinned directly rather than inferred from a green suite.
     */
    #[DataProvider('fastPathTruthTable')]
    public function testTheFastPathFiresExactlyForTheTrimOnlyPolicy(
        bool $expected,
        RowNormalizer $normalizer,
    ): void {
        $property = new \ReflectionProperty(RowNormalizer::class, 'trimOnly');

        self::assertSame($expected, $property->getValue($normalizer));
    }

    /**
     * @return iterable<string, array{bool, RowNormalizer}>
     */
    public static function fastPathTruthTable(): iterable
    {
        yield 'the default policy takes it' => [true, new RowNormalizer()];
        yield 'trim spelled out takes it' => [true, new RowNormalizer(trim: true)];
        yield 'an encoding excludes it' => [false, new RowNormalizer(fromEncoding: 'ISO-8859-15')];
        yield 'trimming off excludes it' => [false, new RowNormalizer(trim: false)];
        yield 'collapsing excludes it' => [false, new RowNormalizer(collapseWhitespace: true)];
        yield 'blank-to-null excludes it' => [false, new RowNormalizer(blankToNull: true)];
        yield 'collapsing with trim on still excludes it' => [
            false,
            new RowNormalizer(trim: true, collapseWhitespace: true),
        ];
        yield 'every switch on excludes it' => [
            false,
            new RowNormalizer(
                fromEncoding: 'ISO-8859-15',
                trim: true,
                collapseWhitespace: true,
                blankToNull: true,
            ),
        ];
    }
}
