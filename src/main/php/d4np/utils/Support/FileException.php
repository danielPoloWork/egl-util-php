<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

/**
 * A filesystem operation failed.
 *
 * `File::read()` / `File::write()` report failure by throwing rather than by returning `false`
 * — the native functions' `false`-on-error convention is exactly what makes unchecked
 * filesystem code silently lose data. Covers the lock that could not be taken, the atomic
 * temp-then-rename that could not complete, and the MIME probe that could not read the file
 * (spec §2 items 22–23).
 */
final class FileException extends UtilsException
{
}
