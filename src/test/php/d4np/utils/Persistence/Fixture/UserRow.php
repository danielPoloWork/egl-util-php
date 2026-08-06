<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Persistence\Fixture;

use D4np\Utils\Dto\DataTransferObject;

/**
 * The shape `RepositoryTest`'s queries project, column for column.
 *
 * Every property is declared because hydration is strict (ADR-0008): a `SELECT *` that returned
 * a column absent from here would raise, which is the behaviour item 10.3 keeps rather than
 * works around.
 */
final class UserRow extends DataTransferObject
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?int $age,
    ) {
    }
}
