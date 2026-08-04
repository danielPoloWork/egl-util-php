<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

use D4np\Utils\Dto\DataTransferObject;

/** The two remaining declarable builtins the type check branches on. */
final class IterableAndObjectDto extends DataTransferObject
{
    /** @param iterable<mixed> $items */
    public function __construct(
        public readonly iterable $items,
        public readonly object $thing,
    ) {
    }
}
