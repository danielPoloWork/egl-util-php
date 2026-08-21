<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

use DateInterval;

/**
 * The test double for the sleep seam (spec r21 FR-49, RFC-0003; ADR-0066).
 *
 * Records what it was asked to wait and **advances the {@see FrozenClock} it was given by exactly
 * that much**, without blocking. That coupling is the whole design: a double that merely recorded
 * the request would leave time standing still, so a deadline could never be reached and
 * {@see Retrier}'s wall-clock bound — the part of FR-49 that ADR-0049 paid for once already — would
 * have no test that could observe it. Advancing the clock is what makes "tests never sleep" and
 * "the deadline is exercised" the same run rather than a trade.
 *
 * Shipped in `src/main` beside {@see FrozenClock} for the reason ADR-0062 gives: the value of a
 * sanctioned seam is that nobody re-implements its double per project.
 *
 * An `ISO 8601` duration string cannot express a fraction of a second, so the sub-second part of the
 * advance is set through `DateInterval::$f` — probed, and honoured by `DateTimeImmutable::add()` to
 * the microsecond. The alternative, `DateInterval::createFromDateString()`, works identically but is
 * typed `DateInterval|false`, and its `false` branch cannot fire on a string this class builds
 * itself: that is the dead defensive code ADR-0022 removed from {@see \D4np\Utils\Security\Hash}.
 */
final class FrozenSleeper implements Sleeper
{
    /**
     * Every duration this sleeper was asked for, in milliseconds and in order.
     *
     * @var list<int>
     */
    private array $requested = [];

    public function __construct(private readonly FrozenClock $clock)
    {
    }

    public function sleep(int $milliseconds): void
    {
        $this->requested[] = $milliseconds;

        if ($milliseconds <= 0) {
            return;
        }

        $advance = new DateInterval('PT' . \intdiv($milliseconds, 1000) . 'S');
        $advance->f = ($milliseconds % 1000) / 1000;

        $this->clock->advance($advance);
    }

    /**
     * The recorded durations, in milliseconds, in the order they were requested.
     *
     * @return list<int>
     */
    public function requested(): array
    {
        return $this->requested;
    }

    /**
     * The total time slept, in milliseconds.
     */
    public function total(): int
    {
        return \array_sum($this->requested);
    }
}
