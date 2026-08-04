<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

/**
 * What one class's constructor declares, reflected once and reused (ADR-0006).
 *
 * The unit {@see ReflectionCache} memoises. Both consumers — the DTO hydrator (spec FR-01) and
 * the Container's autowiring (spec FR-04) — need exactly this: can it be constructed, and with
 * what parameters.
 */
final class ClassMetadata
{
    /**
     * @param class-string             $className      the class this describes
     * @param list<ParameterMetadata>  $parameters     constructor parameters, in declaration
     *                                                 order — empty for a class with no
     *                                                 constructor, which is a legitimate case
     *                                                 both consumers handle (construct with no
     *                                                 arguments), not an error
     * @param bool                     $isInstantiable whether `new` is possible at all. False for
     *                                                 an interface, an abstract class, or a class
     *                                                 whose constructor is not public — the last
     *                                                 being this library's own static-utility
     *                                                 idiom, so the Container must refuse it
     *                                                 clearly rather than fail inside `new`
     */
    public function __construct(
        public readonly string $className,
        public readonly array $parameters,
        public readonly bool $isInstantiable,
    ) {
    }

    /**
     * The parameter with this name, or `null` when the class declares none.
     *
     * How the hydrator answers "is this payload key a declared property?" — the question behind
     * `UnknownKeyException` in strict mode (spec FR-01).
     */
    public function parameter(string $name): ?ParameterMetadata
    {
        foreach ($this->parameters as $parameter) {
            if ($parameter->name === $name) {
                return $parameter;
            }
        }

        return null;
    }

    /**
     * Parameter names, in declaration order.
     *
     * @return list<string>
     */
    public function parameterNames(): array
    {
        return array_map(
            static fn (ParameterMetadata $parameter): string => $parameter->name,
            $this->parameters,
        );
    }
}
