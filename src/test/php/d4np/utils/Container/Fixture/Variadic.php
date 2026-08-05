<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Container\Fixture;

/** A variadic tail is legitimately empty; there is nothing for the container to enumerate. */
final class Variadic
{
    /** @var list<NoDependencies> */
    public readonly array $items;

    public function __construct(NoDependencies ...$items)
    {
        $this->items = array_values($items);
    }
}
