<?php

declare(strict_types=1);

namespace D4np\Utils\Errors;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A PSR-3 logger that fans one record out to every logger it was given (spec FR-42).
 *
 * The composite of the Composite/Decorator pair this group needs: a channel that goes to a file
 * *and* the console is two loggers behind one `LoggerInterface`, so nothing downstream of the
 * wiring knows there is more than one destination.
 *
 * **The level is validated once, before any delegate sees the record.** Validating inside the loop
 * would let the first delegates write and the third throw, leaving the same record present in some
 * destinations and absent from others — the least readable state a log set can be in. PSR-3's one
 * mandated throw therefore happens before the fan-out, never during it.
 *
 * **A delegate that throws does not deprive the others, and is not swallowed either.** Every
 * delegate is attempted; the first failure is re-thrown once the loop is done. The alternative —
 * swallowing, as {@see Logger} deliberately does for its own writes — is right for a *leaf* that
 * owns a destination and must not escalate a write failure into a fatal error mid-incident
 * (ADR-0029), and wrong here: this class owns no destination, so a swallow would make a fan-out
 * where every delegate failed indistinguishable from one that worked. In the normal wiring nothing
 * is thrown at all, because the leaves already refuse to escalate — verified: a {@see Logger} whose
 * file became unwritable *after* construction returns silently rather than throwing. What survives
 * is the third-party case, where hiding the failure would be the library's own decision to make an
 * incident harder to read.
 *
 * Only the **first** failure is re-thrown: PHP has no suppressed-exception mechanism, as ADR-0016
 * recorded when a failing rollback had to lose either its own error or the original cause. The
 * later failures are lost, which is stated rather than hidden — see `MultiLoggerTest`.
 *
 * **An empty fan-out is legal, and it still validates.** A composite with no delegates checks the
 * level and discards the record, which PSR-3's own `NullLogger` does not — probed, `NullLogger`
 * accepts `'verbose'` without complaint. That difference is this class's own contract and is
 * asserted directly; what it is *not* is the reason {@see LoggerFactory} uses an empty fan-out for a
 * disabled channel. There, a {@see LevelFilteredLogger} sits above the sink and validates first, so
 * substituting `NullLogger` changes nothing observable — planting exactly that substitution left the
 * suite green (item 12.3, ADR-0055 D4). The factory uses this class in both branches because one
 * type of sink differing only in breadth reads more plainly than a branch that changes the class.
 */
final class MultiLogger extends AbstractLogger
{
    /** @var list<LoggerInterface> */
    private readonly array $loggers;

    public function __construct(LoggerInterface ...$loggers)
    {
        $this->loggers = \array_values($loggers);
    }

    /**
     * @param mixed                $level   a PSR-3 level string, or a {@see Level}
     * @param array<string, mixed> $context
     *
     * @throws InvalidArgumentException if `$level` is not a PSR-3 level — before any delegate writes
     * @throws Throwable               the first failure a delegate raised, after all were attempted
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (Level::rankOf($level) === null) {
            throw Level::invalid($level);
        }

        $first = null;

        foreach ($this->loggers as $logger) {
            try {
                $logger->log($level, $message, $context);
            } catch (Throwable $failure) {
                // Remembered, not re-thrown here: the remaining destinations still get the record.
                $first ??= $failure;
            }
        }

        if ($first !== null) {
            throw $first;
        }
    }

    /**
     * How many destinations this record reaches — a value, so a wiring is assertable without a write.
     */
    public function count(): int
    {
        return \count($this->loggers);
    }
}
