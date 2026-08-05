<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Container\Fixture;

/**
 * A constructor parameter with **no type declaration at all**.
 *
 * Neither promoted nor `mixed`, and both exclusions are the point. A promoted property must carry a
 * type, and `mixed` is a *named built-in* type — verified: it arrives as `isBuiltin` with the name
 * `"mixed"`, so it exercises the built-in branch rather than this one. The first version of this
 * fixture used `mixed` and silently tested the wrong refusal.
 */
final class UntypedParameter
{
    public readonly mixed $anything;

    /** @param mixed $anything */
    public function __construct($anything)
    {
        $this->anything = $anything;
    }
}
