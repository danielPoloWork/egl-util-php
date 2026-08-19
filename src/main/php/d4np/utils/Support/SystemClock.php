<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * The wall clock (spec FR-45, RFC-0003; ADR-0062).
 *
 * This is the production half of the library's **sanctioned time seam**: every time-touching API
 * in this library accepts {@see ClockInterface} rather than reading the system time itself, and
 * this is the implementation production code passes. Tests pass {@see FrozenClock} instead — the
 * point of the seam is that a test never sleeps and never races the machine it runs on.
 *
 * `now()` returns a **fresh** `DateTimeImmutable` on every call, in PHP's default timezone unless
 * a {@see DateTimeZone} was injected at construction. The default matches what a plain
 * `new DateTimeImmutable('now')` does, so the seam changes *where* time is read, never *what* is
 * read. For every in-library use — retry deadlines, token expiry, refill arithmetic — instants
 * are compared and subtracted, and instant arithmetic is timezone-independent; the timezone only
 * matters when a caller formats the value, which is the caller's business.
 *
 * Construction cannot fail: the timezone parameter is a `DateTimeZone` object, not a string, so
 * there is nothing to validate here — an invalid zone never becomes a `DateTimeZone` in the first
 * place.
 */
final class SystemClock implements ClockInterface
{
    public function __construct(private readonly ?DateTimeZone $timezone = null)
    {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->timezone);
    }
}
