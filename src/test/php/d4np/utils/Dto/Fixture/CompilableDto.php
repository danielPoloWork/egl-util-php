<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

use D4np\Utils\Dto\DataTransferObject;

/**
 * Eligible for the compiled fast path (ADR-0013): every parameter a builtin scalar with no
 * declared default. The nullable one is deliberate — nullable-without-default is eligible
 * (RFC-0001 R-4 always passes it, so its argument position never shifts), and it is the case most
 * likely to diverge between the two paths, so parity has something real to check.
 */
final class CompilableDto extends DataTransferObject
{
    public function __construct(
        public readonly string $name,
        public readonly int $count,
        public readonly float $ratio,
        public readonly bool $active,
        public readonly ?string $note,
    ) {
    }
}
