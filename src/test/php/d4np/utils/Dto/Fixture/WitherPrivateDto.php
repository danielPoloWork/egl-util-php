<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

use D4np\Utils\Dto\DataTransferObject;
use D4np\Utils\Dto\WithersTrait;

/**
 * A promoted PRIVATE readonly property. The wither must still read it back, which is why the
 * current value is read through reflection rather than a property access from another scope.
 */
final class WitherPrivateDto extends DataTransferObject
{
    use WithersTrait;

    public function __construct(
        private readonly string $secret,
        public readonly string $label,
    ) {
    }

    public function secret(): string
    {
        return $this->secret;
    }
}
