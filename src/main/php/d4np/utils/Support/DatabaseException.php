<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

/**
 * A persistence operation failed, or was refused before it could reach the driver.
 *
 * Covers both halves of the security model's persistence rules (RFC-0001): a driver-level
 * failure surfacing through `DatabaseConnection`'s pinned `ERRMODE_EXCEPTION`, and a
 * `QueryBuilder` **refusal** — an identifier that fails the allowlist, or a `LIMIT`/`OFFSET`
 * that is not a non-negative integer. The refusal case matters most: prepared statements bind
 * *values*, never table or column names, so rejecting an identifier is the only defence there
 * is, and it must be loud (spec §5, test T-02).
 */
final class DatabaseException extends UtilsException
{
}
