<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

/**
 * A CSV document could not be written or read.
 *
 * Distinct from {@see FileException}, which reports why the *file* could not be touched:
 * this one reports why its *contents* could not be produced or consumed — a row whose width
 * disagrees with the header a {@see CsvSerializable} declared, or a failed field write. A
 * consumer that wants either treats them coarsely through {@see UtilsException}.
 */
final class CsvException extends UtilsException
{
}
