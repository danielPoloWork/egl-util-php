<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

/**
 * The field separator of a CSV document (spec r3 FR-28, RFC-0002).
 *
 * An enum rather than a validated string, for ADR-0015's reason reached again: the estate's
 * helper took a separator *name* and resolved it through a `match` with a
 * `default => ';'` arm, so a typo (`'semicolen'`) silently produced a semicolon-separated
 * file the caller believed was something else. An enum makes the wrong value unrepresentable
 * instead of quietly corrected.
 *
 * Four cases, not the eight the estate's `match` enumerated: these are the separators real
 * CSV uses. A caller needing `~` or `^` is describing a different format, and should say so
 * rather than reach for a CSV writer.
 */
enum Delimiter: string
{
    case Comma = ',';
    case Semicolon = ';';
    case Tab = "\t";
    case Pipe = '|';
}
