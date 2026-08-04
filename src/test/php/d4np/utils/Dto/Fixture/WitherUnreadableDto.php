<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

use D4np\Utils\Dto\DataTransferObject;
use D4np\Utils\Dto\WithersTrait;

/**
 * A constructor parameter that is NOT promoted and has no property of the same name, so its
 * current value cannot be read back. The wither must say so plainly.
 */
final class WitherUnreadableDto extends DataTransferObject
{
    use WithersTrait;

    public readonly string $stored;

    public function __construct(string $incoming)
    {
        $this->stored = strtoupper($incoming);
    }
}
