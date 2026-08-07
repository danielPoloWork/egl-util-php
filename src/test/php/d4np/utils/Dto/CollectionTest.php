<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto;

use D4np\Utils\Dto\Collection;
use D4np\Utils\Support\TypeMismatchException;
use D4np\Utils\Tests\Dto\Fixture\AddressDto;
use D4np\Utils\Tests\Dto\Fixture\UserDto;
use PHPUnit\Framework\TestCase;

/**
 * `Collection` — spec FR-03, ADR-0010.
 *
 * The property under test is that it is a *value*: immutable, and every operation returning a
 * new instance. Genericity itself is not testable at run time — PHP has none — which is the
 * point of the optional `instanceof` guard, and of saying so rather than implying otherwise.
 */
final class CollectionTest extends TestCase
{
    // --------------------------------------------------------------- basics

    public function testAnEmptyCollection(): void
    {
        $c = new Collection();

        self::assertTrue($c->isEmpty());
        self::assertCount(0, $c);
        self::assertSame([], $c->toArray());
        self::assertNull($c->first());
    }

    public function testKeysAreDiscardedSoTheResultIsAList(): void
    {
        // A collection is a sequence, not a map: string or gapped keys would make `first()`,
        // iteration order and `toArray()` ambiguous.
        $c = new Collection(['a' => 1, 'z' => 2]);

        self::assertSame([1, 2], $c->toArray());
        self::assertSame(1, $c->first());
    }

    public function testItAcceptsAnyIterableNotJustAnArray(): void
    {
        $generator = (static function (): \Generator {
            yield 1;
            yield 2;
        })();

        self::assertSame([1, 2], (new Collection($generator))->toArray());
    }

    public function testItIsIterableAndCountable(): void
    {
        $c = new Collection([1, 2, 3]);

        self::assertCount(3, $c);
        self::assertSame([1, 2, 3], \iterator_to_array($c));
    }

    // ---------------------------------------------------------- functional

    public function testMapProducesANewCollectionAndLeavesTheOriginalUntouched(): void
    {
        $c = new Collection([1, 2, 3]);

        $doubled = $c->map(static fn (int $n): int => $n * 2);

        self::assertSame([2, 4, 6], $doubled->toArray());
        self::assertSame([1, 2, 3], $c->toArray(), 'the receiver is unchanged');
        self::assertNotSame($c, $doubled);
    }

    public function testFilterKeepsOnlyWhatThePredicateAcceptsAndReindexes(): void
    {
        $c = new Collection([1, 2, 3, 4]);

        $even = $c->filter(static fn (int $n): bool => $n % 2 === 0);

        // Re-indexed: array_filter preserves keys, which would leave gaps and break `first()`.
        self::assertSame([2, 4], $even->toArray());
        self::assertSame(2, $even->first());
    }

    public function testReduceFoldsToASingleValue(): void
    {
        $c = new Collection([1, 2, 3, 4]);

        self::assertSame(10, $c->reduce(static fn (int $acc, int $n): int => $acc + $n, 0));
    }

    public function testReduceOnAnEmptyCollectionReturnsTheInitialValue(): void
    {
        // Why the initial value is required rather than defaulting to null: this is the case
        // that would otherwise hand the caller a type the callback never produces.
        self::assertSame(0, (new Collection())->reduce(static fn (int $a, int $b): int => $a + $b, 0));
    }

    public function testOperationsChain(): void
    {
        $result = (new Collection([1, 2, 3, 4, 5]))
            ->filter(static fn (int $n): bool => $n % 2 === 1)
            ->map(static fn (int $n): int => $n * 10)
            ->reduce(static fn (int $acc, int $n): int => $acc + $n, 0);

        self::assertSame(90, $result);
    }

    // ------------------------------------------------------- the type guard

    public function testTheGuardAcceptsMatchingElements(): void
    {
        $a = AddressDto::fromArray(['street' => 'S', 'postcode' => 'P']);

        $c = Collection::of(AddressDto::class, [$a]);

        self::assertSame(AddressDto::class, $c->itemType());
        self::assertSame([$a], $c->toArray());
    }

    public function testTheGuardRejectsAMismatchAndNamesTheIndex(): void
    {
        $address = AddressDto::fromArray(['street' => 'S', 'postcode' => 'P']);
        $user = UserDto::fromArray(['email' => 'a@b.c', 'name' => 'Ada']);

        try {
            Collection::of(AddressDto::class, [$address, $user]);
            self::fail('expected a TypeMismatchException');
        } catch (TypeMismatchException $e) {
            self::assertSame('1', $e->path(), 'the failing index is named');
            self::assertStringContainsString(AddressDto::class, $e->getMessage());
        }
    }

    public function testAnUnguardedCollectionAcceptsAnythingWhichIsThePointOfTheFlagBeingOptional(): void
    {
        // Genericity is static-analysis-level only. Without the opt-in guard, PHP does not and
        // cannot check — asserted so the honesty of that claim is mechanical rather than prose.
        $mixed = new Collection([1, 'two', new \stdClass()]);

        self::assertCount(3, $mixed);
        self::assertNull($mixed->itemType());
    }

    public function testFilterCarriesTheGuardAcross(): void
    {
        $a = AddressDto::fromArray(['street' => 'S', 'postcode' => 'AB1']);
        $b = AddressDto::fromArray(['street' => 'T', 'postcode' => 'XY9']);

        $filtered = Collection::of(AddressDto::class, [$a, $b])
            ->filter(static fn (AddressDto $x): bool => $x->postcode === 'AB1');

        self::assertSame(AddressDto::class, $filtered->itemType(), 'the element type is unchanged, so the guard survives');
        self::assertSame([$a], $filtered->toArray());
    }

    public function testMapDropsTheGuardBecauseTheElementTypeChanges(): void
    {
        // Carrying the old guard across would reject exactly the transformations map() exists
        // for — the callback may return anything at all.
        $a = AddressDto::fromArray(['street' => 'S', 'postcode' => 'AB1']);

        $postcodes = Collection::of(AddressDto::class, [$a])
            ->map(static fn (AddressDto $x): string => $x->postcode);

        self::assertNull($postcodes->itemType());
        self::assertSame(['AB1'], $postcodes->toArray());
    }
}
