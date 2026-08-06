<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

/**
 * A {@see FileSequence} has issued every number its cap allows for the current window.
 *
 * The alternative a sequence must never take is wrapping. A counter that silently returns to
 * `1` re-issues identifiers that are already in use, and the damage surfaces far from the
 * cause — as a duplicate key, or worse, as a row quietly attached to the wrong record. This
 * exception is the sequence refusing to do that; the caller widens the cap, narrows the
 * window, or stops.
 */
final class SequenceExhaustedException extends UtilsException
{
}
