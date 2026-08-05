<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Container\Fixture;

/** An unbound interface, but with a default: the default is the author's stated intent. */
final class OptionalMissingImplementation
{
    public function __construct(public readonly ?Greeter $greeter = null)
    {
    }
}
