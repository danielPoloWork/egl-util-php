<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

use D4np\Utils\Dto\DataTransferObject;
use D4np\Utils\Dto\WithersTrait;

/** Withers over a nested DTO: the child object is carried across unchanged. */
final class WitherNestedDto extends DataTransferObject
{
    use WithersTrait;

    public function __construct(
        public readonly string $name,
        public readonly AddressDto $address,
    ) {
    }
}
