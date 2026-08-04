<?php

declare(strict_types=1);

namespace D4np\Utils\Dto;

use Attribute;

/**
 * Declares the element type of a `Collection` constructor parameter, so hydration can build it
 * (spec FR-01/FR-03, ADR-0010).
 *
 * ```php
 * final class OrderDto extends DataTransferObject
 * {
 *     /** @param Collection<LineDto> $lines *\/
 *     public function __construct(
 *         #[CollectionOf(LineDto::class)]
 *         public readonly Collection $lines,
 *     ) {}
 * }
 * ```
 *
 * **Why an attribute and not the docblock.** PHP has no runtime generics, so `Collection<LineDto>`
 * lives in an annotation — and reading it back gives a *token*, not a class. Resolving that token
 * needs the file's namespace, its `use` statements, and any aliases among them: a docblock
 * reading `Collection<Addr>` may mean `App\Dto\AddressDto` imported under an alias, and nothing
 * short of a real PHP parser can tell. An attribute argument is resolved **by PHP itself** at
 * compile time and arrives as a class-string, which PHPStan also type-checks at the call site.
 *
 * The docblock generic is still worth writing — it is what PHPStan uses, and it is the item's
 * stated `@template` discipline. The two say the same thing to two different readers: the
 * annotation to the static analyser, the attribute to the run time.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class CollectionOf
{
    /**
     * @param class-string $type the class every element of the collection must be
     */
    public function __construct(
        public readonly string $type,
    ) {
    }
}
