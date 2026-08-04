<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support\Fixture;

/** Nullable and defaulted parameters — the two halves of RFC-0001 R-4's "optional" rule. */
final class OptionalsDto
{
    public function __construct(
        public readonly string $required,
        public readonly ?string $nullable,
        public readonly int $defaulted = 42,
        public readonly ?string $nullableAndDefaulted = null,
    ) {
    }
}
