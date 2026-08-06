<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Persistence\Fixture;

use D4np\Utils\Dto\DataTransferObject;

/**
 * A DTO that declares a property the fixture table has no column for.
 *
 * Projecting the DTO means a name the table lacks becomes a driver error on the first read, and
 * `TableGatewayTest` asserts that rather than leaving it as a claim in a docblock. It is the
 * stated cost of the projection: this class is for flat row shapes, and a DTO that is not one
 * belongs behind a `Repository` subclass with a query it owns.
 */
final class Mismatched extends DataTransferObject
{
    public function __construct(
        public readonly int $id,
        public readonly string $nickname,
    ) {
    }
}
