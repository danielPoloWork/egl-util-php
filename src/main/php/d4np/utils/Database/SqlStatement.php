<?php

declare(strict_types=1);

namespace D4np\Utils\Database;

/**
 * An immutable pairing of SQL text and the parameters bound to it (spec r3 FR-33, RFC-0002;
 * ADR-0039).
 *
 * **The narrow waist, not a new safety mechanism.** The surveyed estate's query-factory
 * classes never had a type that forced text and parameters to travel together: a method
 * signature of `execute(string $sql, array $params = [])` does not stop a caller from
 * writing `execute("... {$value} ...")` and leaving `$params` empty — nothing in that shape
 * distinguishes "I forgot to bind" from "there is nothing to bind". That is exactly the
 * estate's failure mode (199 interpolation sites, 0 bound parameters), and no amount of
 * discipline at each of 199 call sites prevented it.
 *
 * `SqlStatement` does not make binding safer at the byte level — `DatabaseConnection`
 * already only ever executes through real prepares (ADR-0014), so a value bound through
 * either shape was equally safe on the wire. What it changes is where a reviewer has to
 * look: after this class exists, {@see DatabaseConnection}'s query methods accept **only**
 * a `SqlStatement`, so there is exactly one place in this codebase where SQL text and
 * parameters are still two separate variables that could be assembled wrongly —
 * {@see QueryBuilder::toSql()}/{@see QueryBuilder::bindings()} and this class's own
 * constructor call. Every other caller, present and future ({@see \D4np\Utils\Persistence}'s
 * upcoming `Repository`, roadmap 10.3), receives or builds a `SqlStatement` and cannot
 * un-pair it.
 */
final class SqlStatement
{
    /**
     * @param array<string|int, mixed> $parameters bound as parameters — never interpolated
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $parameters = [],
    ) {
    }
}
