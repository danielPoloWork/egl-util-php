<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Container\Fixture;

final class EnglishGreeter implements Greeter
{
    public function greet(): string
    {
        return 'hello';
    }
}
