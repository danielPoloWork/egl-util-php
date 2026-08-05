<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Container\Fixture;

/** Interface-typed: unresolvable without a bind(), because nothing says which implementation. */
final class NeedsAGreeter
{
    public function __construct(public readonly Greeter $greeter)
    {
    }
}
