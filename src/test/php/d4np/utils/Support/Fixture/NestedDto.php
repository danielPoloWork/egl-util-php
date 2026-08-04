<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support\Fixture;

/** A class-typed parameter: what the Container autowires and the hydrator recurses into. */
final class NestedDto
{
    public function __construct(
        public readonly ScalarDto $inner,
    ) {
    }
}
