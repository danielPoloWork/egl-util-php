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
 *   is raised. Not sanitised, not truncated, not quoted-and-hoped: **refused**. The allowlist and
 *   the quoting live in {@see Identifier} since item 10.4, so that the write side
 *   ({@see MutationBuilder}) enforces the same one rather than a copy of it.
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
     * The escape character {@see whereLike()} declares in its `ESCAPE` clause.
     *
     * Deliberately duplicated from `Sanitizer::LIKE_ESCAPE` rather than imported: `Sanitizer` is
     * in the `Security` group and RFC-0001's layering rule (enforced by deptrac, ADR-0012) allows
     * groups to depend downward on `Support` only. `SanitizerTest` asserts the two constants
     * agree, so the copy is checked rather than trusted.
     */
    private const LIKE_ESCAPE = '!';

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
     * Render `COUNT(*)` instead of the projection — see {@see self::asRowCount()} (spec r19
     * FR-47, ADR-0064).
     */
    private bool $countOnly = false;

    /**
     * The allowlist and quoting rules, resolved once (roadmap 4.6, ADR-0020).
     *
     * Every identifier this builder quotes goes through the same instance, because the
     * connection's driver cannot change during the builder's life — but before this was cached,
     * quoting asked {@see DatabaseConnection::driver()} afresh on every call, which is a
     * `PDO::getAttribute()` round trip per identifier. Measured at 0.125 µs each, paid a dozen
     * times in a realistic query.
     *
     * Carried across clones for free: `clone` copies the property, so the fluent chain resolves
     * the driver exactly once no matter how long it gets.
     */
    private readonly Identifier $identifiers;

    /**
     * The table name, allowlisted and quoted in the constructor.
     *
     * Held quoted rather than raw so that {@see self::toSql()} — which may be called many times
     * on one builder — does not re-run the allowlist for a name that was already checked before
     * the instance existed.
     */
    private readonly string $quotedTable;

    /**
     * @throws DatabaseException if the table name fails the allowlist
     */
    public function __construct(
        private readonly DatabaseConnection $connection,
        string $table,
    ) {
        $this->identifiers = Identifier::forDriver($connection->driver());
        $this->quotedTable = $this->identifiers->quote($table);
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
        $clone->columns = \array_values(\array_map(fn (string $c): string => $this->identifier($c), $columns));

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
        // Concatenation rather than sprintf(): measured at roughly a third the cost, and this
        // runs once per condition (roadmap 4.6).
        $clone->conditions[] = $this->identifier($column) . ' ' . $operator->value . ' ?';
        $clone->bindings[] = $value;

        return $clone;
    }

    /**
     * `WHERE <column> LIKE ? ESCAPE '!'`, with the pattern bound.
     *
     * Exists because {@see where()} with {@see Operator::Like} emits no `ESCAPE` clause, and
     * **without one an escaped pattern is silently wrong in a driver-dependent way**: MySQL and
     * PostgreSQL treat backslash as an escape by default so it appears to work, while SQLite has
     * no default escape and the pattern matches nothing. Verified directly rather than assumed.
     *
     * So the clause is emitted here, unconditionally, and the escape character is the one
     * `Sanitizer::sqlLikePattern()` applies. `!` rather than `\` because a backslash is special
     * inside a SQL string literal on several drivers — `ESCAPE '\'` is a *parse error* on SQLite —
     * whereas `!` needs no per-driver spelling.
     *
     * **The pattern is passed through as-is.** This does not escape it, because it cannot know
     * which wildcards the caller meant: a prefix search is
     * `Sanitizer::sqlLikePattern($term) . '%'`, where the user's portion is literal and the
     * trailing `%` is the caller's own. Escaping the whole pattern here would turn every `LIKE`
     * into an equality test.
     *
     * `Sanitizer` lives in the `Security` group and this class in `Database`, and RFC-0001's
     * layering rule forbids the import — hence the escape character is spelled out on both sides
     * rather than shared. `SanitizerTest` asserts the two agree, so the duplication cannot drift
     * unnoticed.
     *
     * @throws DatabaseException if the column fails the allowlist
     */
    public function whereLike(string $column, string $pattern): self
    {
        $clone = clone $this;
        $clone->conditions[] = $this->identifier($column) . " LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'";
        $clone->bindings[] = $pattern;

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
            throw new DatabaseException(\sprintf(
                'whereIn() on "%s" was given an empty list. `IN ()` is not valid SQL, and both '
                . 'ways of hiding that are wrong: matching nothing would be an accident rather '
                . 'than a decision, and dropping the condition would silently widen the result '
                . 'set. Decide at the call site whether an empty list means "no rows".',
                $column,
            ));
        }

        $clone = clone $this;
        $clone->conditions[] = $this->identifier($column)
            . ' IN (' . \implode(', ', \array_fill(0, \count($values), '?')) . ')';
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
        $clone->orderBy[] = $this->identifier($column) . ' ' . $direction->value;

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
     * The same query as `SELECT COUNT(*)` — the total this query's filters match, ignoring any
     * window placed on it (spec r19 FR-47; ADR-0064).
     *
     * Exists so that pagination can ask "how many rows in total?" **without any caller-supplied
     * text reaching SQL**. `COUNT(*)` cannot pass {@see Identifier}'s allowlist — it is not a bare
     * identifier — so a paginating layer that had to produce it itself would need
     * {@see SqlStatement::composed()}, spending the zero-in-library-uses property ADR-0041 relies
     * on for its review list. Placing it here keeps the count on the same footing as every other
     * clause this builder renders: assembled by the builder, never by a caller.
     *
     * **`ORDER BY`, `LIMIT` and `OFFSET` are dropped**, and dropping them is the point rather than
     * a convenience: a `LIMIT` would count the window instead of the population, and ordering rows
     * nobody looks at is work with no result. The `WHERE` clause and its bindings carry over
     * unchanged, so the count answers for exactly the rows the unwindowed query would return.
     */
    public function asRowCount(): self
    {
        $clone = clone $this;
        $clone->countOnly = true;
        $clone->orderBy = [];
        $clone->limit = null;
        $clone->offset = null;

        return $clone;
    }

    /**
     * Whether this query carries an `ORDER BY`.
     *
     * Exposed for one reason: **a paginated read without a total order is a correctness bug that
     * looks like it works.** SQL guarantees no row order absent `ORDER BY`, so two windows over
     * the same unordered query may repeat a row and skip another — and every individual page
     * looks perfectly valid. {@see \D4np\Utils\Persistence\Repository::fetchPage()} refuses an
     * unordered builder on the strength of this (ADR-0064).
     */
    public function isOrdered(): bool
    {
        return $this->orderBy !== [];
    }

    /**
     * The SQL this builder represents, with `?` where every value goes.
     *
     * Paired with {@see self::bindings()}; the two are only meaningful together, and neither
     * contains a caller-supplied value.
     */
    public function toSql(): string
    {
        $sql = 'SELECT '
            . ($this->countOnly ? 'COUNT(*)' : ($this->columns === [] ? '*' : \implode(', ', $this->columns)))
            . ' FROM ' . $this->quotedTable;

        if ($this->conditions !== []) {
            $sql .= ' WHERE ' . \implode(' AND ', $this->conditions);
        }

        if ($this->orderBy !== []) {
            $sql .= ' ORDER BY ' . \implode(', ', $this->orderBy);
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
        return $this->connection->select(SqlStatement::fromQueryBuilder($this));
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
        return $this->connection->selectOne(SqlStatement::fromQueryBuilder($this));
    }

    /**
     * Refuse anything that is not a bare identifier, then quote what survives.
     *
     * Delegated to {@see Identifier} since item 10.4 so that this builder and
     * {@see MutationBuilder} enforce one allowlist rather than two copies of it (ADR-0044). The
     * behaviour is unchanged, and {@see \D4np\Utils\Tests\Database\InjectionTest} is what says so.
     *
     * @throws DatabaseException
     */
    private function identifier(string $name): string
    {
        return $this->identifiers->quote($name);
    }

    /**
     * @throws DatabaseException
     */
    private function nonNegative(int $value, string $clause): int
    {
        if ($value < 0) {
            throw new DatabaseException(\sprintf(
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
