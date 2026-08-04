<?php

declare(strict_types=1);

namespace D4np\Utils\Bench\Fixture;

use D4np\Utils\Dto\DataTransferObject;

/**
 * Exactly the shape spec NFR-01 and NFR-04 name: "10 scalar props". Used by both benchmarks so
 * a reader can see the two are measuring the same thing.
 */
final class TenScalarPropsDto extends DataTransferObject
{
    public function __construct(
        public readonly string $a,
        public readonly string $b,
        public readonly string $c,
        public readonly int $d,
        public readonly int $e,
        public readonly int $f,
        public readonly float $g,
        public readonly float $h,
        public readonly bool $i,
        public readonly bool $j,
    ) {
    }

    /**
     * The payload {@see self::fromArray()} and the manual-construction benchmark both start
     * from — one source, so a change to the shape cannot make the two benchmarks quietly
     * measure different things.
     *
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        return [
            'a' => 'alpha', 'b' => 'beta', 'c' => 'gamma',
            'd' => 1, 'e' => 2, 'f' => 3,
            'g' => 1.5, 'h' => 2.5,
            'i' => true, 'j' => false,
        ];
    }
}
