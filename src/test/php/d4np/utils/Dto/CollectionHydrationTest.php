<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto;

use D4np\Utils\Dto\Collection;
use D4np\Utils\Support\TypeMismatchException;
use D4np\Utils\Tests\Dto\Fixture\AddressDto;
use D4np\Utils\Tests\Dto\Fixture\BasketDto;
use D4np\Utils\Tests\Dto\Fixture\Status;
use D4np\Utils\Tests\Dto\Fixture\StatusesDto;
use D4np\Utils\Tests\Dto\Fixture\TagsDto;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Hydrating `Collection` properties — the gap item 3.1 named in its own roadmap entry and
 * deferred to here, and part of **T-01**'s "collections" column (spec §7).
 *
 * The element type comes from `#[CollectionOf]` rather than the docblock, because a docblock
 * yields a *token* that only a real PHP parser could resolve through the file's `use` statements
 * and aliases, while an attribute argument arrives already resolved (ADR-0010).
 */
#[Group('T-01')]
final class CollectionHydrationTest extends TestCase
{
    public function testElementsAreHydratedIntoTheDeclaredType(): void
    {
        $basket = BasketDto::fromArray([
            'label' => 'route',
            'stops' => [
                ['street' => '1 Main St', 'postcode' => 'AB1 2CD'],
                ['street' => '2 Other Rd', 'postcode' => 'XY9 8ZW'],
            ],
        ]);

        self::assertInstanceOf(Collection::class, $basket->stops);
        self::assertCount(2, $basket->stops);

        $first = $basket->stops->first();
        self::assertInstanceOf(AddressDto::class, $first, 'the raw arrays became DTOs');
        self::assertSame('AB1 2CD', $first->postcode);
    }

    public function testTheHydratedCollectionCarriesTheDeclaredGuard(): void
    {
        // The attribute said what these are, so the collection checks rather than trusting the
        // hydration loop got it right.
        $basket = BasketDto::fromArray(['label' => 'route', 'stops' => []]);

        self::assertSame(AddressDto::class, $basket->stops->itemType());
    }

    public function testAnEmptyCollectionHydrates(): void
    {
        $basket = BasketDto::fromArray(['label' => 'route', 'stops' => []]);

        self::assertTrue($basket->stops->isEmpty());
    }

    public function testAlreadyBuiltElementsArePassedThrough(): void
    {
        $address = AddressDto::fromArray(['street' => 'S', 'postcode' => 'P']);

        $basket = BasketDto::fromArray(['label' => 'route', 'stops' => [$address]]);

        self::assertSame($address, $basket->stops->first());
    }

    public function testAnAlreadyBuiltCollectionIsPassedThrough(): void
    {
        $stops = Collection::of(AddressDto::class, [AddressDto::fromArray(['street' => 'S', 'postcode' => 'P'])]);

        $basket = BasketDto::fromArray(['label' => 'route', 'stops' => $stops]);

        self::assertSame($stops, $basket->stops);
    }

    /**
     * The reason paths compose at all: in a collection of nested objects, the index is the only
     * thing that says *which* element was wrong.
     */
    public function testAFailingElementNamesItsIndexInThePath(): void
    {
        try {
            BasketDto::fromArray([
                'label' => 'route',
                'stops' => [
                    ['street' => '1 Main St', 'postcode' => 'AB1 2CD'],
                    ['street' => '2 Other Rd', 'postcode' => 12345],
                ],
            ]);
            self::fail('expected a TypeMismatchException');
        } catch (TypeMismatchException $e) {
            self::assertSame('stops.1.postcode', $e->path());
        }
    }

    public function testAnElementOfTheWrongTypeEntirelyIsRejectedWithItsIndex(): void
    {
        try {
            BasketDto::fromArray(['label' => 'route', 'stops' => ['not an address']]);
            self::fail('expected a TypeMismatchException');
        } catch (TypeMismatchException $e) {
            self::assertSame('stops.0', $e->path());
        }
    }

    public function testANonIterableValueForACollectionIsRejected(): void
    {
        try {
            BasketDto::fromArray(['label' => 'route', 'stops' => 'nope']);
            self::fail('expected a TypeMismatchException');
        } catch (TypeMismatchException $e) {
            self::assertSame('stops', $e->path());
        }
    }

    /**
     * Without the attribute the element type is genuinely unknown, so elements pass through
     * untouched — which is what a `Collection<string>` wants. Guessing that an array of arrays
     * means an array of DTOs would be inventing a mapping the declaration never expressed.
     */
    public function testWithoutTheAttributeElementsPassThroughUntouched(): void
    {
        $dto = TagsDto::fromArray(['tags' => ['php', 'dto', 'utils']]);

        self::assertSame(['php', 'dto', 'utils'], $dto->tags->toArray());
        self::assertNull($dto->tags->itemType(), 'nothing was declared, so nothing is guarded');
    }

    public function testLeniencyPropagatesIntoCollectionElements(): void
    {
        $basket = BasketDto::lenient()->fromArray([
            'label' => 'route',
            'unknownAtRoot' => 1,
            'stops' => [['street' => 'S', 'postcode' => 'P', 'unknownInElement' => 2]],
        ]);

        self::assertSame('P', $basket->stops->first()?->postcode);
    }

    public function testStrictModeRejectsAnUnknownKeyInsideAnElement(): void
    {
        try {
            BasketDto::fromArray([
                'label' => 'route',
                'stops' => [['street' => 'S', 'postcode' => 'P', 'county' => 'X']],
            ]);
            self::fail('expected a hydration failure');
        } catch (\D4np\Utils\Support\UnknownKeyException $e) {
            self::assertSame('stops.0.county', $e->path());
        }
    }

    /**
     * ADR-0086 §2: a backing value under a backed-enum `#[CollectionOf]` resolves exactly as it
     * would at a top-level enum parameter — the element-level asymmetry FR-51's export turned
     * from a curiosity into a broken round trip.
     */
    public function testBackedEnumElementsHydrateFromTheirBackingValues(): void
    {
        $dto = StatusesDto::fromArray(['statuses' => ['active', 'inactive']]);

        self::assertSame([Status::Active, Status::Inactive], $dto->statuses->toArray());
    }

    public function testBackedEnumElementsMayMixInstancesAndValues(): void
    {
        $dto = StatusesDto::fromArray(['statuses' => [Status::Active, 'inactive']]);

        self::assertSame([Status::Active, Status::Inactive], $dto->statuses->toArray());
    }

    public function testAnUnknownBackingValueIsRefusedWithItsIndexAndTheAllowedValues(): void
    {
        try {
            StatusesDto::fromArray(['statuses' => ['active', 'paused']]);
            self::fail('expected a hydration failure');
        } catch (TypeMismatchException $e) {
            self::assertSame('statuses.1', $e->path());
            self::assertStringContainsString("'active', 'inactive'", $e->getMessage());
        }
    }

    public function testANonScalarElementUnderAnEnumAttributeIsStillRefused(): void
    {
        try {
            StatusesDto::fromArray(['statuses' => [['not' => 'a value']]]);
            self::fail('expected a hydration failure');
        } catch (TypeMismatchException $e) {
            self::assertSame('statuses.0', $e->path());
        }
    }
}
