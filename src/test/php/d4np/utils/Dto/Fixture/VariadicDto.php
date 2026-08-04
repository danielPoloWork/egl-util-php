<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

use D4np\Utils\Dto\DataTransferObject;

/** A variadic constructor: refused rather than guessed at. */
final class VariadicDto extends DataTransferObject
{
    /** @var list<string> */
    public readonly array $rest;

    public function __construct(string ...$rest)
    {
        $this->rest = array_values($rest);
    }
}
