<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support\Fixture;

/**
 * A `mixed`-typed parameter.
 *
 * Reflection reports `mixed` as a {@see \ReflectionNamedType} that is builtin and nullable —
 * so unlike {@see UntypedService} it *has* a type, and the metadata must say so rather than
 * flattening both cases into "no type".
 */
final class MixedTypeService
{
    public function __construct(
        public readonly mixed $anything,
    ) {
    }
}
