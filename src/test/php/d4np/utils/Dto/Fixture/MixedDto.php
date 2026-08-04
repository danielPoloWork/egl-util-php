<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

use D4np\Utils\Dto\DataTransferObject;

/** A `mixed` parameter: anything passes, including null. */
final class MixedDto extends DataTransferObject
{
    public function __construct(
        public readonly mixed $anything,
    ) {
    }
}
