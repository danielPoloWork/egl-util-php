<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

use D4np\Utils\Dto\Collection;
use D4np\Utils\Dto\DataTransferObject;

/**
 * A Collection with NO attribute. The element type is genuinely unknown to the hydrator, so
 * elements pass through untouched - which is what a Collection of scalars wants.
 */
final class TagsDto extends DataTransferObject
{
    /** @param Collection<string> $tags */
    public function __construct(
        public readonly Collection $tags,
    ) {
    }
}
