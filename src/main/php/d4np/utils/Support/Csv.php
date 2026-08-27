<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

use Generator;

/**
 * Streaming CSV writing and reading (spec r3 FR-28/FR-29, RFC-0002; ADR-0037).
 *
 * Three decisions distinguish this from a `fputcsv()` loop.
 *
 * **RFC 4180 fidelity.** PHP's CSV functions default to a backslash escape character that
 * RFC 4180 does not define, and it **corrupts data**: a field ending in a backslash writes
 * as `"ends with \"` — where the backslash escapes the closing quote — and reads back as one
 * unterminated field that swallows the rest of the line. Every call here passes
 * `escape: ''`, so the quote-doubling of the actual standard is the only mechanism in play
 * and a value survives the round trip (ADR-0037; the same reason PHP 8.4 deprecates the
 * default).
 *
 * **Failures throw.** `fputcsv()` and `fopen()` report failure by return value, and the
 * estate's exporter returned `false` from four different paths with the reason accumulated
 * into a local `$message` variable that nothing ever read. Here every path raises
 * {@see CsvException} or {@see FileException}.
 *
 * **Memory is proportional to a row.** {@see self::write()} consumes an `iterable`, so a
 * generator streams; {@see self::read()} is itself a generator. Neither buffers the table
 * (spec NFR-12).
 *
 * ## CSV injection is opt-in to defend against, and off by default
 *
 * **`$guardFormulas` is `false` unless you pass `true`.** A field beginning `=`, `+`, `-`, `@`, a
 * tab or a carriage return is executed as a formula by Excel, LibreOffice and Google Sheets when the
 * file is opened — so `=WEBSERVICE(...)` in a user-supplied name becomes a request from the
 * machine of whoever opens your export.
 *
 * The default is deliberate and is ADR-0037's recorded call: the guard *alters exported data* by
 * prefixing an apostrophe, and a library that silently rewrote values would repeat the
 * input-mutilation mistake spec §1 exists to reject. Whether the file is going to a spreadsheet or
 * to another program is something only the caller knows.
 *
 * **The consequence is that the safe choice is the one you have to type** (issue #102, ADR-0079).
 * If any field can contain text a user supplied, pass it:
 *
 * ```php
 * Csv::write($path, $rows, guardFormulas: true);   // exports opened in a spreadsheet
 * Csv::write($path, $rows);                        // machine-to-machine, values untouched
 * ```
 */
final class Csv
{
    /**
     * Leading characters a spreadsheet may read as the start of a formula (OWASP's CSV
     * Injection set, including the tab and carriage return that Excel also treats as
     * formula-leading once the cell is parsed).
     */
    private const FORMULA_LEADERS = ['=', '+', '-', '@', "\t", "\r"];

    /** Prefix that forces a spreadsheet to treat a cell as literal text. */
    private const TEXT_PREFIX = "'";

    private function __construct()
    {
        // Static-only: no instances.
    }

    /**
     * Write `$rows` to `$path`, atomically and without buffering the table.
     *
     * Rows may be arrays of scalars, or {@see CsvSerializable} objects. When the **first**
     * row is `CsvSerializable`, its `csvHeader()` is written first and every subsequent row
     * is checked against that width — the pairing the interface promises, enforced instead of
     * requested. Plain arrays are written as given: their shape is the caller's business.
     *
     * `$guardFormulas` prefixes any field beginning with a formula character with an
     * apostrophe. It is **off by default**, deliberately: the guard alters exported data, and
     * a library that silently rewrote values would be repeating the input-mutilation mistake
     * spec §1 exists to reject. Turning it on is a decision about *where the file is going*,
     * which only the caller knows (ADR-0037).
     *
     * @param iterable<array<array-key, scalar|null>|CsvSerializable> $rows
     *
     * @return int the number of data rows written, not counting a header
     *
     * @throws CsvException  if a row is empty or cannot be written, or a `CsvSerializable`
     *                       row's width disagrees with the header
     * @throws FileException if the file cannot be replaced atomically
     */
    public static function write(
        string $path,
        iterable $rows,
        Delimiter $delimiter = Delimiter::Comma,
        bool $guardFormulas = false,
    ): int {
        $written = 0;

        File::writeStream($path, static function ($handle) use ($rows, $delimiter, $guardFormulas, &$written): void {
            $headerWidth = null;

            foreach ($rows as $row) {
                if ($row instanceof CsvSerializable) {
                    if ($headerWidth === null) {
                        $header = $row->csvHeader();
                        $headerWidth = \count($header);
                        self::putRow($handle, $header, $delimiter, $guardFormulas);
                    }

                    $values = $row->csvRow();
                    if (\count($values) !== $headerWidth) {
                        throw new CsvException(\sprintf(
                            '%s produced %d value(s) for a %d-column header. csvHeader() and '
                            . 'csvRow() must agree, in order and in count.',
                            $row::class,
                            \count($values),
                            $headerWidth,
                        ));
                    }

                    self::putRow($handle, $values, $delimiter, $guardFormulas);
                } else {
                    self::putRow($handle, $row, $delimiter, $guardFormulas);
                }

                $written++;
            }
        });

        return $written;
    }

    /**
     * Read `$path` row by row.
     *
     * A generator, so a file larger than memory is readable; nothing is buffered beyond the
     * current row. Entirely blank lines are skipped rather than yielded: `fgetcsv()` reports
     * one as `[null]`, a phantom single-column row that every consumer would have to filter.
     * A line holding one *quoted empty field* (`""`) is a real row and is yielded as `['']`.
     *
     * @return Generator<int, list<string|null>>
     *
     * @throws FileException if the path is not a readable file
     * @throws CsvException  if reading fails part-way through
     */
    public static function read(string $path, Delimiter $delimiter = Delimiter::Comma): Generator
    {
        if (!\is_file($path)) {
            throw new FileException(\sprintf('Cannot read "%s": not a file.', $path));
        }

        $handle = @\fopen($path, 'rb');
        if ($handle === false) {
            throw new FileException(\sprintf('Cannot read "%s": failed to open for reading.', $path));
        }

        try {
            while (true) {
                $row = \fgetcsv($handle, 0, $delimiter->value, '"', '');

                if ($row === false) {
                    if (!\feof($handle)) {
                        throw new CsvException(\sprintf('Reading "%s" failed before the end of the file.', $path));
                    }

                    return;
                }

                // fgetcsv() reports a blank line as [null] — not a row.
                if ($row === [null]) {
                    continue;
                }

                yield $row;
            }
        } finally {
            @\fclose($handle);
        }
    }

    /**
     * @param resource $handle
     * @param array<array-key, scalar|null> $row
     *
     * @throws CsvException
     */
    private static function putRow($handle, array $row, Delimiter $delimiter, bool $guardFormulas): void
    {
        $fields = \array_values($row);

        if ($fields === []) {
            throw new CsvException(
                'A CSV row must have at least one field. A zero-column row has no '
                . 'representation in CSV — it would be written as a blank line and read back '
                . 'as nothing.',
            );
        }

        if ($guardFormulas) {
            $fields = \array_map(self::guard(...), $fields);
        }

        // A row of exactly one empty field is the one shape `fputcsv()` cannot express: it
        // emits a bare newline, which is indistinguishable from a blank line and therefore
        // vanishes on read — measured, and a silent row loss. The explicit empty-field form
        // is written instead; `fputcsv()` never quotes an empty field, and there is no flag
        // to make it (ADR-0037).
        if (\count($fields) === 1 && (string) ($fields[0] ?? '') === '') {
            if (@\fwrite($handle, "\"\"\n") === false) {
                throw new CsvException('Failed to write a CSV row.');
            }

            return;
        }

        // escape: '' — see the class docblock and ADR-0037. Not a stylistic choice: the
        // default corrupts any field ending in a backslash.
        if (@\fputcsv($handle, $fields, $delimiter->value, '"', '') === false) {
            throw new CsvException('Failed to write a CSV row.');
        }
    }

    /**
     * Neutralize a leading formula character by prefixing an apostrophe, which spreadsheets
     * read as "this cell is text".
     *
     * Applied only when the caller opted in. Note that it **changes the value**: the
     * apostrophe is part of the field on any subsequent read, so a guarded file no longer
     * round-trips to its input. That is the cost the opt-in buys, and the reason it is not
     * the default.
     */
    private static function guard(string|int|float|bool|null $field): string|int|float|bool|null
    {
        if (!\is_string($field) || $field === '') {
            return $field;
        }

        return \in_array($field[0], self::FORMULA_LEADERS, true)
            ? self::TEXT_PREFIX . $field
            : $field;
    }
}
