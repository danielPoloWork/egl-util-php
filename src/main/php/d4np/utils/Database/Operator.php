<?php

declare(strict_types=1);

namespace D4np\Utils\Database;

/**
 * A comparison operator in a `WHERE` clause.
 *
 * **Spec FR-07 does not ask for this enum. It asks for {@see Sort}, and this is the same
 * requirement applied to the same threat.**
 *
 * FR-07 makes the `ORDER BY` direction an enum for one reason: the direction is concatenated into
 * the SQL text and therefore cannot be a bound parameter. A comparison operator is concatenated
 * into the SQL text for exactly the same reason. A `where(string $column, string $operator, mixed
 * $value)` signature would bind the *value* safely, allowlist the *column* safely, and leave the
 * operator as an unchecked string spliced between them — an injection point in the middle of a
 * builder whose whole purpose is not to have one, and the more dangerous for looking harmless
 * next to two parameters that are carefully handled.
 *
 * Following the spec's own stated pattern is the smaller decision here than inventing an operator
 * allowlist, so this enum exists and `QueryBuilder::where()` takes it. Recorded in ADR-0015 as an
 * extension of FR-07 rather than a silent addition.
 *
 * `IN`, `IS NULL` and `IS NOT NULL` are deliberately absent: each needs a different number of
 * placeholders (many, and none), so they are separate methods on the builder rather than arms of
 * this enum that would silently bind the wrong thing.
 */
enum Operator: string
{
    case Equals = '=';
    case NotEquals = '<>';
    case LessThan = '<';
    case LessThanOrEqual = '<=';
    case GreaterThan = '>';
    case GreaterThanOrEqual = '>=';

    /**
     * Pattern matching.
     *
     * The value still binds as a parameter, so the *pattern* cannot inject SQL. What it can still
     * do is inject **wildcards**: a user-supplied `%` turns an equality-like lookup into a prefix
     * scan, which is a real problem (unbounded scans, and matching rows a user should not see).
     * That is spec FR-10's job — `Sanitizer::sqlLikePattern()`, milestone 5 — and is not solved
     * by binding alone. Named here so a caller reaching for `Like` is pointed at it.
     */
    case Like = 'LIKE';
}
