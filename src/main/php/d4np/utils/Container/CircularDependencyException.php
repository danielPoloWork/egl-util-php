<?php

declare(strict_types=1);

namespace D4np\Utils\Container;

/**
 * A dependency cycle was reached while autowiring (imported ADR-001's stated non-goal).
 *
 * Its own class rather than a plain {@see ContainerException} because the container has to *act* on
 * the distinction: an ordinary resolution failure may legitimately fall back to a parameter's
 * default, whereas a cycle is a structural error that a default would paper over. Deciding that by
 * matching on message text would be a decision that breaks the day someone rewords the message.
 */
final class CircularDependencyException extends ContainerException
{
}
