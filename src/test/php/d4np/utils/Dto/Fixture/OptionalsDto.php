<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

use D4np\Utils\Dto\DataTransferObject;

/** The four RFC-0001 R-4 cases in one place: required, nullable, defaulted, both. */
final class OptionalsDto extends DataTransferObject
{
    public function __construct(
        public readonly string $required,
        public readonly ?string $nullable,
        public readonly int $defaulted = 42,
        public readonly ?string $nullableAndDefaulted = 'preset',
    ) {
    }
}
