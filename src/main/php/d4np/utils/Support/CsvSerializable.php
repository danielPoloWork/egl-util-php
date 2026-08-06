<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

/**
 * An object that knows how to become a CSV header and a CSV row (spec r3 FR-29, RFC-0002).
 *
 * The pairing is the whole point. The estate's version of this interface asked implementors,
 * in prose, to "maintain strict consistency" between the two methods — a plea a docblock
 * cannot enforce, and the two drift the first time a property is added to one and not the
 * other. {@see Csv::write()} emits the header from the **first** item and then checks every
 * row against its width, so a mismatch is a `CsvException` naming both counts rather than a
 * misaligned export nobody notices until a spreadsheet is already wrong.
 */
interface CsvSerializable
{
    /**
     * The column names, in the same order as {@see self::csvRow()} returns values.
     *
     * @return list<string>
     */
    public function csvHeader(): array;

    /**
     * This object's values, in the same order as {@see self::csvHeader()} names them.
     *
     * @return list<scalar|null>
     */
    public function csvRow(): array;
}
