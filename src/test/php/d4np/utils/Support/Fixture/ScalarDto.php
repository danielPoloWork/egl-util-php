<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support\Fixture;

/** The ordinary case: typed, promoted, readonly — the DTO shape spec FR-01 describes. */
final class ScalarDto
{
    public function __construct(
        public readonly string $email,
        public readonly int $age,
    ) {
    }
}
