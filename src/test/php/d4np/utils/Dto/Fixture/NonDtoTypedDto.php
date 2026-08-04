<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

use D4np\Utils\Dto\DataTransferObject;

/** A class-typed parameter that is NOT a DTO — passed through, never built from an array. */
final class NonDtoTypedDto extends DataTransferObject
{
    public function __construct(
        public readonly \DateTimeImmutable $at,
    ) {
    }
}
