<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

use D4np\Utils\Dto\DataTransferObject;

/** Two levels, so a failure path has something to compose: order.customer.address.postcode. */
final class OrderDto extends DataTransferObject
{
    public function __construct(
        public readonly string $reference,
        public readonly CustomerDto $customer,
    ) {
    }
}
