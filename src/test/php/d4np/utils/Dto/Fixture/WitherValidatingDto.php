<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

use D4np\Utils\Dto\DataTransferObject;
use D4np\Utils\Dto\WithersTrait;

/**
 * A DTO that validates in its constructor. The whole point of rebuilding rather than cloning:
 * a clone-based wither would bypass this and could produce an object the class refuses to build.
 */
final class WitherValidatingDto extends DataTransferObject
{
    use WithersTrait;

    public function __construct(
        public readonly string $email,
    ) {
        if (!str_contains($email, '@')) {
            throw new \InvalidArgumentException('email must contain @');
        }
    }
}
