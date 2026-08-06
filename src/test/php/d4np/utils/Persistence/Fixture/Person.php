<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Persistence\Fixture;

use D4np\Utils\Dto\DataTransferObject;

/**
 * The row shape `TableGatewayTest`'s table is read through.
 *
 * It deliberately declares **fewer** columns than the table has: the fixture table also carries
 * a `secret` column, so a gateway that issued `SELECT *` would fail strict hydration on every
 * read. That is the difference the projection makes, and this omission is what tests it.
 */
final class Person extends DataTransferObject
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?int $age,
        public readonly ?string $status,
    ) {
    }
}
