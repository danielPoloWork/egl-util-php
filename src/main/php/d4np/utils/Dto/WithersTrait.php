<?php

declare(strict_types=1);

namespace D4np\Utils\Dto;

use D4np\Utils\Support\HydrationException;

/**
 * `with(...)` semantics for immutable DTOs (spec FR-02, ADR-0009).
 *
 * ```php
 * final class UserDto extends DataTransferObject
 * {
 *     use WithersTrait;
 *
 *     public function __construct(
 *         public readonly string $email,
 *         public readonly string $name,
 *     ) {}
 * }
 *
 * $renamed = $user->with(name: 'Grace');   // a new UserDto; $user is untouched
 * ```
 *
 * **Why a rebuild rather than a clone.** A `readonly` property cannot be reassigned after
 * `clone`, and PHP 8.3's readonly amendment only relaxes that *inside* `__clone()` — still an
 * error on 8.1 and 8.2, which this project supports. Rebuilding through the constructor works
 * identically on all three, so the trait carries no version branch at all: the "absorb the
 * 8.1→8.3 difference" requirement is met by not depending on the difference (ADR-0009).
 *
 * It is also the stronger semantics on its own merits — the constructor runs, so a DTO that
 * validates there validates the wither's result too. A clone-based wither would bypass it and
 * could produce an object the class itself would have refused to build.
 *
 * The abstract declaration below is the requirement, enforced by PHP rather than by a comment:
 * a class using this trait must supply a {@see Hydrator}, which {@see DataTransferObject}
 * already does. Using the trait anywhere else is a fatal error at compile time rather than a
 * surprise at the first `with()` call.
 */
trait WithersTrait
{
    abstract protected static function hydrator(): Hydrator;

    /**
     * A copy of this object with the named properties replaced.
     *
     * Takes named arguments — `->with(name: 'Grace', email: 'g@example.com')` — so the call site
     * names what it is changing. Everything not named keeps its current value.
     *
     * The result goes through the same checks as hydration: a name the class does not declare
     * raises `UnknownKeyException`, and a value the declaration cannot accept raises
     * `TypeMismatchException`. `with()` is not a way around the type system.
     *
     * @param mixed ...$changes named arguments only; a positional one has no property to bind to
     *
     * @throws HydrationException
     */
    public function with(mixed ...$changes): static
    {
        /** @var array<string, mixed> $changes */
        return static::hydrator()->withChanges($this, $changes);
    }
}
