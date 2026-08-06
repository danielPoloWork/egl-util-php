<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Database;

use D4np\Utils\Database\Identifier;
use D4np\Utils\Support\DatabaseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * `Identifier` — the allowlist FR-07 specifies, extracted at item 10.4 (ADR-0044) so that the
 * read and write builders enforce one rule rather than two copies of it.
 *
 * The last test is the one that matters most and is the reason the extraction happened: it
 * asserts the pattern exists **once** in the production tree. Everything else here is behaviour
 * that `QueryBuilderTest` and `InjectionTest` already exercised through `QueryBuilder`; a second
 * allowlist would keep all of those green while being wrong.
 */
final class IdentifierTest extends TestCase
{
    // ---- what the allowlist admits ---------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function legalIdentifiers(): iterable
    {
        yield 'plain'                => ['id'];
        yield 'underscore first'     => ['_internal'];
        yield 'digits after a letter' => ['column1'];
        yield 'snake case'           => ['last_seen_at'];
        yield 'upper case'           => ['ID'];
        // Quoting is what makes a reserved word usable as a column name at all.
        yield 'reserved word'        => ['order'];
    }

    #[DataProvider('legalIdentifiers')]
    public function testALegalIdentifierIsQuotedAndOtherwiseUntouched(string $name): void
    {
        self::assertSame('"' . $name . '"', Identifier::forDriver('sqlite')->quote($name));
    }

    // ---- what it refuses -------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function illegalIdentifiers(): iterable
    {
        yield 'statement terminator' => ['id; DROP TABLE users'];
        yield 'comment'              => ['id --'];
        yield 'double quote'         => ['id"'];
        yield 'backtick'             => ['id`'];
        yield 'bracket'              => ['id]'];
        yield 'space'                => ['user name'];
        yield 'qualified name'       => ['users.id'];
        yield 'wildcard'             => ['*'];
        yield 'function call'        => ['count(*)'];
        yield 'leading digit'        => ['1id'];
        yield 'empty string'         => [''];
        yield 'null byte'            => ["id\0"];
        yield 'unicode'              => ['città'];
    }

    #[DataProvider('illegalIdentifiers')]
    public function testAnIllegalIdentifierIsRefusedRatherThanEscaped(string $name): void
    {
        $this->expectException(DatabaseException::class);

        Identifier::forDriver('sqlite')->quote($name);
    }

    /**
     * The hole FR-07's notation had, kept as its own test rather than one row of a corpus.
     *
     * PCRE's `$` matches before a trailing newline, so the spec's `…*$` transcribed literally
     * admitted `"id\n"` — verified at the time by watching it render into a `SELECT`. `\z` is
     * what closes it, and this test is what stops a later "correction" back to `$` for fidelity
     * to the spec's wording (ADR-0015).
     */
    public function testATrailingNewlineIsRefusedBecauseTheAnchorIsNotDollar(): void
    {
        $this->expectException(DatabaseException::class);

        Identifier::forDriver('sqlite')->quote("id\n");
    }

    public function testTheRefusalNamesTheIdentifierAndSaysWhy(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessageMatches('/identifier "users\.id" is not allowed/');
        // The message has to be usable by whoever hits it: it names the value and says that
        // mapping the input to a known column is the fix.
        $this->expectExceptionMessageMatches('/map the input to a known column/');

        Identifier::forDriver('sqlite')->quote('users.id');
    }

    // ---- per-driver quoting -----------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function drivers(): iterable
    {
        yield 'mysql'      => ['mysql', '`id`'];
        yield 'sqlsrv'     => ['sqlsrv', '[id]'];
        yield 'dblib'      => ['dblib', '[id]'];
        yield 'mssql'      => ['mssql', '[id]'];
        yield 'sqlite'     => ['sqlite', '"id"'];
        yield 'pgsql'      => ['pgsql', '"id"'];
        yield 'oci'        => ['oci', '"id"'];
        yield 'unknown'    => ['some-future-driver', '"id"'];
    }

    #[DataProvider('drivers')]
    public function testEachDriverGetsItsOwnQuotingWithTheStandardFormAsTheDefault(
        string $driver,
        string $expected,
    ): void {
        self::assertSame($expected, Identifier::forDriver($driver)->quote('id'));
    }

    // ---- the property the extraction exists for ---------------------------------------------------

    /**
     * One allowlist, in one file.
     *
     * Asserted as a mechanism (ADR-0027's pattern) because behaviour cannot see it: a second
     * copy of the pattern in `MutationBuilder` would pass every other test in this suite and in
     * `MutationBuilderTest`, right up until the day someone widened one of the two.
     *
     * **Code only, comments excluded** — `QueryBuilder`'s docblock quotes FR-07's notation while
     * explaining that the rule now lives here, and a check that counted prose would be red for
     * the documentation being right. `RepositoryTest` strips docblocks for the same reason.
     */
    public function testThePatternAppearsExactlyOnceInTheProductionTree(): void
    {
        $reflected = (new ReflectionClass(Identifier::class))->getFileName();
        self::assertIsString($reflected);

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname($reflected, 2)));
        $carriers = [];

        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);

            if (str_contains(self::codeOf($source), 'A-Za-z_][A-Za-z0-9_]')) {
                $carriers[] = $file->getFilename();
            }
        }

        sort($carriers);

        self::assertSame(
            ['Identifier.php'],
            $carriers,
            'the identifier allowlist must live in exactly one class: a copy of it is a second '
            . 'rule that can drift, and the weaker of two allowlists is the one that decides',
        );
    }

    /**
     * The source with every comment and docblock removed.
     */
    private static function codeOf(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
