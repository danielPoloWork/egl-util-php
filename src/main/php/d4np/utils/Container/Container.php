<?php

declare(strict_types=1);

namespace D4np\Utils\Container;

use D4np\Utils\Support\ParameterMetadata;
use D4np\Utils\Support\ReflectionCache;
use Psr\Container\ContainerInterface;

/**
 * A deliberately small PSR-11 container (spec FR-04, imported ADR-001, ADR-0028).
 *
 * **The non-goals are the design.** Imported ADR-001 chose to ship this rather than depend on
 * PHP-DI, on the condition that its scope stay stated and small: constructor autowiring through the
 * shared reflection cache, singleton and factory definitions, `ServiceProvider` registration — and
 * **no compilation, no attribute configuration, no lazy proxies, and no circular-dependency
 * resolution**. Where a mature container would add a feature, this one fails loudly and names
 * ADR-001, whose answer to such a request is "adopt a mature container instead".
 *
 * The escape hatch is structural rather than promised: everything in this library that consumes a
 * container consumes `Psr\Container\ContainerInterface`, so replacing this class with PHP-DI or
 * Symfony DI is a wiring change and nothing else.
 *
 * **Resolution is memoised by default.** `get()` on the same identifier returns the same instance,
 * whether the entry was registered or autowired, which is what makes NFR-02's warm path a single
 * array read. {@see factory()} is the opt-out for entries that must be built fresh each time —
 * stated as an opt-out because a container that silently rebuilt a graph on every `get()` would
 * turn one shared connection into hundreds.
 *
 * @see ServiceProvider for grouping registrations
 */
final class Container implements ContainerInterface
{
    /**
     * Resolved values, including autowired ones. The hot path of NFR-02.
     *
     * @var array<string, mixed>
     */
    private array $instances = [];

    /** @var array<string, callable(self): mixed> */
    private array $factories = [];

    /**
     * Identifiers whose factory result is memoised. A factory absent from here is rebuilt per call.
     *
     * @var array<string, true>
     */
    private array $shared = [];

    /** @var array<string, string> */
    private array $aliases = [];

    /**
     * The identifiers currently being constructed, in order — the dependency path a circular
     * reference is reported with.
     *
     * @var list<string>
     */
    private array $building = [];

    /** @var array<string, true> */
    private array $buildingIndex = [];

    public function __construct(private readonly ReflectionCache $cache = new ReflectionCache())
    {
    }

    /**
     * Store an already-built value under `$id`.
     *
     * Takes `mixed` rather than `object` because a container is also where configuration lands —
     * a DSN, a feature flag, a timeout. PSR-11 says nothing narrower.
     */
    public function instance(string $id, mixed $value): self
    {
        $this->instances[$id] = $value;

        return $this;
    }

    /**
     * Register `$id` as built once and reused.
     *
     * With no factory this marks a class to be autowired and shared, which is already the default —
     * it is accepted so that a `ServiceProvider` can state the intent explicitly rather than relying
     * on the reader knowing the default.
     *
     * @param (callable(self): mixed)|null $factory
     */
    public function singleton(string $id, ?callable $factory = null): self
    {
        if ($factory !== null) {
            $this->factories[$id] = $factory;
        }

        $this->shared[$id] = true;
        unset($this->instances[$id]);

        return $this;
    }

    /**
     * Register `$id` as built fresh on every `get()`.
     *
     * @param callable(self): mixed $factory
     */
    public function factory(string $id, callable $factory): self
    {
        $this->factories[$id] = $factory;
        unset($this->shared[$id], $this->instances[$id]);

        return $this;
    }

    /**
     * Point an identifier — typically an interface — at a concrete class.
     *
     * This is the only way an interface-typed constructor parameter can be autowired, since nothing
     * about an interface says which implementation was meant. Without a binding the container
     * refuses rather than guessing.
     */
    public function bind(string $abstract, string $concrete): self
    {
        $this->aliases[$abstract] = $concrete;
        unset($this->instances[$abstract]);

        return $this;
    }

    public function register(ServiceProvider ...$providers): self
    {
        foreach ($providers as $provider) {
            $provider->register($this);
        }

        return $this;
    }

    /**
     * **The return type is conditional on the identifier, which is a deliberate addition to
     * PSR-11.** The interface declares `get()` as returning `mixed`, so under PHPStan at max every
     * consumer would have to narrow at every call site — `$container->get(Mailer::class)` would be
     * an `object` at best and this library holds itself to that analysis level, so it would be
     * exporting the noise. When `$id` is a class name the return is that class; for any other
     * identifier it stays `mixed`, because a string key genuinely says nothing about its value.
     * Verified against PHPStan max before being relied on.
     *
     * @template T of object
     *
     * @param class-string<T>|string $id
     *
     * @return ($id is class-string<T> ? T : mixed)
     *
     * @throws NotFoundException  if nothing is registered under `$id` and it is not an autowirable class
     * @throws ContainerException if the entry exists but cannot be built
     */
    public function get(string $id): mixed
    {
        // NFR-02's warm path, kept to one isset and one array read. `isset()` is false for a
        // stored null, which resolve() handles; paying array_key_exists() here to cover that
        // would tax every hit for a rare entry.
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        return $this->resolve($id);
    }

    /**
     * Whether `get($id)` would find something.
     *
     * True for a registered entry **and** for any instantiable class, because those are exactly the
     * identifiers `get()` will return a value for. Reporting `false` for a class this container
     * would happily autowire would make `has()` a statement about the registration table rather
     * than about the container's behaviour, which is not what PSR-11 asks of it.
     */
    public function has(string $id): bool
    {
        if (isset($this->factories[$id]) || isset($this->aliases[$id]) || \array_key_exists($id, $this->instances)) {
            return true;
        }

        return \class_exists($id) && $this->cache->for($id)->isInstantiable;
    }

    private function resolve(string $id): mixed
    {
        if (\array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        $target = $this->aliases[$id] ?? $id;

        if (isset($this->buildingIndex[$id])) {
            throw new CircularDependencyException(\sprintf(
                'Circular dependency detected while resolving "%s": %s. This container does not '
                . 'resolve cycles by design (imported ADR-001); break the cycle, or inject a '
                . 'factory so one side is built on demand.',
                $id,
                \implode(' -> ', [...$this->building, $id]),
            ));
        }

        $this->building[] = $id;
        $this->buildingIndex[$id] = true;

        try {
            $value = isset($this->factories[$id])
                ? ($this->factories[$id])($this)
                : $this->build($target, $id);
        } finally {
            \array_pop($this->building);
            unset($this->buildingIndex[$id]);
        }

        // Autowired entries are shared unless explicitly registered as a factory.
        if (!isset($this->factories[$id]) || isset($this->shared[$id])) {
            $this->instances[$id] = $value;
        }

        return $value;
    }

    /**
     * Construct `$class` by autowiring its constructor.
     *
     * `$id` is carried separately so a failure names what the *caller* asked for, not the concrete
     * class a `bind()` happened to point at.
     */
    private function build(string $class, string $id): mixed
    {
        // Interfaces and traits must reach the cache, not be rejected here: `class_exists()` is
        // false for both — verified — and an unbound interface is the single most likely thing to
        // arrive at this method. Refusing it as "no such class" would send the reader hunting for a
        // typo instead of telling them to bind() it. This mirrors ReflectionCache's own three-way
        // check, and is the reason that check exists.
        if (!\class_exists($class) && !\interface_exists($class) && !\trait_exists($class)) {
            throw new NotFoundException(\sprintf(
                'No entry is registered for "%s", and it is not a loadable class this container '
                . 'could autowire.',
                $id,
            ));
        }

        $metadata = $this->cache->for($class);

        if (!$metadata->isInstantiable) {
            throw new ContainerException(\sprintf(
                '"%s" cannot be instantiated (it is an interface, an abstract class, or has a '
                . 'non-public constructor). Bind it to a concrete class with bind(), or register a '
                . 'factory for it.',
                $class,
            ));
        }

        $arguments = [];
        foreach ($metadata->parameters as $parameter) {
            // A variadic tail is legitimately empty: there is nothing for the container to
            // enumerate, and inventing a value would be guessing.
            if ($parameter->isVariadic) {
                continue;
            }

            $arguments[] = $this->argumentFor($parameter, $class);
        }

        return new $class(...$arguments);
    }

    private function argumentFor(ParameterMetadata $parameter, string $class): mixed
    {
        // ADR-0006 records union and intersection types as a null `type` with the declaration
        // preserved verbatim, precisely so this refusal can name what it saw. Imported ADR-001
        // requires failing here rather than picking an arm.
        if ($parameter->type === null && $parameter->declaredType !== null) {
            return $this->fallbackOrFail($parameter, \sprintf(
                'Cannot autowire $%s of %s: its type "%s" is a union or intersection, and choosing '
                . 'an arm would be a guess. Register a factory for %s instead.',
                $parameter->name,
                $class,
                $parameter->declaredType,
                $class,
            ));
        }

        if ($parameter->type === null) {
            return $this->fallbackOrFail($parameter, \sprintf(
                'Cannot autowire $%s of %s: it has no type declaration, so there is nothing to '
                . 'resolve it by.',
                $parameter->name,
                $class,
            ));
        }

        if ($parameter->isBuiltin) {
            return $this->fallbackOrFail($parameter, \sprintf(
                'Cannot autowire $%s of %s: "%s" is a built-in type with no default. Register a '
                . 'factory for %s, or give the parameter a default.',
                $parameter->name,
                $class,
                $parameter->type,
                $class,
            ));
        }

        try {
            return $this->get($parameter->type);
        } catch (CircularDependencyException $e) {
            // A cycle is a structural error, not the kind of absence a default answers. Falling
            // back here would hide it and hand back a half-built graph.
            throw $e;
        } catch (ContainerException $e) {
            // A default is the author's own answer to "what if this is absent", so it wins over
            // failing.
            if ($parameter->hasDefault) {
                return $parameter->default;
            }

            throw $e;
        }
    }

    /**
     * @throws ContainerException
     */
    private function fallbackOrFail(ParameterMetadata $parameter, string $message): mixed
    {
        if ($parameter->hasDefault) {
            return $parameter->default;
        }

        throw new ContainerException($message);
    }

    /**
     * The shared metadata cache, exposed so a consumer can hand the same one to the DTO hydrator.
     *
     * Imported ADR-001 commits to **one** cache across the container and the hydrator; this is how
     * that commitment is honoured when the container built its own.
     */
    public function reflectionCache(): ReflectionCache
    {
        return $this->cache;
    }
}
