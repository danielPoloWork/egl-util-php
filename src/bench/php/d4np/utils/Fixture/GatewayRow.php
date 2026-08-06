<?php

declare(strict_types=1);

namespace D4np\Utils\Bench\Fixture;

use D4np\Utils\Dto\DataTransferObject;

/**
 * The flat row shape {@see \D4np\Utils\Bench\GatewayBench} projects — one property per column,
 * because that is what `TableGateway` is for (a nested DTO or a `Collection` belongs behind a
 * `Repository` subclass, not this class, per its own docblock).
 */
final class GatewayRow extends DataTransferObject
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?int $age,
        public readonly ?string $status,
    ) {
    }
}
