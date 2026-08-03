<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

use Throwable;

/**
 * The marker every exception this library throws implements.
 *
 * Catching `UtilsThrowable` catches everything the package can raise, without coupling the
 * `catch` to a concrete base class. That indirection is the point: an exception that must
 * extend some other SPL base for interoperability can still join the family by implementing
 * this interface, so the "one thing to catch" contract survives a change no class hierarchy
 * could absorb. See ADR-0004.
 */
interface UtilsThrowable extends Throwable
{
}
