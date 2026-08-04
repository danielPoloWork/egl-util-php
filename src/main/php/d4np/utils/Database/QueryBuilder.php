<?php

declare(strict_types=1);

namespace D4np\Utils\Database;

use D4np\Utils\Support\DatabaseException;

/**
 * A fluent `SELECT` builder that binds every value and allowlists every identifier (spec FR-07,
 * ADR-0015).
 *
 * RFC-0001's security model has four mechanisms; this class is the second one, and it exists
 * because of a fact the first one cannot cover: **prepared statements bind values, never table or
 * column names.** A parameter placeholder is not legal SQL where an identifier goes, so an
 * identifier that reaches this builder from user input has no binding to hide behind. The
 * allowlist is the whole defence, which is why it is a refusal rather than an escape:
 *
 * - **Values** — always bound (`?` placeholders, {@see self::bindings()}). No method on this class
 *   concatenates a value into the SQL text.
 * - **Identifiers** — must match `^[A-Za-z_][A-Za-z0-9_]*$` exactly, or a {@see DatabaseException}
 *   is raised. Not sanitised, not truncated, not quoted-and-hoped: **refused**.
 * - **Keywords** — the `ORDER BY` direction and the comparison operator are closed enums
 *   ({@see Sort}, {@see Operator}), so no caller-supplied string reaches the SQL text at all.
 * - **`LIMIT`/`OFFSET`** — `int` by signature, and negative values are refused.
 *
 * **On quoting.** Identifiers are additionally wrapped in the driver's own quoting characters
 * (backticks on MySQL, brackets on SQL Server, double quotes elsewhere), with the quote character
 * doubled inside. It is tempting to call that escaping unreachable — an identifier that passed the
 * allowlist holds only letters, digits and underscores, so there is no quote character left to
 * escape — and this class's first draft said exactly that.
 *
 * It was wrong, and the way it was wrong is worth keeping: FR-07's allowlist transcribed literally
 * admitted `"id\n"`, because PCRE's `$` matches before a trailing newline (see
 * {@see self::IDENTIFIER}). For as long as that hole was open, the quoting is what kept the
 * smuggled character inside a quoted identifier instead of loose in the statement. The anchor is
 * fixed now, but the lesson stands: "the allowlist makes this unreachable" is a claim about a
 * regex being perfect, and the second layer costs nothing. Quoting is also what lets a
 * legal-but-reserved word (`order`, `select`) be used as a column name at all.
 *
 * Note that `PDO::quote()` is **not** used, and could not be: it produces a string *literal*
 * (`'id'`), so quoting an identifier with it yields a constant rather than a column reference —
 * a silent wrong answer rather than an error. Verified rather than assumed.
 *
 * Instances are immutable; every method returns a new builder, so a partially-built query can be
 * shared or reused without a later call mutating it under its holder.
 */
final class QueryBuilder
{
    /**
     * Spec FR-07 writes this allowlist as `^[A-Za-z_][A-Za-z0-9_]*$`. Transcribed into PHP
     * literally, **that pattern has a hole**: PCRE's `$` also matches immediately before a
     * trailing newline, so `"id\n"` satisfies it. Verified directly — it rendered as
     * `SELECT "id\n" FROM "users"`, past an allowlist that is supposed to be the only thing
     * standing between an identifier and the SQL text.
     *
     * `\z` anchors at the true end of the subject and admits nothing after the last character.
     * This is the spec's *intent* implemented rather than its notation copied; ADR-0015 records
     * the difference so nobody later "corrects" it back to `$` for fidelity to FR-07's wording.
     */
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*\z/';

    /** @var list<string> */
    private array $columns = [];

    /** @var list<string> */
    private array $conditions = [];

    /** @var list<mixed> */
    private array $bindings = [];

    /** @var list<string> */
    private array $orderBy = [];

    private ?int $limit = null;

    private ?int $offset = null;

    /**
     * @throws DatabaseException if the table name fails the allowlist
     */
    public function __construct(
        private readonly DatabaseConnection $connection,
        private readonly string $table,
    ) {
        $this->identifier($table);
    }

    /**
     * The columns to select. No arguments — or never calling this — selects `*`.
     *
     * @throws DatabaseException if any column fails the allowlist
     */
    public function select(string ...$columns): self
    {
        $clone = clone $this;
        // array_values() because a variadic is not necessarily a list: PHP 8.1 lets a caller pass
        // named arguments into one (`select(first: 'id')`), which produces string keys. PHPStan at
        // max level is what surfaced that — the keys are discarded here either way, but the
        // declared `list<string>` has to be true rather than nearly true.
        $clone->columns = array_values(array_map(fn (string $c): string => $this->identifier($c), $columns));

        return $clone;
    }

    /**
     * `WHERE <column> <operator> ?`, with the value bound.
     *
     * @throws DatabaseException if the column fails the allowlist
     */
    public function where(string $column, Operator $operator, mixed $value): self
    {
        $clone = clone $this;
        $clone->conditions[] = sprintf('%s %s ?', $this->identifier($column), $operator->value);
        $clone->bindings[] = $value;

        return $clone;
    }

    /**
     * `WHERE <column> IN (?, ?, …)`, one placeholder per value.
     *
     * An empty list is refused rather than rendered. `IN ()` is a syntax error on most drivers,
     * and the plausible silent alternatives are both wrong: rendering `IN (NULL)` matches nothing
     * by accident rather than by intent, and dropping the condition entirely would *widen* the
     * result set — a filter that silently stops filtering is the more dangerous failure.
     *
     * @param list<mixed> $values
     *
     * @throws DatabaseException if the column fails the allowlist, or the list is empty
     */
    public function whereIn(string $column, array $values): self
    {
        if ($values === []) {
            throw new DatabaseException(sprintf(
                'whereIn() on "%s" was given an empty list. `IN ()` is not valid SQL, and both '
                . 'ways of hiding that are wrong: matching nothing would be an accident rather '
                . 'than a decision, and dropping the condition would silently widen the result '
                . 'set. Decide at the call site whether an empty list means "no rows".',
                $column,
            ));
        }

        $clone = clone $this;
        $clone->conditions[] = sprintf(
            '%s IN (%s)',
            $this->identifier($column),
            implode(', ', array_fill(0, count($values), '?')),
        );
        foreach ($values as $value) {
            $clone->bindings[] = $value;
        }

        return $clone;
    }

    /**
     * `WHERE <column> IS NULL`.
     *
     * A separate method because `= NULL` is never true in SQL — binding `null` through
     * {@see self::where()} with {@see Operator::Equals} would produce a condition that silently
     * matches nothing.
     *
     * @throws DatabaseException if the column fails the allowlist
     */
    public function whereNull(string $column): self
    {
        $clone = clone $this;
        $clone->conditions[] = $this->identifier($column) . ' IS NULL';

        return $clone;
    }

    /**
     * `WHERE <column> IS NOT NULL`.
     *
     * @throws DatabaseException if the column fails the allowlist
     */
    public function whereNotNull(string $column): self
    {
        $clone = clone $this;
        $clone->conditions[] = $this->identifier($column) . ' IS NOT NULL';

        return $clone;
    }

    /**
     * `ORDER BY <column> <ASC|DESC>`. Repeated calls append, left to right.
     *
     * @throws DatabaseException if the column fails the allowlist
     */
    public function orderBy(string $column, Sort $direction = Sort::Asc): self
    {
        $clone = clone $this;
        $clone->orderBy[] = sprintf('%s %s', $this->identifier($column), $direction->value);

        return $clone;
    }

    /**
     * @throws DatabaseException if `$limit` is negative
     */
    public function limit(int $limit): self
    {
        $clone = clone $this;
        $clone->limit = $this->nonNegative($limit, 'LIMIT');

        return $clone;
    }

    /**
     * @throws DatabaseException if `$offset` is negative
     */
    public function offset(int $offset): self
    {
        $clone = clone $this;
        $clone->offset = $this->nonNegative($offset, 'OFFSET');

        return $clone;
    }

    /**
     * The SQL this builder represents, with `?` where every value goes.
     *
     * Paired with {@see self::bindings()}; the two are only meaningful together, and neither
     * contains a caller-supplied value.
     */
    public function toSql(): string
    {
        $sql = sprintf(
            'SELECT %s FROM %s',
            $this->columns === [] ? '*' : implode(', ', $this->columns),
            $this->quote($this->table),
        );

        if ($this->conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $this->conditions);
        }

        if ($this->orderBy !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }

        // Rendered as literals, which is safe only because both are `int` by signature and
        // already refused if negative — there is no string path to either. Several drivers reject
        // a bound parameter in LIMIT when prepares are real (which DatabaseConnection pins), so
        // binding them is not actually an option here.
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        return $sql;
    }

    /**
     * The values to bind, in placeholder order.
     *
     * @return list<mixed>
     */
    public function bindings(): array
    {
        return $this->bindings;
    }

    /**
     * Run the query and return every row.
     *
     * @return list<array<string, mixed>>
     *
     * @throws DatabaseException
     */
    public function get(): array
    {
        return $this->connection->select($this->toSql(), $this->bindings);
    }

    /**
     * Run the query and return the first row, or `null`.
     *
     * @return array<string, mixed>|null
     *
     * @throws DatabaseException
     */
    public function first(): ?array
    {
        return $this->connection->selectOne($this->toSql(), $this->bindings);
    }

    /**
     * Refuse anything that is not a bare identifier, then quote what survives.
     *
     * A qualified name (`users.id`) does **not** match, and that is deliberate rather than an
     * oversight: FR-07's allowlist has no `.` in it, this builder has no `JOIN`, and a single-table
     * query never needs the qualification. Accepting it later — by validating each dot-separated
     * part — widens the allowlist and stays backward compatible; having accepted it and needing to
     * take it back would not.
     *
     * @throws DatabaseException
     */
    private function identifier(string $name): string
    {
        if (preg_match(self::IDENTIFIER, $name) !== 1) {
            throw new DatabaseException(sprintf(
                'The identifier "%s" is not allowed. Table and column names cannot be bound as '
                . 'parameters — a prepared statement has no placeholder for them — so this '
                . 'builder allows only bare names matching %s and refuses everything else. If '
                . 'this name came from user input, that is the vulnerability this refusal exists '
                . 'to stop; map the input to a known column at the call site instead.',
                $name,
                self::IDENTIFIER,
            ));
        }

        return $this->quote($name);
    }

    /**
     * Wrap an already-allowlisted identifier in the driver's quoting characters.
     *
     * The doubling below is unreachable for anything that passed {@see self::identifier()} — such
     * a name has no quote character in it. It is written correctly anyway rather than skipped,
     * because a quoting function that is only safe when called in the right order is a trap for
     * whoever calls it next.
     */
    private function quote(string $identifier): string
    {
        return match ($this->connection->driver()) {
            'mysql' => '`' . str_replace('`', '``', $identifier) . '`',
            'sqlsrv', 'dblib', 'mssql' => '[' . str_replace(']', ']]', $identifier) . ']',
            // The SQL standard's own form, and what SQLite, PostgreSQL, Oracle and the rest use.
            default => '"' . str_replace('"', '""', $identifier) . '"',
        };
    }

    /**
     * @throws DatabaseException
     */
    private function nonNegative(int $value, string $clause): int
    {
        if ($value < 0) {
            throw new DatabaseException(sprintf(
                '%s must be a non-negative integer, got %d. Refused rather than clamped to 0: a '
                . 'negative value here means the caller computed it wrongly, and silently '
                . 'returning a different page than the one asked for would hide that.',
                $clause,
                $value,
            ));
        }

        return $value;
    }
}
