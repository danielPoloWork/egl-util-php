<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

use D4np\Utils\Dto\DataTransferObject;

/** The spec §6 example, verbatim: the shape every other fixture varies from. */
final class UserDto extends DataTransferObject
{
    public function __construct(
        public readonly string $email,
        public readonly string $name,
    ) {
    }
}
