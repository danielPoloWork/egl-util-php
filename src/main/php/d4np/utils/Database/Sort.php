<?php

declare(strict_types=1);

namespace D4np\Utils\Database;

/**
 * An `ORDER BY` direction (spec FR-07).
 *
 * An enum rather than a string because this value is **concatenated into the SQL text** — it
 * cannot be a bound parameter, so a `string $direction` would be an injection point sitting in
 * the middle of a builder whose entire purpose is to not have any. Making it a closed type moves
 * the check from run time to the type system: there is no value of this type that is not one of
 * two keywords.
 *
 * Spec FR-07 names this design directly (*"ORDER BY direction is an enum"*), and the same
 * reasoning is why {@see Operator} exists.
 */
enum Sort: string
{
    case Asc = 'ASC';
    case Desc = 'DESC';
}
