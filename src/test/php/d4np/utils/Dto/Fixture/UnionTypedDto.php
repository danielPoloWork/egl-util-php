<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

use D4np\Utils\Dto\DataTransferObject;

/**
 * A union-typed parameter. The metadata deliberately does not reduce a union to one arm
 * (ADR-0006), so this reaches the hydrator with no single type to check — and PHP's own check
 * at construction is what rejects a bad value, converted to a library exception.
 */
final class UnionTypedDto extends DataTransferObject
{
    public function __construct(
        public readonly int|string $value,
    ) {
    }
}
