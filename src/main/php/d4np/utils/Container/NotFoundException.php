<?php

declare(strict_types=1);

namespace D4np\Utils\Container;

use Psr\Container\NotFoundExceptionInterface;

/**
 * No entry exists for the requested identifier (PSR-11's `NotFoundExceptionInterface`).
 *
 * Kept distinct from its parent because PSR-11 draws exactly this line: *"no entry was found"* is a
 * different answer from *"the entry exists and could not be built"*. A caller probing for an
 * optional service wants to distinguish the two, and collapsing them would make a misconfigured
 * dependency look identical to an absent one.
 */
final class NotFoundException extends ContainerException implements NotFoundExceptionInterface
{
}
