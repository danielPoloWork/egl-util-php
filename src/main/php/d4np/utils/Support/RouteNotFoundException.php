<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

/**
 * No route is registered for the requested path (spec r3 FR-38, RFC-0002; ADR-0050).
 *
 * The `404` half of FR-38's pair. Distinct from {@see MethodNotAllowedException}, and the
 * distinction is the requirement: a path nobody registered and a path registered for a
 * different method are different answers, and collapsing them into one `404` is what stops a
 * caller from ever learning it used the wrong verb.
 */
final class RouteNotFoundException extends HttpException
{
}
