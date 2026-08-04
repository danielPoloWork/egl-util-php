<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

use D4np\Utils\Dto\DataTransferObject;

/** The inner DTO of the nesting fixtures. */
final class AddressDto extends DataTransferObject
{
    public function __construct(
        public readonly string $street,
        public readonly string $postcode,
    ) {
    }
}
