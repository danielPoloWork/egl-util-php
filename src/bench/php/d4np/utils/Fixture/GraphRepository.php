<?php

declare(strict_types=1);

namespace D4np\Utils\Bench\Fixture;

/** One node of NFR-02's benchmark graph: a small, realistic service chain. */
final class GraphRepository
{
    public function __construct(public readonly GraphLeaf $leaf)
    {
    }
}
