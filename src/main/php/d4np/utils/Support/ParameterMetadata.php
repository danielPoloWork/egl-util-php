<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

/**
 * What one constructor parameter declares, reflected once and reused (ADR-0006).
 *
 * Every field here exists because a stated requirement needs it — the DTO hydrator (spec FR-01)
 * or the Container's autowiring (spec FR-04). Nothing is recorded speculatively: a metadata
 * object that describes more than its consumers ask for is a maintenance cost with no reader.
 */
final class ParameterMetadata
{
    /**
     * @param string      $name         parameter name — how the hydrator matches a payload key
     * @param string|null $type         the single named type, or `null` when the parameter is
     *                                  untyped **or** declares a union/intersection type; those
     *                                  cases are distinguished by {@see self::$declaredType}
     * @param string|null $declaredType the declared type exactly as written (`int`, `?Foo`,
     *                                  `int|string`, `A&B`), or `null` when untyped. Kept for
     *                                  diagnostics: imported ADR-001 requires the Container to
     *                                  *fail loudly* on what it cannot autowire, and a message
     *                                  naming the type it actually saw is what makes that useful
     * @param bool        $isBuiltin    whether `$type` is a PHP builtin (`int`, `string`, …)
     *                                  rather than a class. The branch both consumers make:
     *                                  the hydrator type-checks a scalar or recurses into a
     *                                  nested DTO; the Container resolves a class or refuses
     * @param bool        $allowsNull   whether `null` satisfies the declaration — one half of
     *                                  RFC-0001 R-4's "neither nullable nor defaulted" rule
     * @param bool        $hasDefault   whether the parameter declares a default — the other half
     *                                  of R-4. Needed separately from {@see self::$default},
     *                                  since a declared default of `null` is indistinguishable
     *                                  from "no default" by value alone
     * @param mixed       $default      the declared default, or `null` when `$hasDefault` is false
     * @param bool        $isVariadic   whether the parameter is variadic. Recorded so the metadata
     *                                  does not silently describe `...$args` as an ordinary
     *                                  parameter — being truthful about what was reflected is the
     *                                  cache's entire job
     * @param list<object> $attributes  the parameter's attribute instances, **uninterpreted**.
     *                                  Deliberately untyped beyond `object`: a consumer group
     *                                  (`Dto`, `Container`) knows what its own attributes mean,
     *                                  and `Support` must not — naming one here would import a
     *                                  group upward and break the layering rule RFC-0001 sets
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $type,
        public readonly ?string $declaredType,
        public readonly bool $isBuiltin,
        public readonly bool $allowsNull,
        public readonly bool $hasDefault,
        public readonly mixed $default,
        public readonly bool $isVariadic,
        public readonly array $attributes = [],
    ) {
    }

    /**
     * The parameter's attribute of type `$class`, or `null` when it carries none.
     *
     * @template A of object
     *
     * @param class-string<A> $class
     *
     * @return A|null
     */
    public function attribute(string $class): ?object
    {
        foreach ($this->attributes as $attribute) {
            if ($attribute instanceof $class) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * Whether the parameter may be omitted from a payload without the result being malformed
     * (RFC-0001 R-4).
     *
     * A nullable or defaulted parameter hydrates to `null` or its default when absent; anything
     * else absent is a `MissingKeyException` — in strict **and** lenient mode alike.
     */
    public function isOptional(): bool
    {
        return $this->allowsNull || $this->hasDefault || $this->isVariadic;
    }

    /**
     * Whether the Container can autowire this parameter without an explicit definition.
     *
     * True only for a single named class type. An untyped parameter offers nothing to resolve;
     * a builtin has no service to look up; a union or intersection type gives no single class to
     * construct — the case imported ADR-001 names explicitly as one the Container refuses rather
     * than guesses at.
     */
    public function isAutowirable(): bool
    {
        return $this->type !== null && !$this->isBuiltin && !$this->isVariadic;
    }
}
