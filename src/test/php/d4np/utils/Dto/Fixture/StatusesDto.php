<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

use D4np\Utils\Dto\Collection;
use D4np\Utils\Dto\CollectionOf;
use D4np\Utils\Dto\DataTransferObject;

/** A Collection of backed enums, element type declared by attribute (ADR-0086 §2). */
final class StatusesDto extends DataTransferObject
{
    /** @param Collection<Status> $statuses */
    public function __construct(
        #[CollectionOf(Status::class)]
        public readonly Collection $statuses,
    ) {
    }
}
