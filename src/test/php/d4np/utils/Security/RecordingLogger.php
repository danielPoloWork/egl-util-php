<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Security;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A PSR-3 logger that keeps what it was told, so a test can assert on it.
 *
 * Extends `Psr\Log\AbstractLogger` rather than implementing `LoggerInterface` directly: the
 * abstract base already routes the eight level-named methods (`warning()`, `error()`, …) into
 * `log()`, so this only has to implement the one that matters. A hand-written implementation of
 * all nine would be nine more chances to get a test double wrong.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param string|Stringable    $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
