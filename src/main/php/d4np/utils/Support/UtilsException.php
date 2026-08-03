<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

use RuntimeException;

/**
 * The base class of every exception this library throws.
 *
 * Extends `RuntimeException` rather than `Exception`: everything this package raises is a
 * condition detected at run time (a malformed payload, a rejected identifier, an unreadable
 * file), never a compile-time-checkable programming contract in the `LogicException` sense.
 *
 * Deliberately **not** `final`. It is the documented extension point for a consumer who needs
 * an exception of their own inside this family; the concrete leaves below it are `final`,
 * because the hierarchy's shape is a MAJOR-version surface (RFC-0001) and a subclass of a leaf
 * would widen a contract this project has committed not to widen silently. See ADR-0004.
 */
class UtilsException extends RuntimeException implements UtilsThrowable
{
}
