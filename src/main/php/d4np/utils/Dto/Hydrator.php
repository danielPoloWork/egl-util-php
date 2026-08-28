<?php

declare(strict_types=1);

namespace D4np\Utils\Dto;

use BackedEnum;
use Closure;
use D4np\Utils\Support\HydrationException;
use D4np\Utils\Support\MissingKeyException;
use D4np\Utils\Support\ParameterMetadata;
use D4np\Utils\Support\ReflectionCache;
use D4np\Utils\Support\TypeMismatchException;
use D4np\Utils\Support\UnknownKeyException;
use ReflectionProperty;
use TypeError;
use UnitEnum;

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
    /**
     * Per-class compiled closures, or `false` for a class the compiler declined (ADR-0013).
     *
     * `false` rather than simply "absent" so an ineligible class is asked about once and then
     * answered from this array — otherwise every hydration of, say, a nested-DTO class would
     * re-run the eligibility walk to reach the same "no" it reached last time.
     *
     * @var array<class-string, Closure(array<string, mixed>, string, bool): object|false>
     */
    private array $compiled = [];

    private readonly HydrationCompiler $compiler;

    public function __construct(
        private readonly ReflectionCache $cache,
        ?HydrationCompiler $compiler = null,
    ) {
        $this->compiler = $compiler ?? new HydrationCompiler();
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
     * A copy of `$source` with `$changes` applied — the engine behind {@see WithersTrait::with()}.
     *
     * Rebuilds through the constructor rather than cloning, which is what makes withers work on
     * the declared PHP 8.1 floor at all: a `readonly` property cannot be reassigned after
     * `clone`, and PHP 8.3's readonly amendment only relaxes that *inside* `__clone()` — still
     * an error on 8.1 and 8.2. Rebuilding needs no version branch and behaves identically on all
     * three (ADR-0009).
     *
     * Rebuilding is also the stronger semantics, independently of compatibility: the constructor
     * runs, so any validation a DTO performs there applies to the result. A clone-based wither
     * bypasses the constructor entirely and can produce an object the class would have refused
     * to construct.
     *
     * @template T of object
     *
     * @param T                    $source
     * @param array<string, mixed> $changes
     *
     * @return T
     *
     * @throws HydrationException
     */
    public function withChanges(object $source, array $changes): object
    {
        $current = $this->readBack(
            $source,
            'apply withers to',
            'Withers rebuild through the constructor',
        );

        /** @var T */
        return $this->hydrateAt($source::class, \array_merge($current, $changes), false, '');
    }

    /**
     * The plain-array form of `$dto` — the inverse of {@see self::hydrate()} (spec FR-51,
     * ADR-0086). The engine behind {@see DataTransferObject::toArray()}.
     *
     * Conversion is driven by each constructor parameter's **declaration**, mirroring the
     * hydration branches exactly, which is what makes `fromArray(extract($x)) == $x` hold by
     * construction rather than by test coverage: nested DTOs export recursively, backed enums as
     * their backing value, `#[CollectionOf]`-typed collections as lists (DTO elements to arrays,
     * backed-enum elements to values), and everything hydration passes through untouched —
     * builtins, `mixed`, unions, plain arrays, attribute-less collection elements, and instances
     * of classes outside this library's conversion vocabulary (`DateTimeImmutable`, a consumer's
     * value object) — comes back exactly as it is. The output is plain data exactly as far as the
     * declarations are plain; opaque values stay opaque rather than being guessed at.
     *
     * Export takes no options, deliberately: two callers exporting the same object must get
     * the same array, or the round-trip property becomes a property of a configuration.
     *
     * @return array<string, mixed>
     *
     * @throws HydrationException at any position DECLARED as a pure (non-backed) enum — a
     *                            parameter of that type, or a `#[CollectionOf]` naming one: the
     *                            declaration put the value in the enum vocabulary and gave it no
     *                            data form, and both alternatives fail later and worse (the case
     *                            name will not re-hydrate; the instance detonates inside
     *                            `json_encode()` with no path). Also on a variadic or
     *                            non-promoted constructor parameter, which cannot be read back.
     */
    public function extract(object $dto): array
    {
        return $this->extractAt($dto, '');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws HydrationException
     */
    private function extractAt(object $dto, string $prefix): array
    {
        $current = $this->readBack(
            $dto,
            'export',
            'toArray() reads the current values back through the constructor parameters',
        );

        // readBack() either returned a value for every parameter or threw, so every name is
        // present here — no existence guard, or it would be dead code wearing a decision.
        $meta = $this->cache->for($dto::class);
        $exported = [];
        foreach ($meta->parameters as $parameter) {
            $exported[$parameter->name] = $this->extractValue(
                $current[$parameter->name],
                $parameter,
                self::join($prefix, $parameter->name),
            );
        }

        return $exported;
    }

    /**
     * Convert one property value to its exported form — the inverse of {@see self::coerce()},
     * branch for branch (ADR-0086 §1). The declaration decides, never the value's runtime type:
     * a backed enum sitting in a `mixed` parameter is passed through as the instance hydration
     * would accept there, because exporting its backing value would re-hydrate as a plain
     * scalar and silently break the round trip.
     *
     * @throws HydrationException
     */
    private function extractValue(mixed $value, ParameterMetadata $parameter, string $path): mixed
    {
        $type = $parameter->type;

        if ($value === null || $type === null || $type === 'mixed' || $parameter->isBuiltin) {
            return $value;
        }

        if ($type === Collection::class && $value instanceof Collection) {
            return $this->extractCollection($value, $parameter, $path);
        }

        if (\is_a($type, DataTransferObject::class, true) && $value instanceof DataTransferObject) {
            return $this->extractAt($value, $path);
        }

        if (\is_a($type, BackedEnum::class, true) && $value instanceof BackedEnum) {
            return $value->value;
        }

        // Declaration-driven, like every branch here: a parameter DECLARED as a pure enum is
        // refused (the declaration put the value inside the enum vocabulary and gave it no data
        // form), while a pure-enum instance sitting in a mixed/untyped/opaque position has
        // already passed through above, as-is — the same as-is that makes its round trip exact.
        if (\is_a($type, UnitEnum::class, true)) {
            throw self::pureEnumRefusal($type, $path);
        }

        return $value;
    }

    /**
     * The exported form of a `Collection` parameter — the inverse of
     * {@see self::coerceCollection()}: with a `#[CollectionOf]` DTO type the elements become
     * arrays, with a backed-enum type they become backing values (the shape §2's widening
     * re-hydrates), and with no attribute they pass through untouched, exactly as hydration
     * passed them in.
     *
     * @param Collection<mixed> $value
     *
     * @return list<mixed>
     *
     * @throws HydrationException
     */
    private function extractCollection(Collection $value, ParameterMetadata $parameter, string $path): array
    {
        $of = $parameter->attribute(CollectionOf::class);
        $isDto = $of !== null && \is_a($of->type, DataTransferObject::class, true);
        $isBackedEnum = $of !== null && \is_a($of->type, BackedEnum::class, true);
        // Declaration-driven here too: only an attribute NAMING a pure enum triggers the
        // refusal — per element, so an empty collection still exports as [] and round-trips. An
        // attribute-less collection's elements pass through as-is, enums included, exactly as
        // hydration passed them in.
        $pureEnumType = $of !== null && !$isBackedEnum && \is_a($of->type, UnitEnum::class, true)
            ? $of->type
            : null;

        $exported = [];
        $index = 0;
        foreach ($value as $element) {
            $elementPath = self::join($path, (string) $index);

            if ($isDto && $element instanceof DataTransferObject) {
                $exported[] = $this->extractAt($element, $elementPath);
            } elseif ($isBackedEnum && $element instanceof BackedEnum) {
                $exported[] = $element->value;
            } elseif ($pureEnumType !== null) {
                throw self::pureEnumRefusal($pureEnumType, $elementPath);
            } else {
                $exported[] = $element;
            }

            $index++;
        }

        return $exported;
    }

    /**
     * @param class-string $enum
     */
    private static function pureEnumRefusal(string $enum, string $path): HydrationException
    {
        return new HydrationException(\sprintf(
            'Cannot export %s: it is a pure (non-backed) enum, which has no backing value to '
            . 'represent it as data. Back the enum, or keep it out of exported DTOs.',
            $enum,
        ), $path);
    }

    /**
     * Every constructor parameter's current value, read back from the property of the same name
     * — the walk {@see self::withChanges()} and {@see self::extractAt()} share, each supplying
     * its own wording for the two shapes that cannot be read back (ADR-0086 §4).
     *
     * A parameter with a declared default whose property does not exist is impossible here (a
     * promoted parameter always has its property), so absence is always a refusal, never a skip
     * — except for defaults handled by the callers themselves.
     *
     * @return array<string, mixed>
     *
     * @throws HydrationException
     */
    private function readBack(object $source, string $activity, string $mechanism): array
    {
        $class = $source::class;
        $meta = $this->cache->for($class);

        $current = [];
        foreach ($meta->parameters as $parameter) {
            if ($parameter->isVariadic) {
                // hydrateAt() refuses these anyway; stopping here keeps the message about the
                // caller's operation rather than about a payload the caller never wrote.
                throw new HydrationException(\sprintf(
                    'Cannot %s %s: parameter "%s" is variadic, so the current '
                    . 'value cannot be read back as a single argument.',
                    $activity,
                    $class,
                    $parameter->name,
                ), $parameter->name);
            }

            if (!\property_exists($source, $parameter->name)) {
                throw new HydrationException(\sprintf(
                    'Cannot %s %s: constructor parameter "%s" has no property of '
                    . 'the same name to read the current value from. %s, '
                    . 'so every parameter must be recoverable — which promoted '
                    . 'properties always are.',
                    $activity,
                    $class,
                    $parameter->name,
                    $mechanism,
                ), $parameter->name);
            }

            // Reflection rather than `$source->{$name}`: a promoted property may be private, and
            // this class is not in its scope. Since PHP 8.1 `getValue()` needs no
            // `setAccessible()`. It costs a reflection lookup per property, which is acceptable
            // here — neither withers nor export is the path NFR-01 measures.
            $current[$parameter->name] = (new ReflectionProperty($class, $parameter->name))->getValue($source);
        }

        return $current;
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
        // The compiled fast path (ADR-0013). Only the all-scalar shape NFR-01 measures is
        // eligible; everything else returns `false` here and falls through to the interpreter
        // below, which remains the only implementation for nested DTOs, collections, enums,
        // unions, variadics and defaults. `HydrationParityTest` holds the two to the same
        // observable behavior.
        $fast = $this->compiled[$class] ??= $this->compiler->compile($this->cache->for($class)) ?? false;
        if ($fast !== false) {
            /** @var T */
            return $fast($data, $prefix, $lenient);
        }

        $meta = $this->cache->for($class);

        if (!$meta->isInstantiable) {
            throw new HydrationException(
                \sprintf('Cannot hydrate %s: the class is not instantiable.', $class),
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
                if (\array_key_exists($parameter->name, $data)) {
                    throw new HydrationException(\sprintf(
                        'Cannot hydrate %s: parameter "%s" is variadic, which a keyed payload '
                        . 'cannot express.',
                        $class,
                        $parameter->name,
                    ), $path);
                }

                continue;
            }

            if (!\array_key_exists($parameter->name, $data)) {
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
        foreach (\array_keys($data) as $key) {
            $name = (string) $key;
            if (!\in_array($name, $declared, true)) {
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

        if ($type === Collection::class) {
            return $this->coerceCollection($value, $parameter, $lenient, $path);
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
            if (!\class_exists($type) && !\interface_exists($type)) {
                throw new HydrationException(\sprintf(
                    'Cannot hydrate: parameter type "%s" does not resolve to a loadable class '
                    . 'or interface.',
                    $type,
                ), $path);
            }

            return $this->coerceObject($value, $type, $lenient, $path);
        }

        if (!self::satisfiesBuiltin($value, $type)) {
            throw TypeMismatchException::at($path, $type, \get_debug_type($value));
        }

        return $value;
    }

    /**
     * Build a {@see Collection} for a `Collection`-typed parameter.
     *
     * The element type comes from the parameter's `#[CollectionOf]` attribute, because PHP has no
     * runtime generics and the docblock `Collection<Foo>` yields a token that only a real parser
     * could resolve through the file's `use` statements and aliases (ADR-0010).
     *
     * **Without the attribute the elements are passed through untouched**, which is what a
     * `Collection<string>` wants and is the honest thing to do when the element type is genuinely
     * unknown: guessing that an array of arrays means an array of DTOs would be inventing a
     * mapping the declaration never expressed.
     *
     * The return is `Collection<mixed>`: the element type is only known at run time, from the
     * attribute, so there is nothing for the static type to be parameterised by here. The
     * caller's own `@param Collection<Foo>` docblock is what PHPStan checks against — which is
     * exactly the division of labour ADR-0010 describes.
     *
     * @return Collection<mixed>
     *
     * @throws HydrationException
     */
    private function coerceCollection(mixed $value, ParameterMetadata $parameter, bool $lenient, string $path): Collection
    {
        if ($value instanceof Collection) {
            return $value;
        }

        if (!\is_iterable($value)) {
            throw TypeMismatchException::at($path, Collection::class, \get_debug_type($value));
        }

        $of = $parameter->attribute(CollectionOf::class);
        if ($of === null) {
            return new Collection($value);
        }

        $items = [];
        $index = 0;
        foreach ($value as $element) {
            $elementPath = self::join($path, (string) $index);

            if ($element instanceof $of->type) {
                $items[] = $element;
            } elseif (\is_a($of->type, DataTransferObject::class, true) && \is_array($element)) {
                /** @var array<string, mixed> $element */
                $items[] = $this->hydrateAt($of->type, $element, $lenient, $elementPath);
            } elseif (\is_a($of->type, BackedEnum::class, true) && (\is_int($element) || \is_string($element))) {
                // The element-level mirror of coerceObject()'s enum branch (ADR-0086 §2): a
                // backing value at a top-level enum parameter hydrated, the same value inside a
                // #[CollectionOf] enum collection did not — and export producing lists of
                // backing values (FR-51) makes that asymmetry a broken round trip rather than a
                // curiosity. Additive: strictly more inputs accepted, none re-interpreted.
                $items[] = $this->coerceBackedEnum($element, $of->type, $elementPath);
            } else {
                throw TypeMismatchException::at($elementPath, $of->type, \get_debug_type($element));
            }

            $index++;
        }

        // Guarded with the declared element type: the attribute said what these are, so the
        // collection carries the check rather than trusting that this loop got it right.
        return Collection::of($of->type, $items);
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
        if (\is_a($type, DataTransferObject::class, true) && \is_array($value)) {
            /** @var array<string, mixed> $value */
            return $this->hydrateAt($type, $value, $lenient, $path);
        }

        if (\is_a($type, BackedEnum::class, true) && (\is_int($value) || \is_string($value))) {
            return $this->coerceBackedEnum($value, $type, $path);
        }

        throw TypeMismatchException::at($path, $type, \get_debug_type($value));
    }

    /**
     * Resolve a backed enum from its scalar backing value.
     *
     * `UnitEnum::cases()` has no scalar to key from, so a pure (non-backed) enum stays
     * instance-only — this branch fires only for `BackedEnum`, which is exactly what
     * {@see coerceObject()} already checked before calling here.
     *
     * `tryFrom()` rather than `from()`: `from()` throws `\ValueError`, which is not part of
     * ADR-0004's family and would let a bare error escape a hydration call. `tryFrom()` returns
     * `null` on no match, converted here into the library's own exception with the path.
     *
     * @param class-string $type
     *
     * @throws TypeMismatchException
     */
    private function coerceBackedEnum(int|string $value, string $type, string $path): mixed
    {
        $case = $type::tryFrom($value);
        if ($case === null) {
            throw TypeMismatchException::at(
                $path,
                \sprintf('%s (one of: %s)', $type, self::backingValuesOf($type)),
                \get_debug_type($value) . ' ' . \var_export($value, true),
            );
        }

        return $case;
    }

    /**
     * @param class-string $type a `BackedEnum`
     */
    private static function backingValuesOf(string $type): string
    {
        /** @var list<BackedEnum> $cases */
        $cases = $type::cases();

        return \implode(', ', \array_map(
            static fn (BackedEnum $case): string => \var_export($case->value, true),
            $cases,
        ));
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
            'int' => \is_int($value),
            'float' => \is_float($value) || \is_int($value),
            'string' => \is_string($value),
            'bool' => \is_bool($value),
            'array' => \is_array($value),
            'iterable' => \is_iterable($value),
            'object' => \is_object($value),
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
                \sprintf('Cannot hydrate %s: %s', $class, $e->getMessage()),
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
