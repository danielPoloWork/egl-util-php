<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

use D4np\Utils\Dto\Collection;
use D4np\Utils\Dto\CollectionOf;
use D4np\Utils\Dto\DataTransferObject;

/** A Collection of nested DTOs, element type declared by attribute. */
final class BasketDto extends DataTransferObject
{
    /** @param Collection<AddressDto> $stops */
    public function __construct(
        public readonly string $label,
        #[CollectionOf(AddressDto::class)]
        public readonly Collection $stops,
    ) {
    }
}
