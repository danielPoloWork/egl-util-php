<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

use D4np\Utils\Dto\DataTransferObject;

/** Every builtin the type check branches on. */
final class ScalarsDto extends DataTransferObject
{
    /**
     * @param array<mixed> $a the native `array` type carries no element type, which PHPStan at
     *                        max level requires — supplied here so the fixture is as strictly
     *                        typed as the code it exercises
     */
    public function __construct(
        public readonly int $i,
        public readonly float $f,
        public readonly string $s,
        public readonly bool $b,
        public readonly array $a,
    ) {
    }
}
