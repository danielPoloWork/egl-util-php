<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

use D4np\Utils\Dto\DataTransferObject;

/** The enum case spec §7's T-01 matrix names, plus a pure-enum property alongside it. */
final class TicketDto extends DataTransferObject
{
    public function __construct(
        public readonly string $title,
        public readonly Status $status,
        public readonly Priority $priority = Priority::Low,
        public readonly ?Direction $direction = null,
    ) {
    }
}
