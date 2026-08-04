<?php

declare(strict_types=1);

namespace D4np\Utils\Dto;

use D4np\Utils\Support\HydrationException;
use D4np\Utils\Support\MissingKeyException;
use D4np\Utils\Support\ParameterMetadata;
use D4np\Utils\Support\ReflectionCache;
use D4np\Utils\Support\TypeMismatchException;
use D4np\Utils\Support\UnknownKeyException;
use TypeError;

/**
 * Turns an array into a typed DTO, recursively (spec FR-01, ADR-0008).
 *
 * The engine behind {@see DataTransferObject::fromArray()}. It holds the shared
 * {@see ReflectionCache} — the one imported ADR-001 commits to — so the reflection cost of a
 * DTO class is paid once per process and every later hydration is an array lookup (NFR-01).
 *
 * Depends only on `Support`, which is what the layering rule allows (RFC-0001).
 */
final class Hydrator
{
    public function __construct(
        private readonly ReflectionCache $cache,
    ) {
    }

    /**
     * Hydrate `$class` from `$data`.
     *
     * @template T of object
     *
     * @param class-string<T>     $class
     * @param array<string, mixed> $data
     * @param bool                $lenient whether unknown keys are ignored rather than rejected
     *
     * @return T
     *
     * @throws HydrationException
     */
    public function hydrate(string $class, array $data, bool $lenient = false): object
    {
        return $this->hydrateAt($class, $data, $lenient, '');
    }

    /**
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $data
     *
     * @return T
     *
     * @throws HydrationException
     */
    private function hydrateAt(string $class, array $data, bool $lenient, string $prefix): object
    {
        $meta = $this->cache->for($class);

        if (!$meta->isInstantiable) {
            throw new HydrationException(
                sprintf('Cannot hydrate %s: the class is not instantiable.', $class),
                $prefix,
            );
        }

        if (!$lenient) {
            $this->rejectUnknownKeys($meta->parameterNames(), $data, $class, $prefix);
        }

        $arguments = [];
        foreach ($meta->parameters as $parameter) {
            $path = self::join($prefix, $parameter->name);

            if ($parameter->isVariadic) {
                // A variadic parameter consumes an arbitrary number of positional arguments,
                // which a keyed payload has no way to express. Refused rather than guessed at.
                if (array_key_exists($parameter->name, $data)) {
                    throw new HydrationException(sprintf(
                        'Cannot hydrate %s: parameter "%s" is variadic, which a keyed payload '
                        . 'cannot express.',
                        $class,
                        $parameter->name,
                    ), $path);
                }

                continue;
            }

            if (!array_key_exists($parameter->name, $data)) {
                // RFC-0001 R-4, and the order matters. A declared default is applied by PHP
                // itself when the argument is omitted, so the cleanest thing is to pass nothing.
                // A nullable parameter WITHOUT a default is a different case: PHP treats it as
                // required (verified — omitting one raises ArgumentCountError), so `null` has to
                // be passed explicitly for R-4's "hydrates to null" to hold.
                if ($parameter->hasDefault) {
                    continue;
                }
                if ($parameter->allowsNull) {
                    $arguments[$parameter->name] = null;

                    continue;
                }

                throw MissingKeyException::forKey($path, $class);
            }

            $arguments[$parameter->name] = $this->coerce($data[$parameter->name], $parameter, $lenient, $path);
        }

        return $this->construct($class, $arguments, $prefix);
    }

    /**
     * @param list<string>         $declared
     * @param array<string, mixed> $data
     * @param class-string         $class
     *
     * @throws UnknownKeyException
     */
    private function rejectUnknownKeys(array $declared, array $data, string $class, string $prefix): void
    {
        foreach (array_keys($data) as $key) {
            $name = (string) $key;
            if (!in_array($name, $declared, true)) {
                throw UnknownKeyException::forKey(self::join($prefix, $name), $class);
            }
        }
    }

    /**
     * @throws HydrationException
     */
    private function coerce(mixed $value, ParameterMetadata $parameter, bool $lenient, string $path): mixed
    {
        $type = $parameter->type;

        // No single named type to check against: an untyped parameter, `mixed`, or a
        // union/intersection the metadata deliberately does not reduce to one arm (ADR-0006).
        // Whatever arrives is passed through, and PHP's own check is the backstop at
        // construction — where it is converted into a library exception with this path.
        if ($type === null || $type === 'mixed') {
            return $value;
        }

        if ($value === null) {
            if ($parameter->allowsNull) {
                return null;
            }

            throw TypeMismatchException::at($path, $parameter->declaredType ?? $type, 'null');
        }

        if (!$parameter->isBuiltin) {
            // A non-builtin type name is only a class-string if it actually resolves. The
            // reflection cache proved the *declaring* class loadable, not the types of its
            // parameters — one naming a class that was never autoloadable reaches here.
            //
            // NOT covered by the suite, deliberately: reaching it needs a DTO whose parameter
            // type does not exist, and PHPStan at max level rejects exactly that (`class.notFound`),
            // so the state cannot occur in this codebase. The check stays because it is what
            // narrows `string` to `class-string` for the type system, and because a consumer who
            // does not run PHPStan can still reach it. A fixture to cover it was written, seen
            // rejected by the linter, and removed rather than suppressed.
            if (!class_exists($type) && !interface_exists($type)) {
                throw new HydrationException(sprintf(
                    'Cannot hydrate: parameter type "%s" does not resolve to a loadable class '
                    . 'or interface.',
                    $type,
                ), $path);
            }

            return $this->coerceObject($value, $type, $lenient, $path);
        }

        if (!self::satisfiesBuiltin($value, $type)) {
            throw TypeMismatchException::at($path, $type, get_debug_type($value));
        }

        return $value;
    }

    /**
     * @param class-string $type
     *
     * @throws HydrationException
     */
    private function coerceObject(mixed $value, string $type, bool $lenient, string $path): mixed
    {
        // Already the right object: pass it through. A caller assembling a graph by hand should
        // not have to take it apart into arrays first.
        if ($value instanceof $type) {
            return $value;
        }

        // A nested DTO is the recursive case spec FR-01 names: an array becomes the child DTO,
        // and the path grows so a failure deep in the graph says where it happened.
        if (is_a($type, DataTransferObject::class, true) && is_array($value)) {
            /** @var array<string, mixed> $value */
            return $this->hydrateAt($type, $value, $lenient, $path);
        }

        throw TypeMismatchException::at($path, $type, get_debug_type($value));
    }

    /**
     * Whether `$value` satisfies the builtin type `$type`.
     *
     * `int` where `float` is declared is accepted deliberately: PHP performs that widening even
     * under `strict_types=1` (verified), so refusing it here would be stricter than the language
     * and would reject a payload the constructor would have taken.
     */
    private static function satisfiesBuiltin(mixed $value, string $type): bool
    {
        return match ($type) {
            'int' => is_int($value),
            'float' => is_float($value) || is_int($value),
            'string' => is_string($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            'iterable' => is_iterable($value),
            'object' => is_object($value),
            // Everything else falls through deliberately, rather than growing an arm per type.
            //
            // `callable` cannot be a property type at all, and the standalone `null`, `true` and
            // `false` types only exist from PHP 8.2 — below this project's 8.1 floor, so arms for
            // them would be unreachable on the minimum supported version and merely *look* like
            // coverage on 8.3. Passing through is correct rather than lax: PHP's own check runs
            // at construction, and construct() converts the resulting TypeError into a library
            // exception carrying the path (ADR-0004's contract), so a genuine mismatch is still
            // reported — with PHP's wording instead of this class's.
            default => true,
        };
    }

    /**
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $arguments
     *
     * @return T
     *
     * @throws HydrationException
     */
    private function construct(string $class, array $arguments, string $prefix): object
    {
        try {
            // Named-argument spread: an omitted key simply is not passed, so PHP applies the
            // parameter's declared default rather than this class having to replicate it.
            return new $class(...$arguments);
        } catch (TypeError $e) {
            // The backstop for anything the checks above let through — an exotic builtin, an
            // intersection type, a union arm. Without this a consumer catching UtilsThrowable
            // would miss a bare TypeError, breaking ADR-0004's "one thing to catch" contract.
            throw new HydrationException(
                sprintf('Cannot hydrate %s: %s', $class, $e->getMessage()),
                $prefix,
                $e,
            );
        }
    }

    private static function join(string $prefix, string $name): string
    {
        return $prefix === '' ? $name : $prefix . '.' . $name;
    }
}
