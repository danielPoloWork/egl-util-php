<?php

declare(strict_types=1);

namespace D4np\Utils\Errors;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * A PSR-3 decorator that drops records below a floor and passes the rest through (spec FR-41).
 *
 * The point is that it wraps **any** `LoggerInterface`, not only this library's {@see Logger}: a
 * consumer with a third-party logger gets the same floor semantics, and the surveyed estate's habit
 * of building one logger per level — eight of them, per class, each re-reading its own enabled flag
 * — becomes one injected logger with one floor.
 *
 * **An unknown level throws even when it would have been dropped.** Filtering first would make a
 * typo'd level behave as a function of the floor: silently discarded below it, an exception above
 * it, so the bug surfaces when someone raises verbosity during an incident. PSR-3 requires the throw
 * for an unknown level and says nothing about filtering, so validation goes first —
 * {@see Level::rankOf()} answers both questions in one array lookup, which makes the order free.
 *
 * **The floor's rank is resolved once, at construction.** Re-deriving it per record would double the
 * work of the suppressed path — the path NFR-14 budgets at ≤ 0.5 µs, and the one a DEBUG-heavy
 * application takes thousands of times per request.
 */
final class LevelFilteredLogger extends AbstractLogger
{
    /**
     * The floor as an integer, for the comparison, and as a value, for {@see self::floor()}. Two
     * fields rather than one derivation: reconstructing the case from the rank would work only
     * while {@see Level}'s declaration order happened to match its ranks.
     */
    private readonly int $threshold;

    public function __construct(
        private readonly LoggerInterface $inner,
        private readonly Level $floor = Level::Debug,
    ) {
        $this->threshold = $floor->rank();
    }

    /**
     * @param mixed                $level   a PSR-3 level string, or a {@see Level}
     * @param array<string, mixed> $context
     *
     * @throws InvalidArgumentException if `$level` is not a PSR-3 level — checked before filtering
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $rank = Level::rankOf($level);

        if ($rank === null) {
            throw Level::invalid($level);
        }

        if ($rank > $this->threshold) {
            return;
        }

        $this->inner->log($level, $message, $context);
    }

    /**
     * The floor this logger was built with, as a value.
     *
     * A policy that can only be observed by exercising it cannot be asserted where it is
     * configured — the reasoning ADR-0026 applied to the session cookie flags, applied here so a
     * wiring test can check a channel's floor without emitting a record and reading a file.
     */
    public function floor(): Level
    {
        return $this->floor;
    }
}
