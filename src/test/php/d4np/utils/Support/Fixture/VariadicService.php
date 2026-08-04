<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support\Fixture;

/** A variadic constructor: must not be described as an ordinary parameter. */
final class VariadicService
{
    /** @var list<string> */
    public readonly array $rest;

    public function __construct(string ...$rest)
    {
        $this->rest = array_values($rest);
    }
}
