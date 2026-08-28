<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

use D4np\Utils\Dto\Collection;
use D4np\Utils\Dto\CollectionOf;
use D4np\Utils\Dto\DataTransferObject;

/**
 * A Collection whose attribute names a PURE enum: hydratable from instances, but a declared
 * enum position with no data form — export refuses it per element (ADR-0086 §3).
 */
final class DirectionsDto extends DataTransferObject
{
    /** @param Collection<Direction> $directions */
    public function __construct(
        #[CollectionOf(Direction::class)]
        public readonly Collection $directions,
    ) {
    }
}
