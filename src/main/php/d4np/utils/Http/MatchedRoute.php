<?php

declare(strict_types=1);

namespace D4np\Utils\Http;

/**
 * A route that matched, with the values its placeholders captured (spec r3 FR-38; ADR-0050).
 *
 * **Every parameter is a `string`.** The router does not coerce `{id}` to an `int`, for the
 * same reason {@see Request}'s typed readers refuse rather than coerce (ADR-0025): the path
 * segment is client-chosen text, and a router that hands back an `int` has decided that
 * `/orders/00042` and `/orders/42` are the same order without being asked. Validation belongs
 * to the handler, which is the only code that knows what the identifier means.
 */
final class MatchedRoute
{
    /**
     * @param callable             $handler    what the route registered
     * @param array<string, string> $parameters placeholder name => captured segment, percent-decoded
     */
    public function __construct(
        public readonly mixed $handler,
        public readonly array $parameters = [],
    ) {
    }

    /**
     * One captured parameter, or `null` when the route has no placeholder by that name.
     */
    public function parameter(string $name): ?string
    {
        return $this->parameters[$name] ?? null;
    }
}
