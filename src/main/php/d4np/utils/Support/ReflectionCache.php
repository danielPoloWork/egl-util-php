<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

/**
 * Per-class constructor metadata, reflected once per process (spec FR-01/FR-04, ADR-0006).
 *
 * The one cache imported ADR-001 commits to: *"the reflection cache used by the container is
 * shared infrastructure with the DTO hydrator (one metadata cache)"*. It lives in `Support`
 * because that is the only layer both the `Dto` and `Container` groups may depend on without
 * breaking the no-cross-group-imports rule (RFC-0001 R-2).
 *
 * It is what makes NFR-01 (≤ 5 µs/DTO warm) and NFR-02 (≤ 2 µs warm singleton resolve)
 * reachable: reflection is paid once per class, and every later lookup is an array hit.
 *
 * **Scope, stated plainly:** this is in-process memoisation, not a cache *backend*. PHP is
 * request-isolated, so the lifetime is one process — there is no persistence, no eviction, and
 * no TTL, because a class's constructor cannot change while the process runs.
 */
final class ReflectionCache
{
    /** @var array<class-string, ClassMetadata> */
    private array $entries = [];

    /**
     * The metadata for `$class`, reflecting it on first request and returning the **same**
     * instance on every later one.
     *
     * @param class-string $class
     *
     * @throws UtilsException if the class cannot be reflected — typically because it does not
     *                        exist. The native `ReflectionException` is wrapped rather than
     *                        allowed to escape, so a consumer catching {@see UtilsThrowable}
     *                        catches this too (ADR-0004's contract).
     */
    public function for(string $class): ClassMetadata
    {
        return $this->entries[$class] ??= $this->reflect($class);
    }

    /**
     * Whether `$class` has already been reflected.
     *
     * Exists so the memoisation is observable from outside — a cache whose only evidence is a
     * timing difference is a cache nobody can write a deterministic test against.
     *
     * @param class-string $class
     */
    public function isCached(string $class): bool
    {
        return isset($this->entries[$class]);
    }

    /** How many classes have been reflected so far. */
    public function count(): int
    {
        return \count($this->entries);
    }

    /**
     * @param class-string $class
     *
     * @throws UtilsException
     */
    private function reflect(string $class): ClassMetadata
    {
        // An explicit precondition rather than a `catch (ReflectionException)`: PHPStan proves
        // that catch dead, because `class-string` asserts the name resolves — and at runtime it
        // may not, since nothing stops a caller passing a name that came from configuration or
        // user input. Checking up front keeps both truths intact and produces a better message
        // than reflection's own.
        //
        // The three checks are not redundant: class_exists() covers classes, abstract classes
        // and enums, but returns false for interfaces and traits — verified directly. All are
        // reflectable, and the Container in particular must be able to reflect an interface in
        // order to refuse it as non-instantiable.
        if (!\class_exists($class) && !\interface_exists($class) && !\trait_exists($class)) {
            throw new UtilsException(\sprintf(
                'Cannot reflect "%s": no such class, interface, enum or trait is loadable.',
                $class,
            ));
        }

        $reflection = new ReflectionClass($class);

        $constructor = $reflection->getConstructor();
        $parameters = [];
        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                $parameters[] = self::describe($parameter);
            }
        }

        return new ClassMetadata($class, $parameters, $reflection->isInstantiable());
    }

    private static function describe(ReflectionParameter $parameter): ParameterMetadata
    {
        $type = $parameter->getType();

        // Only a single named type gives the consumers something to act on. A union or
        // intersection type is deliberately reduced to `null` here and preserved verbatim in
        // $declaredType: imported ADR-001 has the Container refuse those rather than pick one
        // arm, and the refusal message needs to name what it saw.
        $named = $type instanceof ReflectionNamedType ? $type : null;

        return new ParameterMetadata(
            name: $parameter->getName(),
            type: $named?->getName(),
            declaredType: self::declaredTypeOf($type),
            isBuiltin: $named?->isBuiltin() ?? false,
            allowsNull: $type === null || $type->allowsNull(),
            hasDefault: $parameter->isDefaultValueAvailable(),
            default: self::defaultOf($parameter),
            isVariadic: $parameter->isVariadic(),
            attributes: self::attributesOf($parameter),
        );
    }

    /**
     * The parameter's attribute instances, cached with the rest of the metadata.
     *
     * Instantiated here rather than handed back as `ReflectionAttribute`s so the cost is paid
     * once per class like everything else, and stored as plain `object`s so this layer never
     * learns what any of them mean — that belongs to the group that declared them.
     *
     * @return list<object>
     */
    private static function attributesOf(ReflectionParameter $parameter): array
    {
        $instances = [];
        foreach ($parameter->getAttributes() as $attribute) {
            // A failure here is a broken attribute class, not a condition to paper over: it
            // surfaces as the UtilsException `for()` already documents rather than being
            // swallowed into a silently attribute-less parameter.
            $instances[] = $attribute->newInstance();
        }

        return $instances;
    }

    private static function declaredTypeOf(?\ReflectionType $type): ?string
    {
        if ($type === null) {
            return null;
        }

        // An intersection type's __toString() is well defined, but building the string from its
        // parts keeps this independent of that (documented-but-easy-to-change) behaviour.
        if ($type instanceof ReflectionIntersectionType) {
            return \implode('&', \array_map(
                static fn (\ReflectionType $part): string => (string) $part,
                $type->getTypes(),
            ));
        }

        return (string) $type;
    }

    /**
     * The declared default, or `null` when there is none.
     *
     * A default can be a constant expression that fails to evaluate — `self::SOME_CONST` on a
     * class whose constant was removed, for instance. Reading it must not turn metadata
     * collection into a hard failure, so an unreadable default is recorded as absent rather than
     * thrown: {@see ParameterMetadata::$hasDefault} still says `true`, and the consumer that
     * genuinely needs the value fails on its own terms with its own context.
     */
    private static function defaultOf(ReflectionParameter $parameter): mixed
    {
        if (!$parameter->isDefaultValueAvailable()) {
            return null;
        }

        try {
            return $parameter->getDefaultValue();
        } catch (Throwable) {
            return null;
        }
    }
}
