<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto\Fixture;

use D4np\Utils\Dto\DataTransferObject;

/** Wraps a TicketDto so a nested export refusal has a prefix to carry in its path. */
final class TicketHolderDto extends DataTransferObject
{
    public function __construct(
        public readonly string $label,
        public readonly TicketDto $ticket,
    ) {
    }
}
