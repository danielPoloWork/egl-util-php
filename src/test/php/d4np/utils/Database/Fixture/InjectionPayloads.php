<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Database\Fixture;

/**
 * The injection corpora — **one of each, shared by every suite that needs them** (item 10.5).
 *
 * They lived inside `InjectionTest` (values) and `QueryBuilderTest` (identifiers) until item 10.4
 * added `MutationBuilder` and, with it, *a second, shorter identifier corpus* — ten payloads where
 * the read builder faced nineteen. Both suites were green, and the write builder was tested
 * against the weaker list: exactly the failure mode ADR-0044 argues about for the allowlist
 * itself, reproduced one layer up in the tests. Extracting them here is the same fix applied to
 * the same problem — one list, so a payload added for one caller protects every caller.
 *
 * Neither corpus is a list of scary strings. Each entry is a mechanism: quote break-out, comment
 * truncation, stacked statements, `UNION` exfiltration, charset tricks that defeat client-side
 * escaping, and the anchoring edge cases that defeat a hand-written allowlist.
 */
final class InjectionPayloads
{
    /**
     * Fuzzed **values** — things that must reach the driver as bound parameters and never as
     * statement text (spec §7 T-02 / T-13; ADR-0017).
     *
     * @return iterable<string, array{string}>
     */
    public static function values(): iterable
    {
        yield 'classic tautology' => ["' OR '1'='1"];
        yield 'tautology, double quotes' => ['" OR ""="'];
        yield 'stacked drop' => ["Robert'); DROP TABLE users;--"];
        yield 'stacked delete' => ['1; DELETE FROM users'];
        yield 'comment truncation (dash)' => ["admin'--"];
        yield 'comment truncation (hash)' => ['admin\'#'];
        yield 'block comment' => ["admin'/*"];
        yield 'union exfiltration' => ["' UNION SELECT token FROM secrets --"];
        yield 'union with null padding' => ["' UNION SELECT NULL, token FROM secrets --"];
        yield 'backslash escape' => ["\\' OR 1=1 --"];
        yield 'double backslash' => ["\\\\' OR 1=1 --"];
        // The classic charset attack: 0xBF 0x27 is a valid GBK character whose second byte is a
        // quote. It defeats a client-side escaper that does not know the connection charset --
        // which is exactly the scenario ADR-0014's real-prepares pin removes.
        yield 'GBK multibyte quote' => ["\xbf\x27 OR 1=1 --"];
        yield 'null byte' => ["admin\0' OR 1=1"];
        yield 'newline injection' => ["admin'\nOR 1=1"];
        yield 'CRLF injection' => ["admin'\r\nOR 1=1"];
        yield 'tab injection' => ["admin'\tOR 1=1"];
        yield 'nested quotes' => ["''''''"];
        yield 'percent wildcard' => ['100%'];
        yield 'underscore wildcard' => ['a_b'];
        yield 'sqlite pragma' => ["'; PRAGMA writable_schema = 1;--"];
        yield 'sqlite attach' => ["'; ATTACH DATABASE '/tmp/x' AS x;--"];
        yield 'unicode quote lookalike' => ["admin\u{2019} OR 1=1"];
        yield 'emoji' => ['🙂 OR 1=1'];
        yield 'very long' => [\str_repeat("' OR 1=1 --", 500)];
        yield 'only whitespace' => ['   '];
        yield 'empty string' => [''];
        yield 'json-ish' => ['{"$ne": null}'];
        yield 'template expression' => ['${1+1}'];
        yield 'sprintf token' => ['%s %d %%'];
    }

    /**
     * Hostile **identifiers** — table and column names, which cannot be bound and must therefore
     * be refused outright (spec FR-07; ADR-0015).
     *
     * @return iterable<string, array{string}>
     */
    public static function identifiers(): iterable
    {
        yield 'statement terminator' => ['id; DROP TABLE users'];
        yield 'comment' => ['id -- '];
        yield 'block comment' => ['id /* x */'];
        yield 'union' => ['id UNION SELECT password FROM admins'];
        yield 'quote break-out (double)' => ['id" FROM users; --'];
        yield 'quote break-out (backtick)' => ['id` FROM users; --'];
        yield 'quote break-out (bracket)' => ['id] FROM users; --'];
        yield 'subquery' => ['(SELECT 1)'];
        yield 'wildcard' => ['*'];
        yield 'qualified name' => ['users.id'];
        yield 'space' => ['user name'];
        yield 'leading digit' => ['1id'];
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'newline smuggling' => ["id\nDROP TABLE users"];
        // PCRE's `$` matches before a trailing newline, so FR-07's allowlist transcribed
        // literally admits this one. It did, until the pattern was anchored with `\z`.
        yield 'trailing newline (the `$` anchor hole)' => ["id\n"];
        yield 'trailing CRLF' => ["id\r\n"];
        yield 'null byte' => ["id\0"];
        yield 'unicode lookalike' => ['ｉd'];
        // Arrived with the write builder at item 10.4, and now protects the read builder too —
        // which is the point of there being one list.
        yield 'function call' => ['count(*)'];
        yield 'parenthesis' => ['id)'];
    }
}
