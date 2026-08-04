<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

use D4np\Utils\Dto\DataTransferObject;
use D4np\Utils\Dto\WithersTrait;

/** The documented shape: promoted public readonly properties. */
final class WitherUserDto extends DataTransferObject
{
    use WithersTrait;

    public function __construct(
        public readonly string $email,
        public readonly string $name,
        public readonly int $age = 30,
    ) {
    }
}
