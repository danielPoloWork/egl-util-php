<?php

declare(strict_types=1);

namespace D4np\Utils\Database;

use D4np\Utils\Support\DatabaseException;

/**
 * The allowlist that stands between a table or column name and the SQL text, and the driver
 * quoting applied to what survives it (spec FR-07, ADR-0015; extracted at item 10.4, ADR-0044).
 *
 * **Why this is a class rather than two private methods on {@see QueryBuilder}.** Prepared
 * statements bind values, never identifiers — there is no placeholder legal where a column name
 * goes — so for identifiers the allowlist is the entire defence. Item 10.4 needed that same
 * defence on the write side ({@see MutationBuilder}), and there were exactly two ways to get it:
 * copy the pattern, or extract it. A copied allowlist is one edit away from two allowlists, and
 * the day they disagree the weaker one is the one that matters. This library already carries one
 * deliberately duplicated security constant (`QueryBuilder::LIKE_ESCAPE`, kept in step by a test
 * that asserts the two agree) and that is affordable because its drift produces a *wrong query*;
 * an allowlist that drifts produces a *vulnerability*, so it is shared rather than checked.
 *
 * Both callers therefore run the same `preg_match` and the same quoting, and every proof written
 * against either one — {@see \D4np\Utils\Tests\Database\InjectionTest}'s payload corpus included —
 * is a proof about this class.
 */
final class Identifier
{
    /**
     * Spec FR-07 writes this allowlist as `^[A-Za-z_][A-Za-z0-9_]*$`. Transcribed into PHP
     * literally, **that pattern has a hole**: PCRE's `$` also matches immediately before a
     * trailing newline, so `"id\n"` satisfies it. Verified directly when {@see QueryBuilder} was
     * written — it rendered as `SELECT "id\n" FROM "users"`, past an allowlist that is supposed
     * to be the only thing standing between an identifier and the SQL text.
     *
     * `\z` anchors at the true end of the subject and admits nothing after the last character.
     * This is the spec's *intent* implemented rather than its notation copied; ADR-0015 records
     * the difference so nobody later "corrects" it back to `$` for fidelity to FR-07's wording.
     */
    private const PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*\z/';

    /**
     * @param array{string, string} $quoteCharacters the driver's opening and closing quote
     */
    private function __construct(
        private readonly array $quoteCharacters,
    ) {
    }

    /**
     * The quoting rules of one driver, resolved once.
     *
     * Resolved once per builder rather than per identifier because `PDO::getAttribute()` is a
     * round trip and a realistic query quotes a dozen names (roadmap 4.6, ADR-0020). The driver
     * cannot change during a builder's life, so the pair is safe to hold.
     */
    public static function forDriver(string $driver): self
    {
        return new self(match ($driver) {
            'mysql' => ['`', '`'],
            'sqlsrv', 'dblib', 'mssql' => ['[', ']'],
            // The SQL standard's own form, and what SQLite, PostgreSQL, Oracle and the rest use.
            default => ['"', '"'],
        });
    }

    /**
     * Refuse anything that is not a bare identifier, then quote what survives.
     *
     * A qualified name (`users.id`) does **not** match, and that is deliberate rather than an
     * oversight: FR-07's allowlist has no `.` in it, and neither builder has a `JOIN`, so a
     * single-table statement never needs the qualification. Accepting it later — by validating
     * each dot-separated part — widens the allowlist and stays backward compatible; having
     * accepted it and needing to take it back would not.
     *
     * @throws DatabaseException if the name is not a bare identifier
     */
    public function quote(string $name): string
    {
        if (\preg_match(self::PATTERN, $name) !== 1) {
            throw new DatabaseException(\sprintf(
                'The identifier "%s" is not allowed. Table and column names cannot be bound as '
                . 'parameters — a prepared statement has no placeholder for them — so this '
                . 'library allows only bare names matching %s and refuses everything else. If '
                . 'this name came from user input, that is the vulnerability this refusal exists '
                . 'to stop; map the input to a known column at the call site instead.',
                $name,
                self::PATTERN,
            ));
        }

        [$open, $close] = $this->quoteCharacters;

        // Doubling the closing character is unreachable for anything that passed the allowlist —
        // such a name has no quote character in it. It is written correctly anyway rather than
        // skipped, because a quoting routine that is only safe when called in the right order is
        // a trap for whoever calls it next. Quoting also lets a legal-but-reserved word (`order`,
        // `select`) be used as a column name at all.
        return $open . \str_replace($close, $close . $close, $name) . $close;
    }
}
