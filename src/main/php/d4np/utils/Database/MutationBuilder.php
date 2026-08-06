<?php

declare(strict_types=1);

namespace D4np\Utils\Database;

use D4np\Utils\Support\DatabaseException;

/**
 * `INSERT`, `UPDATE` and `DELETE` composed with the allowlist {@see QueryBuilder} applies to
 * reads (item 10.4, ADR-0044; the write half of spec r3 FR-35, RFC-0002).
 *
 * **This class exists because `QueryBuilder` is `SELECT`-only, which nothing said out loud.**
 * RFC-0002 specifies {@see \D4np\Utils\Persistence\TableGateway} as *"select/insert/update/delete
 * composed exclusively through `QueryBuilder`"*, and that sentence is not implementable as
 * written: the builder has no write verb, and never had one. Writes therefore needed composition
 * of their own, and the only question worth arguing was where it lives. Not in `Persistence`,
 * where it would have put a second SQL generator in a group whose job is to *call* one — and
 * where the assembled text would have had to enter {@see SqlStatement} through
 * {@see SqlStatement::composed()}, spending the review-list property ADR-0041 built on that
 * method's zero in-library uses. So it lives here, beside the read builder, sharing its
 * {@see Identifier} and entering `SqlStatement` through a door of its own.
 *
 * The safety properties are the read builder's, unchanged:
 *
 * - **Values** — always bound. Column *values* never reach the SQL text; `?` does.
 * - **Column names** — allowlisted and driver-quoted by {@see Identifier}, the same instance of
 *   the same rule the read side runs.
 * - **Unqualified writes** — refused. See {@see self::update()} and {@see self::delete()}.
 *
 * Each verb is a named constructor rather than a fluent step, so an instance always carries a
 * complete statement: there is no half-built `MutationBuilder` whose {@see self::toSql()} would
 * have to invent an answer.
 *
 * ```php
 * $statement = SqlStatement::fromMutation(
 *     MutationBuilder::insert($connection, 'users', ['name' => 'Ada', 'age' => 36]),
 * );
 * ```
 *
 * **Non-goals**, stated so nobody has to infer them from the absence of a method: no `JOIN`, no
 * `RETURNING`, no upsert, no bulk multi-row `INSERT`, and criteria that are equality or `IS NULL`
 * and nothing else. Every one of those is legitimate SQL that this class deliberately does not
 * model — {@see SqlStatement::literal()} takes hand-written dialect SQL with placeholders, which
 * is exactly what FR-33 exists to allow.
 */
final class MutationBuilder
{
    /**
     * @param list<mixed> $bindings
     */
    private function __construct(
        private readonly string $sql,
        private readonly array $bindings,
    ) {
    }

    /**
     * `INSERT INTO <table> (<columns>) VALUES (?, …)`.
     *
     * @param array<array-key, mixed> $values column name => value; keys are allowlisted, values bound
     *
     * @throws DatabaseException if the table or a column fails the allowlist, or `$values` is empty
     */
    public static function insert(DatabaseConnection $connection, string $table, array $values): self
    {
        $identifiers = Identifier::forDriver($connection->driver());
        $quotedTable = $identifiers->quote($table);

        if ($values === []) {
            throw new DatabaseException(sprintf(
                'insert() into "%s" was given no values. An INSERT with no columns is not a '
                . 'smaller insert, it is a different statement (the driver-specific DEFAULT '
                . 'VALUES form), so writing one has to be deliberate rather than the result of '
                . 'an empty array arriving where a row was expected.',
                $table,
            ));
        }

        $columns = [];
        $bindings = [];

        foreach ($values as $column => $value) {
            // (string) because PHP silently turns a numeric-string array key into an int, and a
            // column named "0" is exactly the kind of thing the allowlist should refuse loudly
            // rather than the kind of thing a TypeError should hide.
            $columns[] = $identifiers->quote((string) $column);
            $bindings[] = $value;
        }

        return new self(
            'INSERT INTO ' . $quotedTable
                . ' (' . implode(', ', $columns) . ')'
                . ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
            $bindings,
        );
    }

    /**
     * `UPDATE <table> SET <column> = ?, … WHERE <criteria>`.
     *
     * **Empty criteria are refused, and that refusal is the point of this method's signature.**
     * An `UPDATE` with no `WHERE` rewrites every row in the table, and an empty array is precisely
     * what an unvalidated `$request['filters'] ?? []` collapses to — the shape where the caller
     * believes they are updating one row and the statement updates all of them. The same reasoning
     * {@see QueryBuilder::whereIn()} applies to an empty `IN ()` list applies here with the damage
     * turned up: a filter that silently stops filtering is worse than an error, and here it is
     * not even recoverable. A deliberate whole-table update is still available, spelled out in
     * one conspicuous {@see SqlStatement::literal()} line.
     *
     * @param array<array-key, mixed> $values   column name => new value
     * @param array<array-key, mixed> $criteria column name => value; `null` renders `IS NULL`
     *
     * @throws DatabaseException if an identifier fails the allowlist, or either array is empty
     */
    public static function update(
        DatabaseConnection $connection,
        string $table,
        array $values,
        array $criteria,
    ): self {
        $identifiers = Identifier::forDriver($connection->driver());
        $quotedTable = $identifiers->quote($table);

        if ($values === []) {
            throw new DatabaseException(sprintf(
                'update() on "%s" was given no values to set. `SET` with nothing after it is a '
                . 'syntax error, and the two silent alternatives are both wrong: running the '
                . 'statement without the SET clause would delete-by-update, and skipping the '
                . 'statement would report success for work that never happened.',
                $table,
            ));
        }

        $assignments = [];
        $bindings = [];

        foreach ($values as $column => $value) {
            $assignments[] = $identifiers->quote((string) $column) . ' = ?';
            $bindings[] = $value;
        }

        [$where, $whereBindings] = self::conditions($identifiers, $criteria, 'update', $table);

        return new self(
            'UPDATE ' . $quotedTable . ' SET ' . implode(', ', $assignments) . ' WHERE ' . $where,
            [...$bindings, ...$whereBindings],
        );
    }

    /**
     * `DELETE FROM <table> WHERE <criteria>`.
     *
     * Empty criteria are refused for {@see self::update()}'s reason, with the consequence one
     * step further along: an unqualified `DELETE` empties the table.
     *
     * @param array<array-key, mixed> $criteria column name => value; `null` renders `IS NULL`
     *
     * @throws DatabaseException if an identifier fails the allowlist, or `$criteria` is empty
     */
    public static function delete(DatabaseConnection $connection, string $table, array $criteria): self
    {
        $identifiers = Identifier::forDriver($connection->driver());
        $quotedTable = $identifiers->quote($table);

        [$where, $bindings] = self::conditions($identifiers, $criteria, 'delete', $table);

        return new self('DELETE FROM ' . $quotedTable . ' WHERE ' . $where, $bindings);
    }

    /**
     * The SQL this builder represents, with `?` where every value goes.
     *
     * Paired with {@see self::bindings()}; the two are only meaningful together, and neither
     * contains a caller-supplied value. {@see SqlStatement::fromMutation()} is what puts them
     * back together.
     */
    public function toSql(): string
    {
        return $this->sql;
    }

    /**
     * The values to bind, in placeholder order — `SET` values first, then the criteria.
     *
     * @return list<mixed>
     */
    public function bindings(): array
    {
        return $this->bindings;
    }

    /**
     * Render the `WHERE` clause shared by {@see self::update()} and {@see self::delete()}.
     *
     * `null` becomes `IS NULL` rather than `= ?` with a bound `null`, because `= NULL` is never
     * true in SQL: binding it would produce a condition that silently matches no row, so an
     * update would report zero rows affected and a delete would quietly do nothing. This is the
     * same trap {@see QueryBuilder::whereNull()} exists for on the read side.
     *
     * @param array<array-key, mixed> $criteria
     *
     * @return array{string, list<mixed>}
     *
     * @throws DatabaseException
     */
    private static function conditions(
        Identifier $identifiers,
        array $criteria,
        string $verb,
        string $table,
    ): array {
        if ($criteria === []) {
            throw new DatabaseException(sprintf(
                '%s() on "%s" was given no criteria. An unqualified %s applies to every row in '
                . 'the table, and an empty array is what an unvalidated request filter collapses '
                . 'to — so it is refused here rather than executed. If the whole table really is '
                . 'the target, say so in a SqlStatement::literal() the next reader cannot miss.',
                $verb,
                $table,
                strtoupper($verb),
            ));
        }

        $clauses = [];
        $bindings = [];

        foreach ($criteria as $column => $value) {
            $quoted = $identifiers->quote((string) $column);

            if ($value === null) {
                $clauses[] = $quoted . ' IS NULL';

                continue;
            }

            $clauses[] = $quoted . ' = ?';
            $bindings[] = $value;
        }

        return [implode(' AND ', $clauses), $bindings];
    }
}
