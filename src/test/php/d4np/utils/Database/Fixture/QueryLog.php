<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Database\Fixture;

/**
 * Everything the driver was asked to prepare, and everything it was asked to bind.
 *
 * Spec §7's T-02 requires that *"fuzzed value payloads reach the driver only as bound parameters
 * via query-log assertion"*. This is that log. It sits at the PDO boundary — the last place inside
 * this process where the SQL text and the values are still separable — and lets a test assert the
 * property directly rather than infer it: **the payload appears in the parameter array and never
 * in the statement text.**
 *
 * That boundary is the strongest one available here without a server. PDO offers no way to see the
 * bytes it puts on the wire, and no database is available in CI to read a real `general_log` from.
 * What makes the boundary sufficient is ADR-0014's pinned `ATTR_EMULATE_PREPARES=false`: with real
 * prepares, PDO does not interpolate values into the SQL text at all — statement and parameters
 * travel to the server separately. So SQL text that contains only placeholders *at this boundary*
 * is SQL text that contains only placeholders on the wire. Were emulation ever silently re-enabled,
 * `DatabaseConnectionTest` fails first.
 */
final class QueryLog
{
    /** @var list<array{sql: string, params: array<array-key, mixed>|null}> */
    public array $entries = [];

    /**
     * Every distinct statement text the driver was asked to prepare.
     *
     * @return list<string>
     */
    public function statements(): array
    {
        return \array_values(\array_unique(\array_map(
            static fn (array $entry): string => $entry['sql'],
            $this->entries,
        )));
    }

    /**
     * Every value bound, across every statement, flattened.
     *
     * @return list<mixed>
     */
    public function boundValues(): array
    {
        $values = [];
        foreach ($this->entries as $entry) {
            foreach ($entry['params'] ?? [] as $value) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
