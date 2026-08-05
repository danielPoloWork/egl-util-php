<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Errors\Fixture;

use Psr\Log\AbstractLogger;

/**
 * A PSR-3 logger that keeps what it was told, so assertions can be made about the record rather
 * than about a file.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param mixed                $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => is_string($level) ? $level : get_debug_type($level),
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
