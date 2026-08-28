<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Dto;

use D4np\Utils\Support\HydrationException;
use D4np\Utils\Tests\Dto\Fixture\BasketDto;
use D4np\Utils\Tests\Dto\Fixture\Direction;
use D4np\Utils\Tests\Dto\Fixture\DirectionsDto;
use D4np\Utils\Tests\Dto\Fixture\MixedDto;
use D4np\Utils\Tests\Dto\Fixture\NonDtoTypedDto;
use D4np\Utils\Tests\Dto\Fixture\OrderDto;
use D4np\Utils\Tests\Dto\Fixture\Priority;
use D4np\Utils\Tests\Dto\Fixture\ScalarsDto;
use D4np\Utils\Tests\Dto\Fixture\Status;
use D4np\Utils\Tests\Dto\Fixture\StatusesDto;
use D4np\Utils\Tests\Dto\Fixture\TagsDto;
use D4np\Utils\Tests\Dto\Fixture\TicketDto;
use D4np\Utils\Tests\Dto\Fixture\TicketHolderDto;
use D4np\Utils\Tests\Dto\Fixture\UserDto;
use D4np\Utils\Tests\Dto\Fixture\VariadicDto;
use D4np\Utils\Tests\Dto\Fixture\WitherUnreadableDto;
use JsonSerializable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `DataTransferObject::toArray()` / `jsonSerialize()` — spec r32 FR-51 (RFC-0004, roadmap item
 * 15.3, ADR-0086): the exact inverse of hydration, driven by each parameter's declaration.
 */
#[Group('T-01')]
final class DtoToArrayTest extends TestCase
{
    public function testScalarsExportAsThemselves(): void
    {
        $dto = ScalarsDto::fromArray(['i' => 1, 'f' => 1.5, 's' => 'x', 'b' => true, 'a' => ['k' => 'v']]);

        self::assertSame(['i' => 1, 'f' => 1.5, 's' => 'x', 'b' => true, 'a' => ['k' => 'v']], $dto->toArray());
    }

    public function testNestedDtosExportRecursively(): void
    {
        $order = OrderDto::fromArray([
            'reference' => 'R-1',
            'customer' => ['name' => 'Ada', 'address' => ['street' => 'Main 1', 'postcode' => '12345']],
        ]);

        self::assertSame([
            'reference' => 'R-1',
            'customer' => ['name' => 'Ada', 'address' => ['street' => 'Main 1', 'postcode' => '12345']],
        ], $order->toArray());
    }

    public function testACollectionOfDtosExportsAsAListOfArrays(): void
    {
        $basket = BasketDto::fromArray([
            'label' => 'route',
            'stops' => [
                ['street' => 'First 1', 'postcode' => '11111'],
                ['street' => 'Second 2', 'postcode' => '22222'],
            ],
        ]);

        self::assertSame([
            'label' => 'route',
            'stops' => [
                ['street' => 'First 1', 'postcode' => '11111'],
                ['street' => 'Second 2', 'postcode' => '22222'],
            ],
        ], $basket->toArray());
    }

    public function testACollectionOfBackedEnumsExportsAsBackingValues(): void
    {
        $dto = StatusesDto::fromArray(['statuses' => [Status::Active, Status::Inactive]]);

        self::assertSame(['statuses' => ['active', 'inactive']], $dto->toArray());
    }

    public function testAnAttributeLessCollectionExportsItsElementsAsIs(): void
    {
        $dto = TagsDto::fromArray(['tags' => ['a', 'b']]);

        self::assertSame(['tags' => ['a', 'b']], $dto->toArray());
    }

    public function testBackedEnumsExportTheirBackingValueAndDefaultsAreIncluded(): void
    {
        $ticket = TicketDto::fromArray(['title' => 'T', 'status' => 'active']);

        // Priority::Low is the declared default and direction the null default: both are real
        // state of the object, so both appear — an export that omitted them would make two
        // different objects produce the same array.
        self::assertSame(
            ['title' => 'T', 'status' => 'active', 'priority' => 1, 'direction' => null],
            $ticket->toArray(),
        );
    }

    public function testADeclaredPureEnumPositionIsRefusedWithItsPath(): void
    {
        $ticket = new TicketDto('T', Status::Active, Priority::High, Direction::Up);

        try {
            $ticket->toArray();
            self::fail('Expected a HydrationException.');
        } catch (HydrationException $e) {
            self::assertSame('direction', $e->path());
            self::assertStringContainsString(Direction::class, $e->getMessage());
            self::assertStringContainsString('pure (non-backed) enum', $e->getMessage());
        }
    }

    public function testANestedPureEnumRefusalCarriesThePrefixedPath(): void
    {
        $holder = new TicketHolderDto('h', new TicketDto('T', Status::Active, Priority::Low, Direction::Down));

        try {
            $holder->toArray();
            self::fail('Expected a HydrationException.');
        } catch (HydrationException $e) {
            self::assertSame('ticket.direction', $e->path());
        }
    }

    public function testAPureEnumCollectionIsRefusedWithTheElementIndex(): void
    {
        $dto = DirectionsDto::fromArray(['directions' => [Direction::Up]]);

        try {
            $dto->toArray();
            self::fail('Expected a HydrationException.');
        } catch (HydrationException $e) {
            self::assertSame('directions.0', $e->path());
        }
    }

    public function testAnEmptyPureEnumCollectionStillExports(): void
    {
        $dto = DirectionsDto::fromArray(['directions' => []]);

        self::assertSame(['directions' => []], $dto->toArray());
    }

    /**
     * The declaration decides, never the runtime type (ADR-0086 §1): a backed enum sitting in a
     * `mixed` parameter exports as the INSTANCE, because that is what hydration accepts there —
     * its backing value would re-hydrate as a plain string and silently break the round trip.
     */
    public function testABackedEnumInAMixedParameterPassesThroughAsTheInstance(): void
    {
        $dto = MixedDto::fromArray(['anything' => Status::Active]);

        self::assertSame(['anything' => Status::Active], $dto->toArray());
    }

    /** The declaration-driven boundary of §3: a pure enum in an OPAQUE position is not refused. */
    public function testAPureEnumInAMixedParameterPassesThroughAsTheInstance(): void
    {
        $dto = MixedDto::fromArray(['anything' => Direction::Up]);

        self::assertSame(['anything' => Direction::Up], $dto->toArray());
    }

    public function testAnOpaqueClassInstancePassesThroughAsIs(): void
    {
        $at = new \DateTimeImmutable('2026-08-28T12:00:00Z');
        $dto = NonDtoTypedDto::fromArray(['at' => $at]);

        self::assertSame(['at' => $at], $dto->toArray());
    }

    public function testAVariadicConstructorIsRefused(): void
    {
        $dto = VariadicDto::fromArray([]);

        $this->expectException(HydrationException::class);
        $this->expectExceptionMessage('Cannot export');

        $dto->toArray();
    }

    public function testANonPromotedParameterIsRefusedNamingTheExport(): void
    {
        $dto = new WitherUnreadableDto('quiet');

        try {
            $dto->toArray();
            self::fail('Expected a HydrationException.');
        } catch (HydrationException $e) {
            self::assertStringContainsString('Cannot export', $e->getMessage());
            self::assertStringContainsString('toArray() reads the current values back', $e->getMessage());
        }
    }

    public function testEveryDtoIsJsonSerializable(): void
    {
        self::assertInstanceOf(JsonSerializable::class, UserDto::fromArray(['email' => 'a@b.c', 'name' => 'Ada']));
    }

    /** One conversion, two names: json_encode($dto) and json_encode($dto->toArray()) agree. */
    public function testJsonEncodeSerializesExactlyTheToArrayForm(): void
    {
        $basket = BasketDto::fromArray([
            'label' => 'route',
            'stops' => [['street' => 'First 1', 'postcode' => '11111']],
        ]);

        self::assertSame(
            \json_encode($basket->toArray(), JSON_THROW_ON_ERROR),
            \json_encode($basket, JSON_THROW_ON_ERROR),
        );
    }
}
