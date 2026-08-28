<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

/**
 * A line-ending convention {@see Str::normalizeEol()} can target (spec r31 FR-57, RFC-0004).
 *
 * Two cases, not three: a lone `\r` (the classic Mac OS 9 ending) is a normalization *source*
 * {@see Str::normalizeEol()} always recognizes and rewrites — nothing asks to *produce* it, so
 * it is not a target anyone can select.
 */
enum Eol: string
{
    case Lf = "\n";
    case CrLf = "\r\n";
}
