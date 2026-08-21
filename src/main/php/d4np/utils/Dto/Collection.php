<?php

declare(strict_types=1);

namespace D4np\Utils\Dto;

use ArrayIterator;
use Countable;
use D4np\Utils\Support\TypeMismatchException;
use IteratorAggregate;
use Traversable;

/**
 * An immutable, functional wrapper over a list (spec FR-03, ADR-0010).
 *
 * ```php
 * // @var Collection<OrderDto> $orders
 * $totals = $orders->map(static fn (OrderDto $o): float => $o->total)
 *                  ->filter(static fn (float $t): bool => $t > 100.0);
 * ```
 *
 * **Genericity here is static-analysis-level only, and that is stated rather than implied.**
 * PHP has no runtime generics: `Collection<OrderDto>` exists in `@template`/`@var` annotations
 * and is enforced by PHPStan at max level, not by the engine. A `Collection<OrderDto>` handed a
 * `UserDto` at run time is a type error PHPStan catches and PHP does not.
 *
 * For the cases where that is not enough — a payload from outside the type system, say — the
 * constructor takes an **optional** `$itemType`, and every element is then checked with
 * `instanceof` at construction. It is opt-in because it costs a pass over the items, and because
 * inside a codebase that runs PHPStan it is a second belt on the same trousers.
 *
 * Every operation returns a new instance; nothing here mutates.
 *
 * `T` is declared **covariant**, and that is a statement about the design rather than a way to
 * quiet the analyser: covariance is only sound for a container that cannot be written to, and
 * this one cannot — there is no `add()`, no `set()`, no mutation of any kind. A
 * `Collection<AddressDto>` is therefore usable wherever a `Collection<object>` is wanted, which
 * an appendable collection could never safely allow.
 *
 * @template-covariant T
 *
 * @implements IteratorAggregate<int, T>
 */
final class Collection implements Countable, IteratorAggregate
{
    /** @var list<T> */
    private readonly array $items;

    /**
     * @param iterable<T>          $items
     * @param class-string<T>|null $itemType when given, every element is checked with
     *                                       `instanceof` and a mismatch raises immediately,
     *                                       naming the index it failed at
     *
     * @throws TypeMismatchException
     */
    public function __construct(
        iterable $items = [],
        private readonly ?string $itemType = null,
    ) {
        $list = \is_array($items) ? \array_values($items) : \iterator_to_array($items, false);

        if ($itemType !== null) {
            foreach ($list as $index => $item) {
                if (!$item instanceof $itemType) {
                    throw TypeMismatchException::at((string) $index, $itemType, \get_debug_type($item));
                }
            }
        }

        $this->items = $list;
    }

    /**
     * A collection of `$items`, each checked against `$itemType`.
     *
     * The named constructor exists because `new Collection($items, Foo::class)` reads as though
     * the second argument were data; `Collection::of(Foo::class, $items)` reads as what it is.
     *
     * @template TItem
     *
     * @param class-string<TItem> $itemType
     * @param iterable<TItem>     $items
     *
     * @return Collection<TItem>
     *
     * @throws TypeMismatchException
     */
    public static function of(string $itemType, iterable $items = []): self
    {
        return new self($items, $itemType);
    }

    /**
     * A new collection with `$callback` applied to every element.
     *
     * The result is **unguarded** even when this collection is: `$callback` may return a
     * different type entirely, and carrying the old `instanceof` check across would reject
     * exactly the transformations `map()` exists for.
     *
     * @template TOut
     *
     * @param callable(T): TOut $callback
     *
     * @return Collection<TOut>
     */
    public function map(callable $callback): self
    {
        return new self(\array_map($callback, $this->items));
    }

    /**
     * A new collection of the elements `$callback` accepts, keys discarded.
     *
     * The element type is unchanged, so the guard — if any — is carried across.
     *
     * @param callable(T): bool $callback
     *
     * @return Collection<T>
     */
    public function filter(callable $callback): self
    {
        return new self(\array_values(\array_filter($this->items, $callback)), $this->itemType);
    }

    /**
     * Fold the collection into a single value.
     *
     * `$initial` is required rather than defaulting to `null`: a fold with no starting value is
     * undefined on an empty collection, and the usual workaround — returning `null` — hands the
     * caller a type the callback never produces.
     *
     * @template TAcc
     *
     * @param callable(TAcc, T): TAcc $callback
     * @param TAcc                    $initial
     *
     * @return TAcc
     */
    public function reduce(callable $callback, mixed $initial): mixed
    {
        return \array_reduce($this->items, $callback, $initial);
    }

    /**
     * The elements, as a plain list.
     *
     * @return list<T>
     */
    public function toArray(): array
    {
        return $this->items;
    }

    /**
     * The first element, or `null` when empty.
     *
     * @return T|null
     */
    public function first(): mixed
    {
        return $this->items[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return \count($this->items);
    }

    /**
     * The element type this collection checks against, or `null` when unguarded.
     *
     * @return class-string<T>|null
     */
    public function itemType(): ?string
    {
        return $this->itemType;
    }

    /**
     * @return Traversable<int, T>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
