<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Errors\Fixture;

use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * A PSR-3 logger that fails on every record, and remembers that it was asked.
 *
 * PSR-3 tells implementations not to throw from a write, so this stands in for the case that
 * matters to {@see \D4np\Utils\Errors\MultiLogger}: a *third-party* logger that does it anyway —
 * a handler with a full disk, a network appender with no route. This library's own
 * {@see \D4np\Utils\Errors\Logger} never gets here (verified: it swallows its own write failures by
 * design, ADR-0029), which is precisely why the composite's behaviour needs a fixture to exercise.
 */
final class ThrowingLogger extends AbstractLogger
{
    public int $attempts = 0;

    public function __construct(private readonly string $label = 'delegate failed')
    {
    }

    /**
     * @param mixed                $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        ++$this->attempts;

        throw new RuntimeException($this->label);
    }
}
