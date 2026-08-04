<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

use D4np\Utils\Dto\DataTransferObject;

/** One level of nesting: the recursive case spec FR-01 names. */
final class CustomerDto extends DataTransferObject
{
    public function __construct(
        public readonly string $name,
        public readonly AddressDto $address,
    ) {
    }
}
