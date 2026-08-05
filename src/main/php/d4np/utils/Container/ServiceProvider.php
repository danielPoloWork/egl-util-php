<?php

declare(strict_types=1);

namespace D4np\Utils\Container;

/**
 * A group of related container registrations (spec FR-05, imported ADR-001).
 *
 * The whole contract is one method. A provider says what a slice of the application offers, and
 * {@see Container::register()} applies it — so wiring lives next to the thing being wired instead
 * of accumulating in one bootstrap file that every feature has to touch.
 *
 * **There is deliberately no `boot()` phase.** A two-phase lifecycle exists in frameworks to order
 * side effects across providers, and side-effect ordering is application composition, not a utility
 * library's job — the same line imported ADR-001 draws at compilation and lazy proxies. A provider
 * here registers definitions and nothing else; anything needing to *run* after wiring is a call the
 * application makes, in an order it can see.
 */
abstract class ServiceProvider
{
    abstract public function register(Container $container): void;
}
